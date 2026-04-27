<?php

namespace App\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Bot;
use App\Models\Conversation;
use App\Services\AIService;
use App\Services\Chat\ConversationContextService;
use App\Services\LINEService;
use App\Services\MessageAggregationService;
use App\Services\MultipleBubblesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessAggregatedMessages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     * Set to 1 - if it fails, user can send another message.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     * Set to 150s to allow for slow LLM models like gpt-5-mini.
     * Requires DB_QUEUE_RETRY_AFTER >= 180 in production.
     */
    public int $timeout = 150;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Bot $bot,
        public Conversation $conversation,
        public string $groupId,
        public string $externalUserId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        MessageAggregationService $aggregationService,
        AIService $aiService,
        LINEService $lineService,
        MultipleBubblesService $bubblesService
    ): void {
        try {
            $this->processAggregatedMessages(
                $aggregationService,
                $aiService,
                $lineService,
                $bubblesService
            );
        } catch (\Exception $e) {
            Log::error('Failed to process aggregated messages', [
                'bot_id' => $this->bot->id,
                'conversation_id' => $this->conversation->id,
                'group_id' => $this->groupId,
                'error' => $e->getMessage(),
                ...(! app()->environment('production') ? ['trace' => $e->getTraceAsString()] : []),
            ]);

            // Clear aggregation on failure so user can try again
            $aggregationService->clearAggregation($this->conversation->id);

            throw $e;
        }
    }

    /**
     * Process the aggregated messages.
     */
    protected function processAggregatedMessages(
        MessageAggregationService $aggregationService,
        AIService $aiService,
        LINEService $lineService,
        MultipleBubblesService $bubblesService
    ): void {
        $conversationId = $this->conversation->id;

        // Validate and get content (with early exit checks)
        $validationResult = $this->validateAndGetContent($aggregationService);
        if ($validationResult === null) {
            return;
        }

        ['mergedContent' => $mergedContent, 'messageCount' => $messageCount, 'cachedMessageIds' => $cachedMessageIds] = $validationResult;

        // Check if bot is active and not in handover mode
        if (! $this->shouldGenerate()) {
            return;
        }

        // Safety check: skip if bot already responded
        if ($this->hasAlreadyResponded($conversationId, $cachedMessageIds)) {
            $aggregationService->clearAggregation($conversationId);

            return;
        }

        // Acquire lock and attempt to generate response
        $responseLock = $this->acquireResponseLock($aggregationService);
        if ($responseLock === null) {
            return;
        }

        try {
            // Auto-clear stale context before AI generates response
            app(ConversationContextService::class)->autoClearIfIdle($this->conversation);

            // Generate and deliver bot response
            $botMessage = $this->generateAndDeliver(
                $mergedContent,
                $messageCount,
                $aiService,
                $lineService,
                $bubblesService
            );

            if ($botMessage) {
                $this->updateStats($messageCount, $botMessage->id);
            }
        } finally {
            $responseLock->release();
        }

        // Clear aggregation data after successful processing
        $aggregationService->clearAggregation($conversationId);

        // Broadcast events after processing
        if (isset($botMessage) && $botMessage) {
            $this->broadcastResponse($botMessage);
        }
    }

    /**
     * Validate and retrieve merged content from cached messages.
     * Returns array with mergedContent, messageCount, cachedMessageIds or null if validation fails.
     */
    private function validateAndGetContent(MessageAggregationService $aggregationService): ?array
    {
        $conversationId = $this->conversation->id;

        // Get all cache values at job start
        $cachedGroupId = $aggregationService->getCurrentGroupId($conversationId);
        $cachedMessageIds = $aggregationService->getMessageIds($conversationId);
        $startedAt = $aggregationService->getStartedAt($conversationId);

        Log::debug('[Aggregation] Job started', [
            'conversation_id' => $conversationId,
            'job_group_id' => $this->groupId,
            'cached_group_id' => $cachedGroupId,
            'group_id_match' => $cachedGroupId === $this->groupId,
            'cached_message_ids' => $cachedMessageIds,
            'message_count' => count($cachedMessageIds),
            'started_at' => $startedAt,
            'bot_id' => $this->bot->id,
        ]);

        // Verify this group is still active (no newer messages came in)
        if (! $aggregationService->isActiveGroup($conversationId, $this->groupId)) {
            $reason = $cachedGroupId === null ? 'cache_expired_or_missing' : 'newer_group_exists';
            Log::debug('[Aggregation] Early exit: group_id mismatch', [
                'reason' => $reason,
                'job_group_id' => $this->groupId,
                'cached_group_id' => $cachedGroupId,
                'conversation_id' => $conversationId,
            ]);

            return null;
        }

        // Get merged content from all messages
        $mergedContent = $aggregationService->getMergedContent($conversationId);

        if (empty($mergedContent)) {
            $reason = empty($cachedMessageIds) ? 'message_ids_empty' : 'messages_not_found_in_db';
            Log::debug('[Aggregation] Early exit: no content', [
                'reason' => $reason,
                'message_ids' => $cachedMessageIds,
                'conversation_id' => $conversationId,
            ]);
            $aggregationService->clearAggregation($conversationId);

            return null;
        }

        return [
            'mergedContent' => $mergedContent,
            'messageCount' => count($cachedMessageIds),
            'cachedMessageIds' => $cachedMessageIds,
        ];
    }

    /**
     * Check if bot is active and conversation is not in handover mode.
     */
    private function shouldGenerate(): bool
    {
        $shouldGenerate = false;

        DB::transaction(function () use (&$shouldGenerate) {
            // Refresh conversation and bot to get latest state
            $this->conversation->refresh();
            $this->bot->refresh();

            // Check if bot was deactivated while waiting
            if ($this->bot->status !== 'active') {
                Log::debug('[Aggregation] Early exit: bot inactive', [
                    'bot_id' => $this->bot->id,
                    'status' => $this->bot->status,
                    'conversation_id' => $this->conversation->id,
                ]);

                return;
            }

            // Check if handover mode was enabled while waiting
            if ($this->conversation->is_handover) {
                Log::debug('[Aggregation] Early exit: handover mode enabled', [
                    'conversation_id' => $this->conversation->id,
                ]);

                return;
            }

            $shouldGenerate = true;
        });

        return $shouldGenerate;
    }

    /**
     * Check if bot already responded after the latest message in this group.
     */
    private function hasAlreadyResponded(int $conversationId, array $cachedMessageIds): bool
    {
        if (empty($cachedMessageIds)) {
            return false;
        }

        $latestMessageId = max($cachedMessageIds);
        $latestTimestamp = \App\Models\Message::where('id', $latestMessageId)->value('created_at');

        if (! $latestTimestamp) {
            return false;
        }

        $alreadyResponded = \App\Models\Message::where('conversation_id', $conversationId)
            ->where('sender', 'bot')
            ->where('created_at', '>=', $latestTimestamp)
            ->exists();

        if ($alreadyResponded) {
            Log::info('Safety net: bot already responded after latest message, skipping aggregation response', [
                'conversation_id' => $conversationId,
                'group_id' => $this->groupId,
                'latest_message_id' => $latestMessageId,
                'skipped_message_ids' => $cachedMessageIds,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Acquire per-conversation response lock to prevent concurrent AI responses.
     * Returns lock on success, null if lock could not be acquired or max retries exceeded.
     */
    private function acquireResponseLock(MessageAggregationService $aggregationService): ?\Illuminate\Contracts\Cache\Lock
    {
        $conversationId = $this->conversation->id;
        $responseLock = Cache::lock("ai_response:{$conversationId}", 30);

        if (! $responseLock->get()) {
            // Limit re-dispatch attempts to prevent infinite loop
            $redispatchKey = "ai_response_redispatch:{$conversationId}:{$this->groupId}";
            $attempts = (int) Cache::get($redispatchKey, 0);

            if ($attempts >= 3) {
                Log::warning('Aggregation: max re-dispatch attempts reached', [
                    'conversation_id' => $conversationId,
                    'group_id' => $this->groupId,
                    'attempts' => $attempts,
                ]);
                Cache::forget($redispatchKey);
                $aggregationService->clearAggregation($conversationId);

                return null;
            }

            Cache::put($redispatchKey, $attempts + 1, now()->addMinutes(5));

            Log::info('Aggregation: response lock held, re-dispatching', [
                'conversation_id' => $conversationId,
                'attempt' => $attempts + 1,
            ]);
            // Re-dispatch with shorter delay
            ProcessAggregatedMessages::dispatch(
                $this->bot, $this->conversation, $this->groupId, $this->externalUserId
            )->onQueue('webhooks')->delay(now()->addSeconds(5));

            return null;
        }

        // Clean up re-dispatch counter on successful lock acquisition
        Cache::forget("ai_response_redispatch:{$conversationId}:{$this->groupId}");

        return $responseLock;
    }

    /**
     * Generate AI response and deliver to channel.
     */
    private function generateAndDeliver(
        string $mergedContent,
        int $messageCount,
        AIService $aiService,
        LINEService $lineService,
        MultipleBubblesService $bubblesService
    ): ?\App\Models\Message {
        Log::debug('[Aggregation] Generating AI response', [
            'conversation_id' => $this->conversation->id,
            'content_length' => strlen($mergedContent),
        ]);

        // Generate AI response using merged content
        $result = $aiService->generateResponse(
            $this->bot,
            $mergedContent,
            $this->conversation
        );

        // Save bot response
        $botMessage = $this->conversation->messages()->create([
            'sender' => 'bot',
            'content' => $result['content'],
            'type' => 'text',
            'model_used' => $result['model'],
            'prompt_tokens' => $result['usage']['prompt_tokens'],
            'completion_tokens' => $result['usage']['completion_tokens'],
            'cost' => $result['cost'],
            'metadata' => $result['rag_metadata'] ?? null,
        ]);

        // Send response to channel
        if ($botMessage->content) {
            $this->deliverToChannel($botMessage, $lineService, $bubblesService);
        }

        // Execute flow plugins (e.g., Telegram notifications)
        if ($botMessage) {
            try {
                app(\App\Services\FlowPluginService::class)
                    ->executePlugins($this->bot, $this->conversation, $botMessage);
            } catch (\Exception $e) {
                Log::warning('Flow plugin execution failed in aggregation', [
                    'conversation_id' => $this->conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $botMessage;
    }

    /**
     * Deliver bot message to the appropriate channel (Flex, Bubbles, or plain text).
     */
    private function deliverToChannel(
        \App\Models\Message $botMessage,
        LINEService $lineService,
        MultipleBubblesService $bubblesService
    ): void {
        $paymentFlex = app(\App\Services\PaymentFlexService::class);
        $transformed = $paymentFlex->tryConvertToFlex($botMessage->content, $this->conversation);

        if (is_array($transformed)) {
            // Flex detected on full text → send as single message
            $retryKey = $lineService->generateRetryKey();
            $lineService->push($this->bot, $this->externalUserId, [$transformed], $retryKey);
        } elseif ($bubblesService->isEnabled($this->bot)) {
            // No Flex match → normal bubble flow
            $bubbles = $bubblesService->parseIntoBubbles($botMessage->content, $this->bot);
            $bubblesService->sendBubbles($this->bot, $this->externalUserId, null, $bubbles, $this->conversation);
        } else {
            // No Flex, no bubbles → send as plain text
            $retryKey = $lineService->generateRetryKey();
            $lineService->push($this->bot, $this->externalUserId, [$botMessage->content], $retryKey);
        }
    }

    /**
     * Broadcast response events to connected clients.
     */
    private function broadcastResponse(\App\Models\Message $botMessage): void
    {
        // Refresh conversation to get actual DB values after DB::raw updates
        $this->conversation->refresh();
        $conversationData = [
            'id' => $this->conversation->id,
            'message_count' => $this->conversation->message_count,
            'last_message_at' => $this->conversation->last_message_at?->toISOString(),
            'unread_count' => $this->conversation->unread_count,
        ];
        broadcast(new MessageSent($botMessage, $conversationData))->toOthers();
        broadcast(new ConversationUpdated($this->conversation, 'message_received'))->toOthers();
    }

    /**
     * Update conversation and bot statistics.
     */
    protected function updateStats(int $aggregatedMessageCount, int $lastMessageId): void
    {
        // Update conversation stats
        // unread_count: +1 for bot response
        // message_count: already incremented for user messages, +1 for bot
        $this->conversation->update([
            'unread_count' => DB::raw('unread_count + 1'),
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => now(),
            'last_message_id' => $lastMessageId,
        ]);

        // Update bot stats
        // total_messages: +1 for bot response only (user messages already counted)
        $this->bot->update([
            'total_messages' => DB::raw('total_messages + 1'),
            'last_active_at' => now(),
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessAggregatedMessages job failed', [
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'group_id' => $this->groupId,
            'error' => $exception->getMessage(),
        ]);
    }
}
