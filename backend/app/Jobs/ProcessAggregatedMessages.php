<?php

namespace App\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Exceptions\OpenRouterException;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AIService;
use App\Services\Chat\AggregatedMessageDeliveryService;
use App\Services\Chat\ConversationContextService;
use App\Services\ConversationResponseLease;
use App\Services\MessageAggregationService;
use App\Services\ReplyPhaseTimingCollector;
use App\Services\ReplyPhaseTimingRecorder;
use App\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
     * Set to 200s to cover reasoning effort=high (primary 90s + fallback + intent).
     * Requires REDIS_QUEUE_RETRY_AFTER >= 210 in production (prod uses the Redis queue).
     */
    public int $timeout = 200;

    /**
     * Wave 2 timing: debounce the dispatcher intended (reported verbatim as
     * aggregation_wait_ms; queue_wait_ms is what came ON TOP of it).
     */
    public ?int $intendedDebounceMs = null;

    /**
     * Wave 2 timing: when the job instance was created (= queued).
     */
    public ?int $queuedAtMs = null;

    /**
     * Review round 1 (blocking #1): how many times this job has already
     * re-queued itself while another worker held the response lease.
     * Lives in the JOB PAYLOAD (serialized with the job), not in the cache,
     * so the wait chain survives a full Redis outage.
     */
    public int $leaseWaitAttempts = 0;

    /**
     * Lease-wait budget. Each re-dispatch waits at least MIN seconds, so the
     * chain provably outlasts the fence: 45 attempts × 5 s = 225 s >
     * ConversationResponseLease::TTL_SECONDS (210 s, which itself exceeds the
     * 200 s job timeout). While a holder is live the wait is capped at MAX,
     * so a normal reply lands within seconds of the holder releasing.
     */
    public const MAX_LEASE_WAIT_ATTEMPTS = 45;

    private const MIN_LEASE_RETRY_SECONDS = 5;

    private const MAX_LEASE_RETRY_SECONDS = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Bot $bot,
        public Conversation $conversation,
        public string $groupId,
        public string $externalUserId,
        ?int $intendedDebounceMs = null,
        int $leaseWaitAttempts = 0
    ) {
        $this->intendedDebounceMs = $intendedDebounceMs;
        $this->queuedAtMs = ReplyPhaseTimingCollector::clock();
        $this->leaseWaitAttempts = $leaseWaitAttempts;
    }

    /**
     * Execute the job.
     */
    public function handle(
        MessageAggregationService $aggregationService,
        AIService $aiService,
        AggregatedMessageDeliveryService $delivery,
        ?ConversationResponseLease $leases = null
    ): void {
        // Wave 2 timing: debounce reported verbatim, queue wait measured on top.
        $timing = ReplyPhaseTimingCollector::start($this->queuedAtMs);
        $timing->setDebounceMs($this->intendedDebounceMs ?? 0);

        try {
            $this->processAggregatedMessages(
                $aggregationService,
                $aiService,
                $delivery,
                $timing,
                $leases
            );
            $this->writeTimingRow($timing);
        } catch (\Exception $e) {
            $timing->setOutcome('failed');
            $this->writeTimingRow($timing);

            Log::error('Failed to process aggregated messages', [
                'bot_id' => $this->bot->id,
                'conversation_id' => $this->conversation->id,
                'group_id' => $this->groupId,
                'error' => $e->getMessage(),
                ...(! app()->environment('production') ? ['trace' => $e->getTraceAsString()] : []),
            ]);

            // The group is unsent: clear ONLY our own group id so a newer
            // group that arrived while we were failing survives untouched
            // (X1: the whole-row clear dropped it too).
            $aggregationService->releaseAnsweredGroup($this->conversation->id, $this->groupId, []);

            throw $e;
        }
    }

    /**
     * Wave 2 timing: write the row once, best-effort, only when generation
     * was actually attempted (early exits write nothing).
     */
    private function writeTimingRow(ReplyPhaseTimingCollector $timing): void
    {
        if (! $timing->attempted()) {
            return;
        }

        app(ReplyPhaseTimingRecorder::class)->write(
            $timing,
            $timing->toRow(
                $this->bot->id,
                $this->conversation->id,
                $this->job !== null ? $this->job->getJobId() : null,
                $this->groupId,
            )
        );
    }

    /**
     * Process the aggregated messages.
     */
    protected function processAggregatedMessages(
        MessageAggregationService $aggregationService,
        AIService $aiService,
        AggregatedMessageDeliveryService $delivery,
        ?ReplyPhaseTimingCollector $timing = null,
        ?ConversationResponseLease $leases = null
    ): void {
        $conversationId = $this->conversation->id;

        // Check if bot is active and not in handover mode
        if (! $this->shouldGenerate()) {
            return;
        }

        // Acquire the per-conversation response lease and attempt to generate.
        // DB-backed: the fence holds even while Redis is down.
        $leases ??= app(ConversationResponseLease::class);
        $leaseToken = $this->acquireResponseLease($leases);
        if ($leaseToken === null) {
            return;
        }

        $botMessage = null;
        $pluginSnapshot = null;

        try {
            // Everything below reads the group WHILE holding the fence: the
            // previous holder hands over the row (releaseAnsweredGroup)
            // before it releases the lease, so under the fence we always
            // observe the post-handover message ids (X1).
            $validationResult = $this->validateAndGetContent($aggregationService);
            if ($validationResult === null) {
                return;
            }

            ['mergedContent' => $mergedContent, 'messageCount' => $messageCount, 'cachedMessageIds' => $cachedMessageIds, 'turnBoundary' => $turnBoundary] = $validationResult;

            // Safety check: skip if a bot reply that is not older than this
            // turn already covers the group. Evaluated under the fence, so
            // the in-flight holder's half-written state cannot be misread.
            if ($this->hasAlreadyResponded($conversationId, $cachedMessageIds, $turnBoundary)) {
                $aggregationService->releaseAnsweredGroup($conversationId, $this->groupId, $cachedMessageIds);

                return;
            }

            // Auto-clear stale context before AI generates response
            app(ConversationContextService::class)->autoClearIfIdle($this->conversation);

            // Generate and deliver bot response
            $botMessage = $this->generateAndDeliver(
                $mergedContent,
                $messageCount,
                $aiService,
                $delivery,
                $cachedMessageIds,
                $timing
            );

            if ($botMessage) {
                $pluginSnapshot = $this->capturePluginSnapshot();
                $this->updateStats($messageCount, $botMessage->id);

                // X1 root fix: hand the group over BEFORE releasing the
                // fence — subtract the ids this reply answered, leaving
                // exactly the unanswered ones for the next turn.
                $aggregationService->releaseAnsweredGroup($conversationId, $this->groupId, $cachedMessageIds);
            }
        } finally {
            try {
                $leases->release($conversationId, $leaseToken);
            } catch (Throwable $e) {
                // Defense in depth (the service already swallows): a release
                // failure must never mask the primary exception nor skip the
                // post-send plugin work below.
                Log::warning('Response lease release threw past its guard', [
                    'conversation_id' => $conversationId,
                    'exception_type' => $e::class,
                ]);
            }
            if ($botMessage && $pluginSnapshot !== null) {
                try {
                    $this->dispatchFlowPlugins($botMessage, $pluginSnapshot);
                } catch (Throwable $e) {
                    Log::warning('Flow plugin dispatch failed after aggregation response', [
                        'bot_id' => $this->bot->id,
                        'conversation_id' => $this->conversation->id,
                        'message_id' => $botMessage->id,
                        'exception_type' => $e::class,
                        'exception_code' => $e->getCode(),
                    ]);
                }
            }
        }

        // (Row already handed over by releaseAnsweredGroup above, while the
        // fence was still held — no whole-row clear here: it would delete a
        // newer group that arrived during generation, the X1 bug.)

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
        $turnBoundary = $aggregationService->getGroupUpdatedAt($conversationId);

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
            // Only these ids are unanswerable — a newer group in the row
            // keeps whatever we did not touch (X1).
            $aggregationService->releaseAnsweredGroup($conversationId, $this->groupId, $cachedMessageIds);

            return null;
        }

        return [
            'mergedContent' => $mergedContent,
            'messageCount' => count($cachedMessageIds),
            'cachedMessageIds' => $cachedMessageIds,
            'turnBoundary' => $turnBoundary,
        ];
    }

    /**
     * Check if bot is active and conversation is not in handover mode.
     */
    private function shouldGenerate(): bool
    {
        // Read-only refresh of latest bot/conversation state — no writes or locks,
        // so no transaction needed (dropping it avoids per-job BEGIN/COMMIT round-trips).
        $this->conversation->refresh();
        $this->bot->refresh();

        // Check if bot was deactivated while waiting
        if ($this->bot->status !== 'active') {
            Log::debug('[Aggregation] Early exit: bot inactive', [
                'bot_id' => $this->bot->id,
                'status' => $this->bot->status,
                'conversation_id' => $this->conversation->id,
            ]);

            return false;
        }

        // Check if handover mode was enabled while waiting
        if ($this->conversation->is_handover) {
            Log::debug('[Aggregation] Early exit: handover mode enabled', [
                'conversation_id' => $this->conversation->id,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Check if a bot reply that is not older than this turn already covers
     * the group.
     *
     * A reply counts only when it is newer than the latest remaining message
     * AND STRICTLY newer than the row's last mutation: the mutation was the
     * handover save (releaseAnsweredGroup) that bumped updated_at past the
     * just-written reply, so a reply at or before the mutation answered an
     * EARLIER turn and must not suppress the remaining messages (X1: it did,
     * and msg B was lost a second time even without the whole-row clear).
     * Second-precision timestamps cannot order sub-second events, so
     * same-second counts as "row mutated after the reply" — the conservative
     * direction is answering, not dropping the customer's message.
     */
    private function hasAlreadyResponded(int $conversationId, array $cachedMessageIds, ?int $turnBoundary = null): bool
    {
        if (empty($cachedMessageIds)) {
            return false;
        }

        $latestMessageId = max($cachedMessageIds);
        $latestTimestamp = Message::where('id', $latestMessageId)->value('created_at');

        if (! $latestTimestamp) {
            return false;
        }

        $alreadyResponded = Message::where('conversation_id', $conversationId)
            ->where('sender', 'bot')
            ->where('created_at', '>=', $latestTimestamp);

        if ($turnBoundary !== null) {
            // created_at column values are stored in the app timezone (this
            // project runs +07:00); the boundary must be formatted in the
            // same zone or the comparison shifts by the UTC offset.
            $alreadyResponded->where('created_at', '>', Carbon::createFromTimestamp($turnBoundary, config('app.timezone')));
        }

        $alreadyResponded = $alreadyResponded->exists();

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
     * Acquire the per-conversation response lease (DB-backed, survives Redis
     * outages) to prevent concurrent AI responses.
     * Returns the lease token on success, null when another worker holds the
     * conversation's response fence.
     *
     * The waiting side never depends on Redis (review round 1): the attempt
     * budget rides in this job's payload and the remaining fence time is read
     * from the database, so a cache outage cannot orphan a pending group.
     */
    private function acquireResponseLease(ConversationResponseLease $leases): ?string
    {
        $conversationId = $this->conversation->id;

        $token = $leases->acquire($conversationId);

        if ($token !== null) {
            return $token;
        }

        // The fence is held. Bound the wait WITHOUT the cache: every re-dispatch
        // waits at least MIN_LEASE_RETRY_SECONDS, so the chain covers the full
        // lease TTL (45 × 5 s = 225 s > 210 s) — a holder legitimately generating
        // for minutes, or a stale lease after a hard kill, can never outlive it.
        if ($this->leaseWaitAttempts >= self::MAX_LEASE_WAIT_ATTEMPTS) {
            // Review round 1 (blocking #1): the budget running out must NEVER
            // clear unsent work. Leave the authoritative row so the customer's
            // next message continues this group with a fresh job + full budget.
            Log::warning('Aggregation: lease-wait budget exhausted, group kept for the next message', [
                'conversation_id' => $conversationId,
                'group_id' => $this->groupId,
                'attempts' => $this->leaseWaitAttempts,
            ]);

            return null;
        }

        $remaining = $leases->secondsRemaining($conversationId);
        $delaySeconds = max(
            self::MIN_LEASE_RETRY_SECONDS,
            min($remaining + 2, self::MAX_LEASE_RETRY_SECONDS)
        );

        Log::info('Aggregation: response lease held, re-dispatching', [
            'conversation_id' => $conversationId,
            'group_id' => $this->groupId,
            'attempt' => $this->leaseWaitAttempts + 1,
            'lease_seconds_remaining' => $remaining,
            'redispatch_delay_seconds' => $delaySeconds,
        ]);

        ProcessAggregatedMessages::dispatch(
            $this->bot,
            $this->conversation,
            $this->groupId,
            $this->externalUserId,
            $this->intendedDebounceMs,
            $this->leaseWaitAttempts + 1
        )->onConnection(QueueRouter::connection())->onQueue(QueueRouter::llmQueue())->delay(now()->addSeconds($delaySeconds));

        return null;
    }

    /**
     * Generate AI response and deliver to channel.
     */
    private function generateAndDeliver(
        string $mergedContent,
        int $messageCount,
        AIService $aiService,
        AggregatedMessageDeliveryService $delivery,
        array $currentTurnMessageIds = [],
        ?ReplyPhaseTimingCollector $timing = null
    ): ?Message {
        Log::debug('[Aggregation] Generating AI response', [
            'conversation_id' => $this->conversation->id,
            'content_length' => strlen($mergedContent),
        ]);

        // Generate AI response using merged content
        // exclude ข้อความของ turn นี้ออกจาก history — mergedContent เป็นตัวแทน turn ปัจจุบันอยู่แล้ว
        try {
            $timing?->markAttempted();
            $result = $aiService->generateResponse(
                $this->bot,
                $mergedContent,
                $this->conversation,
                excludeMessageIds: $currentTurnMessageIds,
                timing: $timing
            );
        } catch (OpenRouterException $e) {
            // Unlike the synchronous webhook path, this job calls generateResponse() (which
            // does not catch) with tries=1 — so a failure here would send the customer NOTHING.
            // Send the same friendly Thai fallback instead of silence.
            Log::error('[Aggregation] AI generation failed — sending friendly fallback', [
                'conversation_id' => $this->conversation->id,
                'error' => $e->getMessage(),
            ]);

            $botMessage = $this->conversation->messages()->create([
                'sender' => 'bot',
                'content' => $aiService->getErrorMessage($e),
                'type' => 'text',
            ]);
            $timing?->recordBotMessage($botMessage);
            ReplyPhaseTimingCollector::measure($timing, 'send_ms', fn () => $delivery->deliver($this->bot, $this->conversation, $botMessage, $this->externalUserId));

            return $botMessage;
        }

        // Save bot response
        $botMessage = $this->conversation->messages()->create([
            'sender' => 'bot',
            'content' => $result['content'],
            'type' => 'text',
            'model_used' => $result['model'],
            'prompt_tokens' => $result['usage']['prompt_tokens'],
            'completion_tokens' => $result['usage']['completion_tokens'],
            'cost' => $result['cost'],
            'metadata' => array_filter([
                ...($result['rag_metadata'] ?? []),
                'order_payload' => $result['order_payload'] ?? null,
                // เก็บไว้ตรวจย้อนหลังว่า guard ไปแก้คำตอบอะไรของบอทบ้าง
                'stock_guard' => $result['stock_guard'] ?? null,
                'support_router' => $result['support_router'] ?? null,
            ]) ?: null,
        ]);

        // Send response to channel
        if ($botMessage->content) {
            $timing?->recordBotMessage($botMessage);
            ReplyPhaseTimingCollector::measure($timing, 'send_ms', fn () => $delivery->deliver($this->bot, $this->conversation, $botMessage, $this->externalUserId));
        }

        return $botMessage;
    }

    /**
     * Capture the flow and exact five-message context used by the old inline plugin call.
     */
    private function capturePluginSnapshot(): array
    {
        $contextMessageIds = $this->conversation->messages()
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->pluck('id')
            ->reverse()
            ->values()
            ->map(static fn ($messageId): int => (int) $messageId)
            ->all();

        return [
            'flowId' => $this->conversation->current_flow_id ?? $this->bot->default_flow_id,
            'contextMessageIds' => $contextMessageIds,
        ];
    }

    protected function dispatchFlowPlugins(Message $botMessage, array $pluginSnapshot): void
    {
        ExecuteFlowPlugins::dispatch(
            $this->bot->id,
            $this->conversation->id,
            $botMessage->id,
            $pluginSnapshot['flowId'],
            $pluginSnapshot['contextMessageIds'],
        )->onConnection(QueueRouter::asyncConnection());
    }

    /**
     * Broadcast response events to connected clients.
     */
    private function broadcastResponse(Message $botMessage): void
    {
        // Refresh conversation to get actual DB values after DB::raw updates
        $this->conversation->refresh();
        $conversationData = [
            'id' => $this->conversation->id,
            'message_count' => $this->conversation->message_count,
            'last_message_at' => $this->conversation->last_message_at?->toISOString(),
            'unread_count' => $this->conversation->unread_count,
        ];

        try {
            broadcast(new MessageSent($botMessage, $conversationData))->toOthers();
            broadcast(new ConversationUpdated($this->conversation, 'message_received'))->toOthers();
        } catch (Throwable $e) {
            // Post-delivery realtime UI only (Wave 2 #4b): the customer's
            // reply is already sent — a Redis outage must not turn a
            // delivered reply into a failed job (retry churn, duplicate-send
            // risk, lost timing row).
            Log::warning('Broadcast after reply failed; reply already delivered', [
                'conversation_id' => $this->conversation->id,
                'exception_type' => $e::class,
            ]);
        }
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
    public function failed(Throwable $exception): void
    {
        Log::error('ProcessAggregatedMessages job failed', [
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'group_id' => $this->groupId,
            'error' => $exception->getMessage(),
        ]);
    }
}
