<?php

namespace App\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Bot;
use App\Models\Conversation;
use App\Services\AIService;
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
                ...(!app()->environment('production') ? ['trace' => $e->getTraceAsString()] : []),
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

        // DEBUG: Log all cache values at job start (use stderr for Railway visibility)
        $cachedGroupId = $aggregationService->getCurrentGroupId($conversationId);
        $cachedMessageIds = $aggregationService->getMessageIds($conversationId);
        $startedAt = $aggregationService->getStartedAt($conversationId);

        $cacheDriver = \Illuminate\Support\Facades\Cache::getDefaultDriver();
        $cacheStore = config('cache.default');
        $debugData = json_encode([
            'conversation_id' => $conversationId,
            'job_group_id' => $this->groupId,
            'cached_group_id' => $cachedGroupId,
            'group_id_match' => $cachedGroupId === $this->groupId,
            'cached_message_ids' => $cachedMessageIds,
            'message_count' => count($cachedMessageIds),
            'started_at' => $startedAt,
            'bot_id' => $this->bot->id,
            'cache_driver' => $cacheDriver,
            'cache_store' => $cacheStore,
        ]);
        error_log("[AGGREGATION_DEBUG] Job started: {$debugData}");

        // Verify this group is still active (no newer messages came in)
        if (!$aggregationService->isActiveGroup($conversationId, $this->groupId)) {
            $reason = $cachedGroupId === null ? 'cache_expired_or_missing' : 'newer_group_exists';
            error_log("[AGGREGATION_DEBUG] Early exit: group_id mismatch - reason: {$reason}, job_group_id: {$this->groupId}, cached_group_id: {$cachedGroupId}");
            return;
        }

        // Get merged content from all messages
        $mergedContent = $aggregationService->getMergedContent($conversationId);

        if (empty($mergedContent)) {
            $reason = empty($cachedMessageIds) ? 'message_ids_empty' : 'messages_not_found_in_db';
            error_log("[AGGREGATION_DEBUG] Early exit: no content - reason: {$reason}, message_ids: " . json_encode($cachedMessageIds));
            $aggregationService->clearAggregation($conversationId);
            return;
        }

        // Count messages for stats (reuse cached value from line 93)
        $messageCount = count($cachedMessageIds);

        Log::info('Processing aggregated messages', [
            'conversation_id' => $conversationId,
            'group_id' => $this->groupId,
            'message_count' => $messageCount,
            'merged_length' => strlen($mergedContent),
        ]);

        $botMessage = null;

        // Transaction 1: Fast validation only (~20ms) - refresh and check state
        $shouldGenerate = false;
        DB::transaction(function () use (&$shouldGenerate) {
            // Refresh conversation and bot to get latest state
            $this->conversation->refresh();
            $this->bot->refresh();

            // Check if bot was deactivated while waiting
            if ($this->bot->status !== 'active') {
                error_log("[AGGREGATION_DEBUG] Early exit: bot inactive - bot_id: {$this->bot->id}, status: {$this->bot->status}");
                Log::info('Bot is inactive, skipping AI response in aggregation', [
                    'bot_id' => $this->bot->id,
                    'conversation_id' => $this->conversation->id,
                    'status' => $this->bot->status,
                ]);
                return;
            }

            // Check if handover mode was enabled while waiting
            if ($this->conversation->is_handover) {
                error_log("[AGGREGATION_DEBUG] Early exit: handover mode enabled - conversation_id: {$this->conversation->id}");
                return;
            }

            $shouldGenerate = true;
        });

        // === API calls OUTSIDE transaction (no DB lock held) ===
        // AI generate (~2-3s) + LINE push (~200ms) no longer block concurrent requests
        if ($shouldGenerate) {
            // Safety check: skip if bot already responded after these messages
            if (!empty($cachedMessageIds)) {
                $earliestMessageId = min($cachedMessageIds);
                $earliestMessage = \App\Models\Message::find($earliestMessageId);

                if ($earliestMessage) {
                    $alreadyResponded = \App\Models\Message::where('conversation_id', $conversationId)
                        ->where('sender', 'bot')
                        ->where('created_at', '>=', $earliestMessage->created_at)
                        ->exists();

                    if ($alreadyResponded) {
                        Log::info('Safety net: bot already responded, skipping aggregation response', [
                            'conversation_id' => $conversationId,
                            'group_id' => $this->groupId,
                        ]);
                        $aggregationService->clearAggregation($conversationId);
                        return;
                    }
                }
            }

            // Acquire per-conversation response lock to prevent concurrent AI responses
            $responseLock = Cache::lock("ai_response:{$conversationId}", 30);

            if (!$responseLock->get()) {
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
                    return;
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
                return;
            }

            // Clean up re-dispatch counter on successful lock acquisition
            Cache::forget("ai_response_redispatch:{$conversationId}:{$this->groupId}");

            try {
                error_log("[AGGREGATION_DEBUG] Generating AI response - conversation_id: {$this->conversation->id}, content_length: " . strlen($mergedContent));

                // Generate AI response using merged content (no transaction lock held)
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

                // Send response to LINE using push message
                // (reply token has likely expired after waiting)
                if ($botMessage->content) {
                    if ($bubblesService->isEnabled($this->bot)) {
                        // Parse and send multiple bubbles
                        $bubbles = $bubblesService->parseIntoBubbles($botMessage->content, $this->bot);
                        // Use null for replyToken to force push message
                        $bubblesService->sendBubbles($this->bot, $this->externalUserId, null, $bubbles);
                    } else {
                        // Single message via push with retry key for idempotency
                        $paymentFlex = app(\App\Services\PaymentFlexService::class);
                        $transformed = $paymentFlex->tryConvertToFlex($botMessage->content);
                        $retryKey = $lineService->generateRetryKey();
                        $lineService->push($this->bot, $this->externalUserId, [$transformed], $retryKey);
                    }
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

                // Update stats with atomic DB::raw operations (no transaction needed)
                $this->updateStats($messageCount);
            } finally {
                $responseLock->release();
            }
        }

        // Clear aggregation data after successful processing
        $aggregationService->clearAggregation($conversationId);

        // Broadcast events after transaction
        // Refresh conversation to get actual DB values after DB::raw updates
        if ($botMessage) {
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
    }

    /**
     * Update conversation and bot statistics.
     */
    protected function updateStats(int $aggregatedMessageCount): void
    {
        // Update conversation stats
        // unread_count: +1 for bot response
        // message_count: already incremented for user messages, +1 for bot
        $this->conversation->update([
            'unread_count' => DB::raw('unread_count + 1'),
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => now(),
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
