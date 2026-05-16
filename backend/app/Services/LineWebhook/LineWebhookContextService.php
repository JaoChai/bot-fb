<?php

namespace App\Services\LineWebhook;

use App\Events\ConversationUpdated;
use App\Jobs\ProcessAggregatedMessages;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Services\AutoAssignmentService;
use App\Services\LINEService;
use App\Services\MessageAggregationService;
use App\Services\ProfilePictureService;
use App\Services\RateLimitService;
use App\Services\ResponseHoursService;
use App\Services\SmartAggregation\SmartAggregationAnalyzer;
use App\Services\SmartAggregation\UserTypingStats;
use App\Support\QueueRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LineWebhookContextService
{
    public function __construct(
        private readonly LINEService $line,
        private readonly RateLimitService $rateLimit,
        private readonly ResponseHoursService $responseHours,
        private readonly MessageAggregationService $aggregation,
        private readonly SmartAggregationAnalyzer $smartAnalyzer,
        private readonly UserTypingStats $userTypingStats,
        private readonly ProfilePictureService $profilePictureService,
        private readonly AutoAssignmentService $autoAssignment,
    ) {}

    /**
     * Resolve context for a LINE webhook event (Stage 2).
     *
     * Ports Transaction 1 block from ProcessLINEWebhook::processEvent (lines 212-466)
     * plus outside-hours check, findOrCreateCustomerProfile, and createNewConversation helpers.
     *
     * Sets ctx->profile, ctx->conversation, ctx->userMessage, ctx->gateDecision,
     * ctx->aggregationBuffered, and ctx->metadata as side-effects.
     * Returns void; caller reads ctx to decide next stage.
     */
    public function resolve(WebhookContext $ctx): void
    {
        $userId = $ctx->userId();
        if ($userId === null) {
            return;
        }

        $event = $ctx->event;

        $messageData = [
            'text' => $event['message']['text'] ?? null,
            'id' => $event['message']['id'] ?? null,
        ];

        $replyToken = $ctx->replyToken();
        $webhookEventId = $event['webhookEventId'] ?? null;
        $eventTimestamp = isset($event['timestamp']) ? (int) $event['timestamp'] : null;
        $isRedeliveryEvent = $event['deliveryContext']['isRedelivery'] ?? false;

        // --- Outside-hours check (Stage 1/2 boundary) ---
        // Must run after conversation lookup because handover conversations bypass the block.
        $responseHoursResult = $this->responseHours->checkResponseHours($ctx->bot);
        if (! $responseHoursResult['allowed']) {
            $existingConv = Conversation::where('bot_id', $ctx->bot->id)
                ->where('external_customer_id', $userId)
                ->where('channel_type', 'line')
                ->whereIn('status', ['active', 'handover'])
                ->first();

            if (! $existingConv || ! $existingConv->is_handover) {
                $this->dispatchOfflineMessage($ctx, $replyToken, $userId);
            }

            $ctx->gateDecision = GateDecision::OUTSIDE_HOURS;

            return;
        }

        // --- Aggregation settings (needed for loading indicator duration) ---
        $useAggregation = $this->aggregation->isEnabled($ctx->bot);
        $waitTimeMs = $useAggregation ? $this->aggregation->getWaitTimeMs($ctx->bot) : 0;

        // --- Loading indicator (before DB transaction) ---
        $loadingDuration = $useAggregation ? max(60, (int) ceil($waitTimeMs / 1000) + 30) : 30;
        $this->line->showLoadingIndicator($ctx->bot, $userId, $loadingDuration);

        // --- State collected inside transaction and used after ---
        $dispatchAggregation = false;
        $aggregationGroupId = null;
        $adaptiveWaitMs = null;

        // --- Transaction 1: Fast validation, dedup, save user message (~50ms) ---
        DB::transaction(function () use (
            $ctx,
            $userId,
            $messageData,
            $webhookEventId,
            $eventTimestamp,
            $isRedeliveryEvent,
            $useAggregation,
            &$waitTimeMs,
            &$dispatchAggregation,
            &$aggregationGroupId,
            &$adaptiveWaitMs,
        ) {
            // Find or create conversation (lockForUpdate prevents race condition)
            $existingConversation = Conversation::where('bot_id', $ctx->bot->id)
                ->where('external_customer_id', $userId)
                ->where('channel_type', 'line')
                ->whereIn('status', ['active', 'handover'])
                ->lockForUpdate()
                ->first();

            $isNewConversation = ! $existingConversation;
            $conversation = $existingConversation ?? $this->createNewConversation($ctx, $userId);

            $ctx->conversation = $conversation;
            $ctx->metadata['is_new_conversation'] = $isNewConversation;

            // --- Primary dedup: webhookEventId ---
            if ($webhookEventId && ($existingMsg = Message::where('conversation_id', $conversation->id)
                ->where('webhook_event_id', $webhookEventId)
                ->first())) {

                $botAlreadyResponded = Message::where('conversation_id', $conversation->id)
                    ->where('sender', 'bot')
                    ->where('created_at', '>=', $existingMsg->created_at)
                    ->exists();

                if (! $botAlreadyResponded && $ctx->bot->status === 'active' && ! $conversation->is_handover) {
                    Log::info('Retry recovery: generating bot response for existing user message', [
                        'conversation_id' => $conversation->id,
                        'webhook_event_id' => $webhookEventId,
                    ]);
                    $ctx->userMessage = $existingMsg;
                    $ctx->metadata['should_generate_response'] = true;
                } else {
                    Log::info('Duplicate webhook ignored (by webhook_event_id)', [
                        'conversation_id' => $conversation->id,
                        'webhook_event_id' => $webhookEventId,
                    ]);
                }

                return;
            }

            // --- Fallback dedup: external_message_id ---
            if ($messageData['id'] && ($existingMsg = Message::where('conversation_id', $conversation->id)
                ->where('external_message_id', $messageData['id'])
                ->first())) {

                $botAlreadyResponded = Message::where('conversation_id', $conversation->id)
                    ->where('sender', 'bot')
                    ->where('created_at', '>=', $existingMsg->created_at)
                    ->exists();

                if (! $botAlreadyResponded && $ctx->bot->status === 'active' && ! $conversation->is_handover) {
                    Log::info('Retry recovery: generating bot response for existing user message (by message_id)', [
                        'conversation_id' => $conversation->id,
                        'message_id' => $messageData['id'],
                    ]);
                    $ctx->userMessage = $existingMsg;
                    $ctx->metadata['should_generate_response'] = true;
                } else {
                    Log::info('Duplicate webhook ignored (by external_message_id)', [
                        'conversation_id' => $conversation->id,
                        'message_id' => $messageData['id'],
                    ]);
                }

                return;
            }

            // --- Save user message ---
            $userMessage = $conversation->messages()->create([
                'sender' => 'user',
                'content' => $messageData['text'],
                'type' => 'text',
                'external_message_id' => $messageData['id'],
                'webhook_event_id' => $webhookEventId,
                'is_redelivery' => $isRedeliveryEvent,
                'event_timestamp' => $eventTimestamp,
            ]);

            $ctx->userMessage = $userMessage;

            // --- Increment rate limit counters after successful message save ---
            $this->rateLimit->incrementCounters($ctx->bot, $userId);

            // --- Bot inactive check ---
            if ($ctx->bot->status !== 'active') {
                Log::info('Bot is inactive, message saved but skipping AI response', [
                    'bot_id' => $ctx->bot->id,
                    'conversation_id' => $conversation->id,
                    'status' => $ctx->bot->status,
                ]);
                $this->updateStatsForUserMessageOnly($ctx, $conversation, $userMessage->id);
                if ($isNewConversation) {
                    $ctx->bot->update([
                        'total_conversations' => DB::raw('total_conversations + 1'),
                    ]);
                }
                $ctx->metadata['bot_inactive'] = true;

                return;
            }

            // --- Handover check ---
            if ($conversation->is_handover) {
                Log::info('Conversation in handover mode, skipping AI response', [
                    'conversation_id' => $conversation->id,
                ]);
                $this->updateStatsForUserMessageOnly($ctx, $conversation, $userMessage->id);
                $ctx->metadata['handover'] = true;

                return;
            }

            // --- Aggregation decision ---
            if ($useAggregation) {
                $context = $this->aggregation->buildContext(
                    $conversation,
                    $messageData['text'] ?? '',
                    $userId
                );

                if ($this->smartAnalyzer->isSmartEnabled($ctx->bot) &&
                    $this->smartAnalyzer->shouldTriggerEarly($messageData['text'] ?? '', $context)) {
                    Log::info('Smart aggregation: early trigger activated', [
                        'conversation_id' => $conversation->id,
                        'message' => mb_substr($messageData['text'] ?? '', 0, 50),
                    ]);
                    $useAggregation = false; // fall through to immediate response
                } else {
                    if ($this->smartAnalyzer->isSmartEnabled($ctx->bot)) {
                        $waitTimeMs = $this->smartAnalyzer->calculateAdaptiveWaitTime($context);
                        Log::debug('Smart aggregation: using adaptive wait time', [
                            'conversation_id' => $conversation->id,
                            'wait_ms' => $waitTimeMs,
                            'avg_gap_ms' => $context->avgGapMs,
                        ]);
                    }

                    $aggregationResult = $this->aggregation->startOrContinueAggregation(
                        $conversation,
                        $userMessage,
                        $waitTimeMs
                    );

                    if ($aggregationResult === null) {
                        $useAggregation = false; // cache error, fall through
                    } else {
                        $aggregationGroupId = $aggregationResult['group_id'];
                        $dispatchAggregation = true;
                        $adaptiveWaitMs = $waitTimeMs;

                        // Per-user typing stats (Phase 4)
                        $settings = $ctx->bot->settings;
                        if ($settings?->smart_per_user_learning_enabled && $context->lastGapMs !== null) {
                            $this->userTypingStats->updateStats($ctx->bot->id, $userId, $context->lastGapMs);
                        }

                        Log::info('Message added to aggregation group', [
                            'conversation_id' => $conversation->id,
                            'group_id' => $aggregationGroupId,
                            'is_new_group' => $aggregationResult['is_new_group'],
                            'message_count' => $aggregationResult['message_count'],
                            'adaptive_wait_ms' => $waitTimeMs,
                        ]);

                        $this->updateStatsForUserMessageOnly($ctx, $conversation, $userMessage->id);

                        if ($isNewConversation) {
                            $ctx->bot->update([
                                'total_conversations' => DB::raw('total_conversations + 1'),
                            ]);
                        }

                        $ctx->aggregationBuffered = true;
                        $ctx->metadata['aggregation_group_id'] = $aggregationGroupId;
                        $ctx->metadata['adaptive_wait_ms'] = $adaptiveWaitMs;

                        return;
                    }
                }
            }

            // Immediate response path — Stage 3 will generate the AI reply
            $ctx->metadata['should_generate_response'] = true;
        });

        // --- Dispatch aggregation job outside transaction (no DB lock held) ---
        if ($dispatchAggregation && $aggregationGroupId && $ctx->conversation) {
            ProcessAggregatedMessages::dispatch(
                $ctx->bot,
                $ctx->conversation,
                $aggregationGroupId,
                $userId
            )->onQueue(QueueRouter::llmQueue())->delay(now()->addMilliseconds($adaptiveWaitMs ?? $waitTimeMs));
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Port of ProcessLINEWebhook::createNewConversation (lines 609-641).
     */
    private function createNewConversation(WebhookContext $ctx, string $userId): Conversation
    {
        $customerProfile = $this->findOrCreateCustomerProfile($ctx, $userId);
        $ctx->profile = $customerProfile;

        $autoHandover = $ctx->bot->auto_handover ?? false;

        $conversation = Conversation::create([
            'bot_id' => $ctx->bot->id,
            'customer_profile_id' => $customerProfile?->id,
            'external_customer_id' => $userId,
            'channel_type' => 'line',
            'status' => $autoHandover ? 'handover' : 'active',
            'is_handover' => $autoHandover,
            'current_flow_id' => $ctx->bot->default_flow_id,
            'message_count' => 0,
        ]);

        $assignedUser = $this->autoAssignment->assignConversation($ctx->bot, $conversation);
        if ($assignedUser) {
            $conversation->update(['assigned_user_id' => $assignedUser->id]);
        }

        broadcast(new ConversationUpdated($conversation, 'created'))->toOthers();

        return $conversation;
    }

    /**
     * Port of ProcessLINEWebhook::findOrCreateCustomerProfile (lines 1384-1420).
     */
    private function findOrCreateCustomerProfile(WebhookContext $ctx, string $userId): ?CustomerProfile
    {
        $lineProfile = $this->line->getProfile($ctx->bot, $userId);

        $profile = CustomerProfile::updateOrCreate(
            [
                'external_id' => $userId,
                'channel_type' => 'line',
            ],
            [
                'display_name' => $lineProfile['displayName'] ?? null,
                'picture_url' => $this->profilePictureService->downloadAndStore(
                    'line', $userId, $lineProfile['pictureUrl'] ?? null
                ),
                'last_interaction_at' => now(),
                'metadata' => [
                    'status_message' => $lineProfile['statusMessage'] ?? null,
                ],
            ]
        );

        if ($profile->wasRecentlyCreated) {
            $profile->update([
                'first_interaction_at' => now(),
                'interaction_count' => 1,
            ]);
        } else {
            $profile->increment('interaction_count');
        }

        return $profile;
    }

    /**
     * Port of ProcessLINEWebhook::updateStatsForUserMessageOnly (lines 646-660).
     */
    private function updateStatsForUserMessageOnly(WebhookContext $ctx, Conversation $conversation, int $lastMessageId): void
    {
        $conversation->update([
            'unread_count' => DB::raw('unread_count + 1'),
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => now(),
            'last_message_id' => $lastMessageId,
        ]);

        $ctx->bot->update([
            'total_messages' => DB::raw('total_messages + 1'),
            'last_active_at' => now(),
        ]);
    }

    /**
     * Port of ProcessLINEWebhook::handleOutsideResponseHours (lines 1349-1375).
     */
    private function dispatchOfflineMessage(WebhookContext $ctx, ?string $replyToken, string $userId): void
    {
        $message = $this->responseHours->getOfflineMessage($ctx->bot->settings);

        Log::info('Message received outside response hours', [
            'bot_id' => $ctx->bot->id,
            'has_offline_message' => $message !== null,
        ]);

        if (! $message) {
            return;
        }

        try {
            $retryKey = $this->line->generateRetryKey();
            $this->line->replyWithFallback($ctx->bot, $replyToken, $userId, [$message], $retryKey);
        } catch (\Exception $e) {
            Log::warning('Failed to send offline message', [
                'bot_id' => $ctx->bot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
