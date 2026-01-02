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
use App\Services\LINEService;
use App\Services\MessageAggregationService;
use App\Services\MultipleBubblesService;
use App\Services\RateLimitService;
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
     */
    public int $backoff = 5;

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
        MessageAggregationService $aggregationService
    ): void {
        try {
            $this->processEvent($lineService, $aiService, $rateLimitService, $aggregationService);
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
     * Process the LINE event.
     */
    protected function processEvent(
        LINEService $lineService,
        AIService $aiService,
        RateLimitService $rateLimitService,
        MessageAggregationService $aggregationService
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
            $this->handleNonTextMessage($lineService);
            return;
        }

        // Extract event data
        $userId = $lineService->extractUserId($this->event);
        $replyToken = $lineService->extractReplyToken($this->event);
        $messageData = $lineService->extractMessage($this->event);

        if (!$userId || !$messageData['text']) {
            Log::warning('Invalid LINE message event', [
                'has_user_id' => (bool) $userId,
                'has_text' => (bool) $messageData['text'],
            ]);
            return;
        }

        // Check rate limit before processing
        $rateLimitResult = $rateLimitService->checkRateLimit($this->bot, $userId);
        if (!$rateLimitResult['allowed']) {
            $this->handleRateLimitExceeded($lineService, $rateLimitService, $replyToken, $rateLimitResult['status']);
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

            // Check for duplicate message (LINE may send webhook multiple times)
            if ($messageData['id'] && Message::where('conversation_id', $conversation->id)
                ->where('external_message_id', $messageData['id'])
                ->exists()) {
                Log::info('Duplicate webhook ignored', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $messageData['id'],
                ]);
                return;
            }

            // Save user message (without separate increment)
            $userMessage = $conversation->messages()->create([
                'sender' => 'user',
                'content' => $messageData['text'],
                'type' => 'text',
                'external_message_id' => $messageData['id'],
            ]);

            // Increment rate limit counters after successful message save
            $rateLimitService->incrementCounters($this->bot, $userId);

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

                    Log::info('Message added to aggregation group', [
                        'conversation_id' => $conversation->id,
                        'group_id' => $aggregationGroupId,
                        'is_new_group' => $aggregationResult['is_new_group'],
                        'message_count' => $aggregationResult['message_count'],
                    ]);

                    // Update stats for user message only (bot response will be counted later)
                    $this->updateStatsForUserMessageOnly($conversation);

                    // Increment total_conversations for new conversations
                    if ($isNewConversation) {
                        $this->bot->update([
                            'total_conversations' => DB::raw('total_conversations + 1'),
                        ]);
                    }

                    return; // Exit transaction, will dispatch job outside
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
                } elseif ($replyToken) {
                    // Standard single message reply
                    $lineService->reply($this->bot, $replyToken, [$botMessage->content]);
                }
            }

            // Update conversation and bot stats in batch (2 messages: user + bot)
            $this->updateStatsInBatch($conversation, $isNewConversation);
        });

        // Dispatch aggregation job AFTER transaction commits (to ensure message is saved)
        if ($dispatchAggregation && $conversation && $aggregationGroupId) {
            ProcessAggregatedMessages::dispatch(
                $this->bot,
                $conversation,
                $aggregationGroupId,
                $userId
            )->onQueue('webhooks')->delay(now()->addMilliseconds($waitTimeMs));

            Log::debug('Dispatched aggregation job', [
                'conversation_id' => $conversation->id,
                'group_id' => $aggregationGroupId,
                'delay_ms' => $waitTimeMs,
            ]);
        }

        // Broadcasts AFTER transaction commits (non-blocking)
        if ($userMessage) {
            broadcast(new MessageSent($userMessage))->toOthers();
        }
        if ($botMessage) {
            broadcast(new MessageSent($botMessage))->toOthers();
        }
        if ($conversation) {
            broadcast(new ConversationUpdated($conversation->fresh(), 'message_received'))->toOthers();
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
     */
    protected function handleNonTextMessage(LINEService $lineService): void
    {
        $userId = $lineService->extractUserId($this->event);
        $replyToken = $lineService->extractReplyToken($this->event);
        $messageData = $lineService->extractMessage($this->event);
        $messageType = $messageData['type'];

        if (!$userId) {
            return;
        }

        // Find or create conversation (include handover status for auto_handover bots)
        $existingConversation = Conversation::where('bot_id', $this->bot->id)
            ->where('external_customer_id', $userId)
            ->where('channel_type', 'line')
            ->whereIn('status', ['active', 'handover'])
            ->first();

        $isNewConversation = !$existingConversation;
        $conversation = $existingConversation ?? $this->createNewConversation($userId, $lineService);

        // Check for duplicate message
        if ($messageData['id'] && Message::where('conversation_id', $conversation->id)
            ->where('external_message_id', $messageData['id'])
            ->exists()) {
            Log::info('Duplicate non-text webhook ignored', [
                'conversation_id' => $conversation->id,
                'message_id' => $messageData['id'],
            ]);
            return;
        }

        // Download media for supported types
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
        } elseif ($messageType === 'location') {
            $lat = $messageData['latitude'] ?? '';
            $lng = $messageData['longitude'] ?? '';
            $addr = $messageData['address'] ?? '';
            $content = "[ตำแหน่ง] {$addr} ({$lat}, {$lng})";
        } else {
            $content = '[ข้อความที่ไม่รองรับ]';
        }

        // Save message to database
        $conversation->messages()->create([
            'sender' => 'user',
            'content' => $content,
            'type' => $messageType,
            'media_url' => $mediaUrl,
            'media_type' => $mediaType,
            'external_message_id' => $messageData['id'],
        ]);

        // Update stats
        $this->updateStatsForUserMessageOnly($conversation);
        if ($isNewConversation) {
            $this->bot->update([
                'total_conversations' => DB::raw('total_conversations + 1'),
            ]);
        }

        // Broadcast update
        broadcast(new ConversationUpdated($conversation->fresh(), 'message_received'))->toOthers();

        // Send acknowledgment reply
        if ($replyToken) {
            $response = match ($messageType) {
                'image' => 'ได้รับรูปภาพแล้วครับ',
                'video' => 'ได้รับวิดีโอแล้วครับ',
                'audio' => 'ได้รับเสียงแล้วครับ',
                'sticker' => 'ขอบคุณสำหรับสติกเกอร์ครับ!',
                'location' => 'ได้รับตำแหน่งแล้วครับ',
                'file' => 'ได้รับไฟล์แล้วครับ',
                default => 'ได้รับข้อความแล้วครับ',
            };

            try {
                $lineService->reply($this->bot, $replyToken, [$response]);
            } catch (\Exception $e) {
                Log::warning('Failed to send non-text message acknowledgment', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle rate limit exceeded.
     * Sends custom message if configured, otherwise stays silent.
     */
    protected function handleRateLimitExceeded(
        LINEService $lineService,
        RateLimitService $rateLimitService,
        ?string $replyToken,
        string $status
    ): void {
        // Get custom message from bot settings (null = silent)
        $message = $rateLimitService->getRateLimitMessage($status, $this->bot->settings);

        // If no custom message, stay silent (default behavior)
        if (!$message || !$replyToken) {
            return;
        }

        // Send custom rate limit message to user
        try {
            $lineService->reply($this->bot, $replyToken, [$message]);
        } catch (\Exception $e) {
            Log::warning('Failed to send rate limit message', [
                'bot_id' => $this->bot->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find or create customer profile.
     */
    protected function findOrCreateCustomerProfile(string $userId, LINEService $lineService): ?CustomerProfile
    {
        // Try to find existing profile
        $profile = CustomerProfile::where('external_id', $userId)
            ->where('channel_type', 'line')
            ->first();

        if ($profile) {
            return $profile;
        }

        // Get LINE profile
        $lineProfile = $lineService->getProfile($this->bot, $userId);

        // Create new profile
        return CustomerProfile::create([
            'external_id' => $userId,
            'channel_type' => 'line',
            'display_name' => $lineProfile['displayName'] ?? null,
            'picture_url' => $lineProfile['pictureUrl'] ?? null,
            'first_interaction_at' => now(),
            'last_interaction_at' => now(),
            'interaction_count' => 1,
            'metadata' => [
                'status_message' => $lineProfile['statusMessage'] ?? null,
            ],
        ]);
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
