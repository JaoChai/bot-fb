<?php

namespace App\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Jobs\Middleware\CircuitBreakerJobMiddleware;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Services\AIService;
use App\Services\AutoAssignmentService;
use App\Services\Chat\ConversationContextService;
use App\Services\CircuitBreakerService;
use App\Services\FlowPluginService;
use App\Services\LeadRecoveryService;
use App\Services\LINEService;
use App\Services\LineWebhook\LineWebhookContextService;
use App\Services\LineWebhook\LineWebhookGatingService;
use App\Services\LineWebhook\LineWebhookOutputService;
use App\Services\LineWebhook\LineWebhookPipelineFlag;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\LineWebhook\WebhookContext;
use App\Services\MessageAggregationService;
use App\Services\MultipleBubblesService;
use App\Services\PaymentFlexService;
use App\Services\ProfilePictureService;
use App\Services\RateLimitService;
use App\Services\ResponseHoursService;
use App\Services\SmartAggregation\SmartAggregationAnalyzer;
use App\Services\SmartAggregation\UserTypingStats;
use App\Services\StickerReplyService;
use App\Services\Webhook\Channels\LINE\NonTextHandler;
use App\Services\Webhook\Channels\LINE\StickerHandler;
use App\Support\QueueRouter;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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
     * รองรับ reasoning effort=high (primary 90s + fallback 45s + intent 45s ≈ 180s).
     * ต้อง < queue retry_after (ดู deploy gate) กัน re-dispatch ซ้อน.
     */
    public int $timeout = 200;

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
     * The middleware to run when the job is dispatched (queued).
     */
    public function middleware(): array
    {
        return [app(CircuitBreakerJobMiddleware::class)];
    }

    /**
     * Execute the job.
     */
    public function handle(
        LINEService $lineService,
        AIService $aiService,
        RateLimitService $rateLimitService,
        MessageAggregationService $aggregationService,
        ResponseHoursService $responseHoursService,
        CircuitBreakerService $circuitBreaker,
        LineWebhookGatingService $gating,
        LineWebhookContextService $contextSvc,
        LineWebhookResponseService $responseSvc,
        LineWebhookOutputService $outputSvc,
    ): void {
        try {
            if (
                LineWebhookPipelineFlag::enabledFor($this->bot)
                && $lineService->isMessageEvent($this->event)
                && (
                    $lineService->isTextMessage($this->event)
                    || $lineService->isImageMessage($this->event)
                )
            ) {
                $this->runPipeline($gating, $contextSvc, $responseSvc, $outputSvc);

                return;
            }
            $this->processEvent($lineService, $aiService, $rateLimitService, $aggregationService, $responseHoursService);
        } catch (\Exception $e) {
            Log::error('LINE webhook processing failed', [
                'bot_id' => $this->bot->id,
                'event_type' => $this->event['type'] ?? 'unknown',
                'error' => $e->getMessage(),
                ...(! app()->environment('production') ? ['trace' => $e->getTraceAsString()] : []),
            ]);

            throw $e;
        }
    }

    private function runPipeline(
        LineWebhookGatingService $gating,
        LineWebhookContextService $contextSvc,
        LineWebhookResponseService $responseSvc,
        LineWebhookOutputService $outputSvc,
    ): void {
        $ctx = new WebhookContext($this->bot, $this->event);

        Log::debug('LINE webhook pipeline.start', [
            'bot_id' => $this->bot->id,
            'event_type' => $ctx->messageType(),
        ]);

        // Stage 1: Gating (rate limit only)
        $gating->check($ctx);
        if ($ctx->gateDecision !== null && $ctx->gateDecision->isBlocked()) {
            return;
        }

        // Stage 2: Context (profile, conversation, msg save, aggregation, outside-hours)
        $contextSvc->resolve($ctx);
        if ($ctx->gateDecision !== null && $ctx->gateDecision->isBlocked()) {
            return;
        }
        if ($ctx->aggregationBuffered) {
            return;
        }

        // For text messages: acquire response lock around Stage 3 + Stage 4 (mirror legacy order)
        if ($ctx->messageType() === 'text' && $ctx->conversation !== null && $ctx->userMessage !== null) {
            $lock = Cache::lock("ai_response:{$ctx->conversation->id}", 30);
            if (! $lock->get()) {
                Log::info('Response lock held, falling back to aggregation', [
                    'conversation_id' => $ctx->conversation->id,
                ]);

                $aggregation = app(MessageAggregationService::class);
                $fallback = $aggregation->startOrContinueAggregation(
                    $ctx->conversation, $ctx->userMessage, 15000
                );
                if ($fallback) {
                    ProcessAggregatedMessages::dispatch(
                        $ctx->bot, $ctx->conversation, $fallback['group_id'], $ctx->userId()
                    )->onConnection(QueueRouter::connection())->onQueue(QueueRouter::llmQueue())->delay(now()->addSeconds(15));
                }

                return;
            }

            try {
                $responseSvc->generate($ctx);
                $outputSvc->dispatch($ctx);
            } finally {
                $lock->release();
            }

            return;
        }

        // Non-text path: no lock (sticker/image have different concurrency semantics)
        $responseSvc->generate($ctx);
        $outputSvc->dispatch($ctx);
    }

    /**
     * Send fallback message when system is unavailable.
     * This method doesn't depend on database operations.
     */
    public function circuitFallback(): void
    {
        $lineService = app(LINEService::class);

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
        // Bind the job-scoped handlers so app(Handler::class) lookups inside
        // the legacy non-text path resolve the right Bot instance and the
        // job's shared helpers (createNewConversation /
        // updateStatsForUserMessageOnly) by closure.
        app()->bind(NonTextHandler::class, fn () => new NonTextHandler(
            $this->bot,
            app(ResponseHoursService::class),
            app(LeadRecoveryService::class),
            fn (string $userId, LINEService $lineService) => $this->createNewConversation($userId, $lineService),
            fn (Conversation $conversation, int $lastMessageId) => $this->updateStatsForUserMessageOnly($conversation, $lastMessageId),
        ));
        app()->bind(StickerHandler::class, fn () => new StickerHandler(
            $this->bot,
            app(StickerReplyService::class),
        ));

        // Only process message events
        if (! $lineService->isMessageEvent($this->event)) {
            Log::debug('Ignoring non-message event', [
                'type' => $this->event['type'] ?? 'unknown',
            ]);

            return;
        }

        // Only process text messages for now
        if (! $lineService->isTextMessage($this->event)) {
            app(NonTextHandler::class)->handle($lineService, $this->event);

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

        if (! $userId || ! $messageData['text']) {
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
        if (! $rateLimitResult['allowed']) {
            $this->handleRateLimitExceeded($lineService, $rateLimitService, $replyToken, $userId, $rateLimitResult['status']);

            return;
        }

        // Check response hours before processing
        $responseHoursResult = $responseHoursService->checkResponseHours($this->bot);
        if (! $responseHoursResult['allowed']) {
            $existingConv = Conversation::where('bot_id', $this->bot->id)
                ->where('external_customer_id', $userId)
                ->where('channel_type', 'line')
                ->whereIn('status', ['active', 'handover'])
                ->first();

            if (! $existingConv || ! $existingConv->is_handover) {
                $this->handleOutsideResponseHours($lineService, $responseHoursService, $replyToken, $userId);
            }

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

        // Flag to track if we should generate immediate response after transaction
        $shouldGenerateResponse = false;

        // Transaction 1: Fast validation, dedup, save user message (~50ms)
        // API calls (AI generate + LINE reply) are moved OUTSIDE to avoid holding DB lock for 2-3s
        DB::transaction(function () use (
            $lineService,
            $rateLimitService,
            $aggregationService,
            $userId,
            $messageData,
            $useAggregation,
            $waitTimeMs,
            $webhookEventId,
            $eventTimestamp,
            $isRedeliveryEvent,
            &$userMessage,
            &$conversation,
            &$isHandover,
            &$isNewConversation,
            &$dispatchAggregation,
            &$aggregationGroupId,
            &$shouldGenerateResponse
        ) {
            // Find or create conversation (include handover status for auto_handover bots)
            // Use lockForUpdate() to prevent race condition when multiple webhooks arrive simultaneously
            $existingConversation = Conversation::where('bot_id', $this->bot->id)
                ->where('external_customer_id', $userId)
                ->where('channel_type', 'line')
                ->whereIn('status', ['active', 'handover'])
                ->lockForUpdate()
                ->first();

            $isNewConversation = ! $existingConversation;
            $conversation = $existingConversation ?? $this->createNewConversation($userId, $lineService);

            // Primary deduplication: webhookEventId (LINE best practice)
            if ($webhookEventId && ($existingMsg = Message::where('conversation_id', $conversation->id)
                ->where('webhook_event_id', $webhookEventId)
                ->first())) {

                // Retry recovery: if user message exists but bot never responded (AI failed on previous attempt),
                // allow retry to generate the bot response
                $botAlreadyResponded = $this->botAlreadyRespondedTo($conversation->id, $existingMsg->created_at);

                if (! $botAlreadyResponded && $this->bot->status === 'active' && ! $conversation->is_handover) {
                    Log::info('Retry recovery: generating bot response for existing user message', [
                        'conversation_id' => $conversation->id,
                        'webhook_event_id' => $webhookEventId,
                        'attempt' => $this->attempts(),
                    ]);
                    $userMessage = $existingMsg;
                    $shouldGenerateResponse = true;
                } else {
                    Log::info('Duplicate webhook ignored (by webhook_event_id)', [
                        'conversation_id' => $conversation->id,
                        'webhook_event_id' => $webhookEventId,
                    ]);
                }

                return;
            }

            // Fallback deduplication: external_message_id (backward compatibility)
            if ($messageData['id'] && ($existingMsg = Message::where('conversation_id', $conversation->id)
                ->where('external_message_id', $messageData['id'])
                ->first())) {

                // Retry recovery: same logic as webhook_event_id dedup
                $botAlreadyResponded = $this->botAlreadyRespondedTo($conversation->id, $existingMsg->created_at);

                if (! $botAlreadyResponded && $this->bot->status === 'active' && ! $conversation->is_handover) {
                    Log::info('Retry recovery: generating bot response for existing user message (by message_id)', [
                        'conversation_id' => $conversation->id,
                        'message_id' => $messageData['id'],
                        'attempt' => $this->attempts(),
                    ]);
                    $userMessage = $existingMsg;
                    $shouldGenerateResponse = true;
                } else {
                    Log::info('Duplicate webhook ignored (by external_message_id)', [
                        'conversation_id' => $conversation->id,
                        'message_id' => $messageData['id'],
                    ]);
                }

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
                $this->updateStatsForUserMessageOnly($conversation, $userMessage->id);
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
                $this->updateStatsForUserMessageOnly($conversation, $userMessage->id);

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
                        $this->updateStatsForUserMessageOnly($conversation, $userMessage->id);

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

            // Mark that we should generate immediate response after transaction
            $shouldGenerateResponse = true;
        });

        // === API calls OUTSIDE transaction (no DB lock held) ===
        // AI generate (~2-3s) + LINE reply (~200ms) no longer block concurrent requests
        if ($shouldGenerateResponse && $userMessage && $conversation) {
            // Acquire per-conversation response lock to prevent duplicate AI responses
            $responseLock = Cache::lock("ai_response:{$conversation->id}", 30);

            if (! $responseLock->get()) {
                // Another job is already generating a response for this conversation
                // Fallback: create aggregation group as safety net
                Log::info('Response lock held, falling back to aggregation', [
                    'conversation_id' => $conversation->id,
                ]);

                $fallbackResult = $aggregationService->startOrContinueAggregation(
                    $conversation, $userMessage, 15000
                );

                if ($fallbackResult) {
                    ProcessAggregatedMessages::dispatch(
                        $this->bot,
                        $conversation,
                        $fallbackResult['group_id'],
                        $userId
                    )->onConnection(QueueRouter::connection())->onQueue(QueueRouter::llmQueue())->delay(now()->addSeconds(15));
                }

                // Stats will be updated by ProcessAggregatedMessages when it generates response
                return;
            }

            try {
                // Auto-clear stale context before AI generates response
                app(ConversationContextService::class)->autoClearIfIdle($conversation);

                // Generate AI response (no transaction lock held)
                $botMessage = $aiService->generateAndSaveResponse(
                    $this->bot,
                    $conversation,
                    $userMessage
                );

                // Send reply to LINE (with multiple bubbles support)
                if ($botMessage->content) {
                    $bubblesService = app(MultipleBubblesService::class);
                    $paymentFlex = app(PaymentFlexService::class);
                    $transformed = $paymentFlex->tryConvertToFlex($botMessage->content, $conversation);

                    if (is_array($transformed)) {
                        // Flex detected on full text → send as single message
                        $retryKey = $lineService->generateRetryKey();
                        $lineService->replyWithFallback($this->bot, $replyToken, $userId, [$transformed], $retryKey);
                    } elseif ($bubblesService->isEnabled($this->bot)) {
                        // No Flex match → normal bubble flow
                        $bubbles = $bubblesService->parseIntoBubbles($botMessage->content, $this->bot);
                        $bubblesService->sendBubbles($this->bot, $userId, $replyToken, $bubbles, $conversation);
                    } else {
                        // No Flex, no bubbles → send as plain text
                        $retryKey = $lineService->generateRetryKey();
                        $lineService->replyWithFallback($this->bot, $replyToken, $userId, [$botMessage->content], $retryKey);
                    }
                }

                // Execute flow plugins (e.g., Telegram notifications)
                if ($botMessage) {
                    try {
                        app(FlowPluginService::class)
                            ->executePlugins($this->bot, $conversation, $botMessage);
                    } catch (\Exception $e2) {
                        Log::warning('Flow plugin execution failed in LINE webhook', [
                            'conversation_id' => $conversation->id,
                            'error' => $e2->getMessage(),
                        ]);
                    }
                }

                // Update stats with atomic DB::raw operations (no transaction needed)
                $this->updateStatsInBatch($conversation, $isNewConversation);
            } catch (\Exception $e) {
                // User message is already saved in transaction 1
                // Log error but don't lose the user message
                Log::error('Failed to generate/send AI response after transaction', [
                    'bot_id' => $this->bot->id,
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                    ...(! app()->environment('production') ? ['trace' => $e->getTraceAsString()] : []),
                ]);

                throw $e;
            } finally {
                $responseLock->release();
            }
        }

        // Dispatch aggregation job AFTER transaction commits (to ensure message is saved)
        if ($dispatchAggregation && $conversation && $aggregationGroupId) {
            // Use adaptive wait time if available, otherwise fall back to base wait time
            $delayMs = $this->adaptiveWaitTimeMs ?? $waitTimeMs;

            ProcessAggregatedMessages::dispatch(
                $this->bot,
                $conversation,
                $aggregationGroupId,
                $userId
            )->onConnection(QueueRouter::connection())->onQueue(QueueRouter::llmQueue())->delay(now()->addMilliseconds($delayMs));

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
     * Determine whether the bot has already produced a reply for the given
     * existing user message. Shared between the webhook_event_id and
     * external_message_id dedup branches.
     */
    private function botAlreadyRespondedTo(int $conversationId, Carbon $existingMessageCreatedAt): bool
    {
        return Message::where('conversation_id', $conversationId)
            ->where('sender', 'bot')
            ->where('created_at', '>=', $existingMessageCreatedAt)
            ->exists();
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
    protected function updateStatsForUserMessageOnly(Conversation $conversation, int $lastMessageId): void
    {
        $conversation->update([
            'unread_count' => DB::raw('unread_count + 1'),
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => now(),
            'last_message_id' => $lastMessageId,
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
        if (! $message) {
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

        if (! $message) {
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
                'picture_url' => app(ProfilePictureService::class)->downloadAndStore(
                    'line', $userId, $lineProfile['pictureUrl'] ?? null
                ),
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
