<?php

namespace App\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Services\AIService;
use App\Services\AutoAssignmentService;
use App\Services\LeadRecoveryService;
use App\Services\LINEService;
use App\Services\MessageAggregationService;
use App\Services\MultipleBubblesService;
use App\Services\OpenRouterService;
use App\Services\RateLimitService;
use App\Services\ResponseHoursService;
use App\Services\SmartAggregation\SmartAggregationAnalyzer;
use App\Services\SmartAggregation\UserTypingStats;
use App\Services\StickerReplyService;
use App\Services\CircuitBreakerService;
use App\Exceptions\CircuitOpenException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessLINEWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     * Uses exponential backoff: 5s, 15s, 45s
     */
    public array $backoff = [5, 15, 45];

    /**
     * Smart aggregation state (used to pass data outside transaction).
     */
    protected ?string $aggregationGroupId = null;
    protected bool $dispatchAggregation = false;
    protected ?int $adaptiveWaitTimeMs = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Bot $bot,
        public array $event
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        LINEService $lineService,
        AIService $aiService,
        RateLimitService $rateLimitService,
        MessageAggregationService $aggregationService,
        ResponseHoursService $responseHoursService,
        CircuitBreakerService $circuitBreaker
    ): void {
        try {
            // Use circuit breaker to protect against DB failures
            $circuitBreaker->execute(
                'database',
                fn () => $this->processEvent($lineService, $aiService, $rateLimitService, $aggregationService, $responseHoursService),
                fn () => $this->sendFallbackMessage($lineService)
            );
        } catch (CircuitOpenException $e) {
            // Circuit is open - send fallback and don't retry
            Log::warning('Circuit breaker open for LINE webhook', [
                'bot_id' => $this->bot->id,
                'service' => $e->getService(),
            ]);
            $this->sendFallbackMessage($lineService);
        } catch (\Exception $e) {
            Log::error('LINE webhook processing failed', [
                'bot_id' => $this->bot->id,
                'event_type' => $this->event['type'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Send fallback message when system is unavailable.
     * This method doesn't depend on database operations.
     */
    protected function sendFallbackMessage(LINEService $lineService): void
    {
        if (! config('bot.send_fallback_on_circuit_open', true)) {
            return;
        }

        $userId = $lineService->extractUserId($this->event);
        if (! $userId) {
            return;
        }

        $fallbackMessage = config('bot.fallback_message', 'ขออภัยครับ ระบบกำลังมีปัญหาชั่วคราว กรุณาลองใหม่ในอีกสักครู่');

        try {
            // Use push instead of reply since we don't know if reply token is still valid
            $lineService->push($this->bot, $userId, [$fallbackMessage]);

            Log::info('Sent fallback message to LINE user', [
                'bot_id' => $this->bot->id,
                'user_id' => $userId,
            ]);
        } catch (\Exception $e) {
            // Don't throw - fallback message is best-effort
            Log::error('Failed to send fallback message', [
                'bot_id' => $this->bot->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process the LINE event.
     */
    protected function processEvent(
        LINEService $lineService,
        AIService $aiService,
        RateLimitService $rateLimitService,
        MessageAggregationService $aggregationService,
        ResponseHoursService $responseHoursService
    ): void {
        // Only process message events
        if (!$lineService->isMessageEvent($this->event)) {
            Log::debug('Ignoring non-message event', [
                'type' => $this->event['type'] ?? 'unknown',
            ]);
            return;
        }

        // Only process text messages for now
        if (!$lineService->isTextMessage($this->event)) {
            $this->handleNonTextMessage($lineService, $responseHoursService);
            return;
        }

        // Extract event data
        $userId = $lineService->extractUserId($this->event);
        $replyToken = $lineService->extractReplyToken($this->event);
        $messageData = $lineService->extractMessage($this->event);

        // Extract LINE webhook event metadata (best practice)
        $webhookEventId = $lineService->extractWebhookEventId($this->event);
        $eventTimestamp = $lineService->extractEventTimestamp($this->event);
        $isRedeliveryEvent = $lineService->isRedelivery($this->event);

        if (!$userId || !$messageData['text']) {
            Log::warning('Invalid LINE message event', [
                'has_user_id' => (bool) $userId,
                'has_text' => (bool) $messageData['text'],
            ]);
            return;
        }

        // Check if this is a redelivered event that we've already processed
        if ($isRedeliveryEvent && $webhookEventId) {
            if (Message::where('webhook_event_id', $webhookEventId)->exists()) {
                Log::info('Redelivered webhook event already processed, skipping', [
                    'webhook_event_id' => $webhookEventId,
                ]);
                return;
            }
        }

        // Check rate limit before processing
        $rateLimitResult = $rateLimitService->checkRateLimit($this->bot, $userId);
        if (!$rateLimitResult['allowed']) {
            $this->handleRateLimitExceeded($lineService, $rateLimitService, $replyToken, $userId, $rateLimitResult['status']);
            return;
        }

        // Check response hours before processing
        $responseHoursResult = $responseHoursService->checkResponseHours($this->bot);
        if (!$responseHoursResult['allowed']) {
            $this->handleOutsideResponseHours($lineService, $responseHoursService, $replyToken, $userId);
            return;
        }

        // Check if message aggregation is enabled
        $useAggregation = $aggregationService->isEnabled($this->bot);
        $waitTimeMs = $useAggregation ? $aggregationService->getWaitTimeMs($this->bot) : 0;

        // Show loading indicator immediately (before AI processing)
        // Extended duration if aggregation is enabled to cover wait time
        $loadingDuration = $useAggregation ? max(60, (int) ceil($waitTimeMs / 1000) + 30) : 30;
        $lineService->showLoadingIndicator($this->bot, $userId, $loadingDuration);

        // Variables to collect for broadcasting after transaction
        $userMessage = null;
        $botMessage = null;
        $conversation = null;
        $isHandover = false;
        $isNewConversation = false;
        $dispatchAggregation = false;
        $aggregationGroupId = null;

        // Process in transaction (no broadcasts inside to prevent blocking)
        DB::transaction(function () use (
            $lineService,
            $aiService,
            $rateLimitService,
            $aggregationService,
            $userId,
            $replyToken,
            $messageData,
            $useAggregation,
            $waitTimeMs,
            $webhookEventId,
            $eventTimestamp,
            $isRedeliveryEvent,
            &$userMessage,
            &$botMessage,
            &$conversation,
            &$isHandover,
            &$isNewConversation,
            &$dispatchAggregation,
            &$aggregationGroupId
        ) {
            // Find or create conversation (include handover status for auto_handover bots)
            $existingConversation = Conversation::where('bot_id', $this->bot->id)
                ->where('external_customer_id', $userId)
                ->where('channel_type', 'line')
                ->whereIn('status', ['active', 'handover'])
                ->first();

            $isNewConversation = !$existingConversation;
            $conversation = $existingConversation ?? $this->createNewConversation($userId, $lineService);

            // Primary deduplication: webhookEventId (LINE best practice)
            if ($webhookEventId && Message::where('conversation_id', $conversation->id)
                ->where('webhook_event_id', $webhookEventId)
                ->exists()) {
                Log::info('Duplicate webhook ignored (by webhook_event_id)', [
                    'conversation_id' => $conversation->id,
                    'webhook_event_id' => $webhookEventId,
                ]);
                return;
            }

            // Fallback deduplication: external_message_id (backward compatibility)
            if ($messageData['id'] && Message::where('conversation_id', $conversation->id)
                ->where('external_message_id', $messageData['id'])
                ->exists()) {
                Log::info('Duplicate webhook ignored (by external_message_id)', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $messageData['id'],
                ]);
                return;
            }

            // Save user message with LINE event metadata
            $userMessage = $conversation->messages()->create([
                'sender' => 'user',
                'content' => $messageData['text'],
                'type' => 'text',
                'external_message_id' => $messageData['id'],
                'webhook_event_id' => $webhookEventId,
                'is_redelivery' => $isRedeliveryEvent,
                'event_timestamp' => $eventTimestamp,
            ]);

            // Increment rate limit counters after successful message save
            $rateLimitService->incrementCounters($this->bot, $userId);

            // Check if bot is active - if not, save message but don't respond
            if ($this->bot->status !== 'active') {
                Log::info('Bot is inactive, message saved but skipping AI response', [
                    'bot_id' => $this->bot->id,
                    'conversation_id' => $conversation->id,
                    'status' => $this->bot->status,
                ]);
                // Update stats for user message only
                $this->updateStatsForUserMessageOnly($conversation);
                if ($isNewConversation) {
                    $this->bot->update([
                        'total_conversations' => DB::raw('total_conversations + 1'),
                    ]);
                }
                return; // Exit transaction, message is saved
            }

            // Check handover status
            $isHandover = $conversation->is_handover;

            if ($isHandover) {
                Log::info('Conversation in handover mode, skipping AI response', [
                    'conversation_id' => $conversation->id,
                ]);
                // Update stats for user message only (1 message)
                $this->updateStatsForUserMessageOnly($conversation);
                return;
            }

            // Check if we should use message aggregation
            if ($useAggregation) {
                $smartAnalyzer = app(SmartAggregationAnalyzer::class);

                // Build context for smart decisions
                $context = $aggregationService->buildContext(
                    $conversation,
                    $messageData['text'] ?? '',
                    $userId // external user ID for per-user learning
                );

                // Check if we should trigger early (skip waiting)
                if ($smartAnalyzer->isSmartEnabled($this->bot) &&
                    $smartAnalyzer->shouldTriggerEarly($messageData['text'] ?? '', $context)) {
                    Log::info('Smart aggregation: early trigger activated', [
                        'conversation_id' => $conversation->id,
                        'message' => mb_substr($messageData['text'] ?? '', 0, 50),
                    ]);
                    $useAggregation = false; // Skip aggregation, respond immediately
                } else {
                    // Calculate adaptive wait time
                    if ($smartAnalyzer->isSmartEnabled($this->bot)) {
                        $waitTimeMs = $smartAnalyzer->calculateAdaptiveWaitTime($context);
                        Log::debug('Smart aggregation: using adaptive wait time', [
                            'conversation_id' => $conversation->id,
                            'wait_ms' => $waitTimeMs,
                            'avg_gap_ms' => $context->avgGapMs,
                        ]);
                    }

                    // Start or continue aggregation group
                    $aggregationResult = $aggregationService->startOrContinueAggregation(
                        $conversation,
                        $userMessage,
                        $waitTimeMs
                    );

                    // If aggregation failed (cache error), fall back to immediate response
                    if ($aggregationResult === null) {
                        $useAggregation = false;
                        // Continue to immediate response below
                    } else {
                        $aggregationGroupId = $aggregationResult['group_id'];
                        $dispatchAggregation = true;

                        // Track typing gap for per-user learning (Phase 4)
                        $settings = $this->bot->settings;
                        if ($settings?->smart_per_user_learning_enabled && $context->lastGapMs !== null) {
                            $userTypingStats = app(UserTypingStats::class);
                            $userTypingStats->updateStats($this->bot->id, $userId, $context->lastGapMs);
                        }

                        Log::info('Message added to aggregation group', [
                            'conversation_id' => $conversation->id,
                            'group_id' => $aggregationGroupId,
                            'is_new_group' => $aggregationResult['is_new_group'],
                            'message_count' => $aggregationResult['message_count'],
                            'adaptive_wait_ms' => $waitTimeMs,
                        ]);

                        // Update stats for user message only (bot response will be counted later)
                        $this->updateStatsForUserMessageOnly($conversation);

                        // Increment total_conversations for new conversations
                        if ($isNewConversation) {
                            $this->bot->update([
                                'total_conversations' => DB::raw('total_conversations + 1'),
                            ]);
                        }

                        // Store adaptive wait time for dispatch after transaction
                        $this->adaptiveWaitTimeMs = $waitTimeMs;

                        return; // Exit transaction, will dispatch job outside
                    }
                }
            }

            // Immediate response mode (aggregation disabled)
            // Generate AI response
            $botMessage = $aiService->generateAndSaveResponse(
                $this->bot,
                $conversation,
                $userMessage
            );

            // Send reply to LINE (with multiple bubbles support)
            if ($botMessage->content) {
                $bubblesService = app(MultipleBubblesService::class);

                if ($bubblesService->isEnabled($this->bot)) {
                    // Parse content into bubbles and send with optional delays
                    $bubbles = $bubblesService->parseIntoBubbles($botMessage->content, $this->bot);
                    $bubblesService->sendBubbles($this->bot, $userId, $replyToken, $bubbles);
                } else {
                    // Standard single message reply with fallback to push
                    $retryKey = $lineService->generateRetryKey();
                    $lineService->replyWithFallback($this->bot, $replyToken, $userId, [$botMessage->content], $retryKey);
                }
            }

            // Update conversation and bot stats in batch (2 messages: user + bot)
            $this->updateStatsInBatch($conversation, $isNewConversation);
        });

        // Dispatch aggregation job AFTER transaction commits (to ensure message is saved)
        if ($dispatchAggregation && $conversation && $aggregationGroupId) {
            // Use adaptive wait time if available, otherwise fall back to base wait time
            $delayMs = $this->adaptiveWaitTimeMs ?? $waitTimeMs;

            ProcessAggregatedMessages::dispatch(
                $this->bot,
                $conversation,
                $aggregationGroupId,
                $userId
            )->onQueue('webhooks')->delay(now()->addMilliseconds($delayMs));

            Log::info('Dispatched aggregated message job', [
                'conversation_id' => $conversation->id,
                'group_id' => $aggregationGroupId,
                'delay_ms' => $delayMs,
                'is_adaptive' => $this->adaptiveWaitTimeMs !== null,
            ]);
        }

        // Broadcasts AFTER transaction commits (non-blocking)
        // Refresh conversation to get actual DB values after DB::raw updates
        if ($conversation) {
            $conversation->refresh();
            $conversationData = [
                'id' => $conversation->id,
                'message_count' => $conversation->message_count,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'unread_count' => $conversation->unread_count,
            ];

            // Mark lead recovery as responded when customer sends a message
            app(LeadRecoveryService::class)->markCustomerResponded($conversation);
        }
        if ($userMessage) {
            broadcast(new MessageSent($userMessage, $conversationData ?? null))->toOthers();
        }
        if ($botMessage) {
            broadcast(new MessageSent($botMessage, $conversationData ?? null))->toOthers();
        }
        if ($conversation) {
            broadcast(new ConversationUpdated($conversation, 'message_received'))->toOthers();
        }
    }

    /**
     * Create a new conversation.
     */
    protected function createNewConversation(string $userId, LINEService $lineService): Conversation
    {
        // Create or update customer profile
        $customerProfile = $this->findOrCreateCustomerProfile($userId, $lineService);

        // Check if bot has auto_handover enabled
        $autoHandover = $this->bot->auto_handover ?? false;

        // Create new conversation
        $conversation = Conversation::create([
            'bot_id' => $this->bot->id,
            'customer_profile_id' => $customerProfile?->id,
            'external_customer_id' => $userId,
            'channel_type' => 'line',
            'status' => $autoHandover ? 'handover' : 'active',
            'is_handover' => $autoHandover,
            'current_flow_id' => $this->bot->default_flow_id,
            'message_count' => 0,
        ]);

        // Auto-assign conversation if enabled
        $autoAssignment = app(AutoAssignmentService::class);
        $assignedUser = $autoAssignment->assignConversation($this->bot, $conversation);

        if ($assignedUser) {
            $conversation->update(['assigned_user_id' => $assignedUser->id]);
        }

        // Broadcast new conversation event
        broadcast(new ConversationUpdated($conversation, 'created'))->toOthers();

        return $conversation;
    }

    /**
     * Update stats for user message only (handover mode).
     */
    protected function updateStatsForUserMessageOnly(Conversation $conversation): void
    {
        // Batch update conversation stats (1 query instead of 2)
        $conversation->update([
            'unread_count' => DB::raw('unread_count + 1'),
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => now(),
        ]);

        // Batch update bot stats (1 query instead of 3)
        $this->bot->update([
            'total_messages' => DB::raw('total_messages + 1'),
            'last_active_at' => now(),
        ]);
    }

    /**
     * Update conversation and bot statistics in batch.
     */
    protected function updateStatsInBatch(Conversation $conversation, bool $isNewConversation): void
    {
        // Batch update conversation stats (1 query instead of 3)
        $conversation->update([
            'unread_count' => DB::raw('unread_count + 1'),
            'message_count' => DB::raw('message_count + 2'),
            'last_message_at' => now(),
        ]);

        // Batch update bot stats (1 query instead of 3)
        $botUpdate = [
            'total_messages' => DB::raw('total_messages + 2'),
            'last_active_at' => now(),
        ];

        // Only increment total_conversations for new conversations
        if ($isNewConversation) {
            $botUpdate['total_conversations'] = DB::raw('total_conversations + 1');
        }

        $this->bot->update($botUpdate);
    }

    /**
     * Handle non-text messages (images, videos, audio, files, stickers, locations).
     * Downloads media and saves to database for display in chat.
     * For images: analyzes with AI vision if bot is active and model supports vision.
     */
    protected function handleNonTextMessage(
        LINEService $lineService,
        ResponseHoursService $responseHoursService
    ): void {
        $userId = $lineService->extractUserId($this->event);
        $replyToken = $lineService->extractReplyToken($this->event);
        $messageData = $lineService->extractMessage($this->event);
        $messageType = $messageData['type'];

        // Extract LINE webhook event metadata (best practice)
        $webhookEventId = $lineService->extractWebhookEventId($this->event);
        $eventTimestamp = $lineService->extractEventTimestamp($this->event);
        $isRedeliveryEvent = $lineService->isRedelivery($this->event);

        if (!$userId) {
            return;
        }

        // Check if this is a redelivered event that we've already processed
        if ($isRedeliveryEvent && $webhookEventId) {
            if (Message::where('webhook_event_id', $webhookEventId)->exists()) {
                Log::info('Redelivered non-text webhook already processed, skipping', [
                    'webhook_event_id' => $webhookEventId,
                    'message_type' => $messageType,
                ]);
                return;
            }
        }

        // Download media BEFORE transaction (external API call shouldn't be in transaction)
        $mediaUrl = null;
        $mediaType = null;
        $content = null;

        if (in_array($messageType, ['image', 'video', 'audio', 'file'])) {
            $mediaData = $lineService->downloadAndStoreFile($this->bot, $messageData['id'], $messageType);
            if ($mediaData) {
                $mediaUrl = $mediaData['url'];
                $mediaType = $mediaData['mime_type'];
            }
            $content = match ($messageType) {
                'image' => '[รูปภาพ]',
                'video' => '[วิดีโอ]',
                'audio' => '[เสียง]',
                'file' => '[ไฟล์]',
                default => '[สื่อ]',
            };
        } elseif ($messageType === 'sticker') {
            $content = '[สติกเกอร์]';
            // Construct sticker URL from LINE CDN
            $stickerId = $messageData['sticker_id'] ?? null;
            if ($stickerId) {
                $mediaUrl = "https://stickershop.line-scdn.net/stickershop/v1/sticker/{$stickerId}/android/sticker.png";
                $mediaType = 'image/png';
            }
        } elseif ($messageType === 'location') {
            $lat = $messageData['latitude'] ?? '';
            $lng = $messageData['longitude'] ?? '';
            $addr = $messageData['address'] ?? '';
            $content = "[ตำแหน่ง] {$addr} ({$lat}, {$lng})";
        } else {
            $content = '[ข้อความที่ไม่รองรับ]';
        }

        // Variables for broadcasting after transaction
        $userMessage = null;
        $conversation = null;
        $isNewConversation = false;

        // Process in transaction to prevent race conditions and ensure atomic updates
        DB::transaction(function () use (
            $lineService,
            $userId,
            $messageData,
            $messageType,
            $mediaUrl,
            $mediaType,
            $content,
            $webhookEventId,
            $eventTimestamp,
            $isRedeliveryEvent,
            &$userMessage,
            &$conversation,
            &$isNewConversation
        ) {
            // Find or create conversation (include handover status for auto_handover bots)
            $existingConversation = Conversation::where('bot_id', $this->bot->id)
                ->where('external_customer_id', $userId)
                ->where('channel_type', 'line')
                ->whereIn('status', ['active', 'handover'])
                ->first();

            $isNewConversation = !$existingConversation;
            $conversation = $existingConversation ?? $this->createNewConversation($userId, $lineService);

            // Primary deduplication: webhookEventId (LINE best practice)
            if ($webhookEventId && Message::where('conversation_id', $conversation->id)
                ->where('webhook_event_id', $webhookEventId)
                ->exists()) {
                Log::info('Duplicate non-text webhook ignored (by webhook_event_id)', [
                    'conversation_id' => $conversation->id,
                    'webhook_event_id' => $webhookEventId,
                ]);
                return;
            }

            // Fallback deduplication: external_message_id (backward compatibility)
            if ($messageData['id'] && Message::where('conversation_id', $conversation->id)
                ->where('external_message_id', $messageData['id'])
                ->exists()) {
                Log::info('Duplicate non-text webhook ignored (by external_message_id)', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $messageData['id'],
                ]);
                return;
            }

            // Save message to database with LINE event metadata
            $userMessage = $conversation->messages()->create([
                'sender' => 'user',
                'content' => $content,
                'type' => $messageType,
                'media_url' => $mediaUrl,
                'media_type' => $mediaType,
                'external_message_id' => $messageData['id'],
                'webhook_event_id' => $webhookEventId,
                'is_redelivery' => $isRedeliveryEvent,
                'event_timestamp' => $eventTimestamp,
            ]);

            // Update stats atomically with message creation
            $this->updateStatsForUserMessageOnly($conversation);
            if ($isNewConversation) {
                $this->bot->update([
                    'total_conversations' => DB::raw('total_conversations + 1'),
                ]);
            }
        });

        // Broadcasts AFTER transaction commits (non-blocking)
        // Refresh conversation to get actual DB values after DB::raw updates
        if ($conversation) {
            $conversation->refresh();
            $conversationData = [
                'id' => $conversation->id,
                'message_count' => $conversation->message_count,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'unread_count' => $conversation->unread_count,
            ];

            // Mark lead recovery as responded when customer sends a message
            app(LeadRecoveryService::class)->markCustomerResponded($conversation);
        }
        if ($userMessage) {
            broadcast(new MessageSent($userMessage, $conversationData ?? null))->toOthers();
        }
        if ($conversation) {
            broadcast(new ConversationUpdated($conversation, 'message_received'))->toOthers();
        }

        // Check response hours AFTER saving message but BEFORE AI response
        $responseHoursResult = $responseHoursService->checkResponseHours($this->bot);
        if (!$responseHoursResult['allowed']) {
            Log::info('Non-text message received outside response hours', [
                'bot_id' => $this->bot->id,
                'message_type' => $messageType,
                'status' => $responseHoursResult['status'],
                'current_time' => $responseHoursResult['current_time'] ?? null,
            ]);

            // Send offline message for non-sticker messages (stickers stay silent)
            if ($messageType !== 'sticker') {
                $this->handleOutsideResponseHours($lineService, $responseHoursService, $replyToken, $userId);
            }
            return; // Skip AI response
        }

        // Handle image analysis with AI Vision
        if ($messageType === 'image' && $mediaUrl && $conversation && $replyToken) {
            $this->handleImageAnalysis($lineService, $conversation, $userMessage, $mediaUrl, $userId, $replyToken, $conversationData ?? null);
            return; // Skip sticker reply handling for images
        }

        // Reply to stickers if enabled (and not in handover mode, and bot is active)
        if ($messageType === 'sticker' && $replyToken && $conversation && !$conversation->is_handover && $this->bot->status === 'active') {
            $this->handleStickerReply($lineService, $conversation, $messageData, $userId, $replyToken, $conversationData ?? null);
        }

        // Non-text messages (except stickers with reply enabled) are stored silently
    }

    /**
     * Handle sticker reply with support for static and AI modes.
     *
     * @param LINEService $lineService
     * @param Conversation $conversation
     * @param array $messageData
     * @param string $userId
     * @param string $replyToken
     * @param array|null $conversationData
     */
    protected function handleStickerReply(
        LINEService $lineService,
        Conversation $conversation,
        array $messageData,
        string $userId,
        string $replyToken,
        ?array $conversationData
    ): void {
        $settings = $this->bot->settings;
        if (!$settings?->reply_sticker_enabled) {
            return;
        }

        $mode = $settings->reply_sticker_mode ?? 'static';

        // Show loading indicator for AI mode
        if ($mode === 'ai') {
            $lineService->showLoadingIndicator($this->bot, $userId, 15);
        }

        try {
            $stickerService = app(StickerReplyService::class);
            $responseMessage = $stickerService->generateReply($this->bot, $conversation, $messageData);

            if (!$responseMessage) {
                return;
            }

            // Send reply with fallback to push if token expired
            $retryKey = $lineService->generateRetryKey();
            $lineService->replyWithFallback($this->bot, $replyToken, $userId, [$responseMessage], $retryKey);

            // Save bot response
            $botMessage = $conversation->messages()->create([
                'sender' => 'bot',
                'content' => $responseMessage,
                'type' => 'text',
                'metadata' => [
                    'sticker_reply' => true,
                    'sticker_mode' => $mode,
                    'sticker_id' => $messageData['sticker_id'] ?? null,
                ],
            ]);

            // Update stats
            $conversation->update([
                'message_count' => DB::raw('message_count + 1'),
                'last_message_at' => now(),
            ]);
            $this->bot->update([
                'total_messages' => DB::raw('total_messages + 1'),
                'last_active_at' => now(),
            ]);

            // Broadcast
            $conversation->refresh();
            broadcast(new MessageSent($botMessage, [
                'id' => $conversation->id,
                'message_count' => $conversation->message_count,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'unread_count' => $conversation->unread_count,
            ]))->toOthers();

            Log::info('Replied to sticker', [
                'bot_id' => $this->bot->id,
                'conversation_id' => $conversation->id,
                'mode' => $mode,
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to reply to sticker', [
                'bot_id' => $this->bot->id,
                'mode' => $mode,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle image analysis using AI Vision.
     *
     * Conditions for AI analysis:
     * - Bot is active
     * - Conversation is not in handover mode
     * - Bot's model supports vision
     *
     * @param LINEService $lineService
     * @param Conversation $conversation
     * @param Message $userMessage
     * @param string $imageUrl
     * @param string $userId
     * @param string $replyToken
     * @param array|null $conversationData
     */
    protected function handleImageAnalysis(
        LINEService $lineService,
        Conversation $conversation,
        Message $userMessage,
        string $imageUrl,
        string $userId,
        string $replyToken,
        ?array $conversationData
    ): void {
        // Check if bot is active
        if ($this->bot->status !== 'active') {
            Log::info('Bot inactive, skipping image analysis', [
                'bot_id' => $this->bot->id,
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        // Check if conversation is in handover mode
        if ($conversation->is_handover) {
            Log::info('Conversation in handover mode, skipping image analysis', [
                'bot_id' => $this->bot->id,
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        // Get the model from bot/flow settings
        $openRouterService = app(OpenRouterService::class);
        $model = $this->getVisionModel();

        // Check if model supports vision
        if (!$model || !$openRouterService->supportsVision($model)) {
            Log::info('Model does not support vision, skipping image analysis', [
                'bot_id' => $this->bot->id,
                'conversation_id' => $conversation->id,
                'model' => $model,
            ]);
            return;
        }

        // Show loading indicator
        $lineService->showLoadingIndicator($this->bot, $userId, 30);

        try {
            // Build system prompt for image analysis
            $systemPrompt = $this->buildVisionSystemPrompt();

            // Get conversation history for context
            $history = $this->getVisionConversationHistory($conversation);

            // Build messages array
            $messages = [];

            // Add system prompt
            if ($systemPrompt) {
                $messages[] = [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ];
            }

            // Add conversation history
            foreach ($history as $msg) {
                $messages[] = [
                    'role' => $msg['sender'] === 'user' ? 'user' : 'assistant',
                    'content' => $msg['content'],
                ];
            }

            // Add current image message with prompt
            $imagePrompt = $this->getImageAnalysisPrompt();
            $messages[] = [
                'role' => 'user',
                'content' => $imagePrompt,
            ];

            // Get API key
            $apiKey = $this->bot->user?->settings?->getOpenRouterApiKey()
                ?? config('services.openrouter.api_key');

            // Call Vision API
            $result = $openRouterService->chatWithVision(
                messages: $messages,
                imageUrls: [$imageUrl],
                model: $model,
                temperature: $this->bot->llm_temperature ?? 0.7,
                maxTokens: $this->bot->llm_max_tokens ?? 1024,
                apiKeyOverride: $apiKey
            );

            $responseContent = $result['content'] ?? '';

            if (empty($responseContent)) {
                Log::warning('Empty response from Vision API', [
                    'bot_id' => $this->bot->id,
                    'conversation_id' => $conversation->id,
                ]);
                return;
            }

            // Save bot response
            $botMessage = $conversation->messages()->create([
                'sender' => 'bot',
                'content' => $responseContent,
                'type' => 'text',
                'model_used' => $result['model'] ?? $model,
                'prompt_tokens' => $result['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $result['usage']['completion_tokens'] ?? 0,
                'cost' => $openRouterService->estimateCost(
                    $result['usage']['prompt_tokens'] ?? 0,
                    $result['usage']['completion_tokens'] ?? 0,
                    $result['model'] ?? $model
                ),
                'metadata' => [
                    'vision_analysis' => true,
                    'image_url' => $imageUrl,
                ],
            ]);

            // Update conversation stats
            $conversation->update([
                'message_count' => DB::raw('message_count + 1'),
                'last_message_at' => now(),
            ]);

            // Update bot stats
            $this->bot->update([
                'total_messages' => DB::raw('total_messages + 1'),
                'last_active_at' => now(),
            ]);

            // Send reply to LINE with fallback to push if token expired
            $bubblesService = app(MultipleBubblesService::class);
            if ($bubblesService->isEnabled($this->bot)) {
                $bubbles = $bubblesService->parseIntoBubbles($responseContent, $this->bot);
                $bubblesService->sendBubbles($this->bot, $userId, $replyToken, $bubbles);
            } else {
                $retryKey = $lineService->generateRetryKey();
                $lineService->replyWithFallback($this->bot, $replyToken, $userId, [$responseContent], $retryKey);
            }

            // Refresh and broadcast
            $conversation->refresh();
            $updatedConversationData = [
                'id' => $conversation->id,
                'message_count' => $conversation->message_count,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'unread_count' => $conversation->unread_count,
            ];

            broadcast(new MessageSent($botMessage, $updatedConversationData))->toOthers();
            broadcast(new ConversationUpdated($conversation, 'message_received'))->toOthers();

            Log::info('Image analyzed successfully', [
                'bot_id' => $this->bot->id,
                'conversation_id' => $conversation->id,
                'model' => $result['model'] ?? $model,
                'tokens_used' => $result['usage']['total_tokens'] ?? 0,
            ]);

        } catch (\Exception $e) {
            Log::error('Image analysis failed', [
                'bot_id' => $this->bot->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Fail silently - image is already saved, just no AI response
        }
    }

    /**
     * Get the vision-capable model to use for image analysis.
     *
     * Priority:
     * 1. Bot's primary_chat_model (from Connection Settings UI)
     * 2. Bot's fallback_chat_model (fallback model)
     * 3. Default vision model (Gemini 2.0 Flash)
     */
    protected function getVisionModel(): string
    {
        $openRouterService = app(OpenRouterService::class);

        // Priority 1: Bot's primary chat model (from Connection Settings UI)
        if ($this->bot->primary_chat_model && $openRouterService->supportsVision($this->bot->primary_chat_model)) {
            Log::debug('Vision model: primary_chat_model', ['model' => $this->bot->primary_chat_model]);
            return $this->bot->primary_chat_model;
        }

        // Priority 2: Bot's fallback chat model
        if ($this->bot->fallback_chat_model && $openRouterService->supportsVision($this->bot->fallback_chat_model)) {
            Log::debug('Vision model: fallback_chat_model', ['model' => $this->bot->fallback_chat_model]);
            return $this->bot->fallback_chat_model;
        }

        // Priority 3: Default vision model
        $defaultModel = config('llm-models.default_vision_model', 'google/gemini-2.0-flash-001');
        Log::debug('Vision model: default', ['model' => $defaultModel]);
        return $defaultModel;
    }

    /**
     * Build system prompt for vision/image analysis.
     * Uses bot's system prompt with vision-specific additions.
     */
    protected function buildVisionSystemPrompt(): string
    {
        // Get base system prompt from bot or flow
        $basePrompt = '';

        if (!empty($this->bot->system_prompt)) {
            $basePrompt = $this->bot->system_prompt;
        } elseif ($this->bot->default_flow_id) {
            $flow = $this->bot->defaultFlow;
            if ($flow && !empty($flow->system_prompt)) {
                $basePrompt = $flow->system_prompt;
            }
        }

        if (empty($basePrompt)) {
            $basePrompt = "You are a helpful AI assistant for {$this->bot->name}. Be friendly, professional, and helpful.";
        }

        // Add vision-specific instruction
        $visionInstruction = "\n\nWhen analyzing images, describe what you see clearly and helpfully. Answer any questions about the image content.";

        return $basePrompt . $visionInstruction;
    }

    /**
     * Get the prompt to use when analyzing an image.
     */
    protected function getImageAnalysisPrompt(): string
    {
        // Check bot settings for custom image prompt
        $settings = $this->bot->settings;
        if ($settings && !empty($settings->image_analysis_prompt)) {
            return $settings->image_analysis_prompt;
        }

        // Default Thai prompt (since most users are Thai based on existing code)
        return 'กรุณาอธิบายรูปภาพนี้ และช่วยตอบคำถามหากมี';
    }

    /**
     * Get conversation history for vision context.
     * Limited to recent messages to keep context manageable.
     */
    protected function getVisionConversationHistory(Conversation $conversation, int $limit = 5): array
    {
        $query = $conversation->messages()
            ->whereIn('sender', ['user', 'bot'])
            ->where('type', 'text'); // Only include text messages in history

        // Filter out messages before context was cleared
        if ($conversation->context_cleared_at) {
            $query->where('created_at', '>', $conversation->context_cleared_at);
        }

        return $query->latest()
            ->take($limit)
            ->get()
            ->reverse()
            ->map(fn (Message $msg) => [
                'sender' => $msg->sender,
                'content' => $msg->content,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Handle rate limit exceeded.
     * Sends custom message if configured, otherwise stays silent.
     */
    protected function handleRateLimitExceeded(
        LINEService $lineService,
        RateLimitService $rateLimitService,
        ?string $replyToken,
        string $userId,
        string $status
    ): void {
        // Get custom message from bot settings (null = silent)
        $message = $rateLimitService->getRateLimitMessage($status, $this->bot->settings);

        // If no custom message, stay silent (default behavior)
        if (!$message) {
            return;
        }

        // Send custom rate limit message to user with fallback to push
        try {
            $retryKey = $lineService->generateRetryKey();
            $lineService->replyWithFallback($this->bot, $replyToken, $userId, [$message], $retryKey);
        } catch (\Exception $e) {
            Log::warning('Failed to send rate limit message', [
                'bot_id' => $this->bot->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle messages received outside response hours.
     * Sends offline message if configured, otherwise stays silent.
     */
    protected function handleOutsideResponseHours(
        LINEService $lineService,
        ResponseHoursService $responseHoursService,
        ?string $replyToken,
        string $userId
    ): void {
        $message = $responseHoursService->getOfflineMessage($this->bot->settings);

        Log::info('Message received outside response hours', [
            'bot_id' => $this->bot->id,
            'has_offline_message' => $message !== null,
        ]);

        if (!$message) {
            return;
        }

        try {
            $retryKey = $lineService->generateRetryKey();
            $lineService->replyWithFallback($this->bot, $replyToken, $userId, [$message], $retryKey);
        } catch (\Exception $e) {
            Log::warning('Failed to send offline message', [
                'bot_id' => $this->bot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find or create customer profile.
     *
     * Uses updateOrCreate with unique constraint to handle race conditions atomically.
     * This approach works safely inside transactions because PostgreSQL's ON CONFLICT
     * handles the race condition at database level without causing transaction abort.
     */
    protected function findOrCreateCustomerProfile(string $userId, LINEService $lineService): ?CustomerProfile
    {
        // Get LINE profile first (outside of DB operation)
        $lineProfile = $lineService->getProfile($this->bot, $userId);

        // Use updateOrCreate which generates ON CONFLICT DO UPDATE in PostgreSQL
        // This handles race conditions atomically at database level
        $profile = CustomerProfile::updateOrCreate(
            [
                'external_id' => $userId,
                'channel_type' => 'line',
            ],
            [
                'display_name' => $lineProfile['displayName'] ?? null,
                'picture_url' => $lineProfile['pictureUrl'] ?? null,
                'last_interaction_at' => now(),
                'metadata' => [
                    'status_message' => $lineProfile['statusMessage'] ?? null,
                ],
            ]
        );

        // Set first_interaction_at only for new profiles
        if ($profile->wasRecentlyCreated) {
            $profile->update([
                'first_interaction_at' => now(),
                'interaction_count' => 1,
            ]);
        } else {
            // Increment interaction count for existing profiles
            $profile->increment('interaction_count');
        }

        return $profile;
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('LINE webhook job failed permanently', [
            'bot_id' => $this->bot->id,
            'event_type' => $this->event['type'] ?? 'unknown',
            'error' => $exception->getMessage(),
        ]);
    }
}
