<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAggregationGroup;
use App\Services\SmartAggregation\AggregationContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MessageAggregationService
{
    /**
     * Cache key prefixes (legacy format, kept for cut-over read-through:
     * jobs queued by the old release must still find their cache-only group).
     */
    private const CACHE_PREFIX = 'msg_agg';

    /**
     * Start or continue an aggregation group for a conversation.
     * Returns the group ID and whether this is a new group.
     * Returns null if the database write fails (fallback to immediate response).
     *
     * The `message_aggregation_groups` row is authoritative: a Redis drop can
     * no longer expire unsent work. When no row exists yet (one-deploy
     * cut-over), a group written by the old release is adopted from the legacy
     * cache keys instead of being dropped.
     */
    public function startOrContinueAggregation(
        Conversation $conversation,
        Message $message,
        int $waitTimeMs
    ): ?array {
        try {
            return DB::transaction(function () use ($conversation, $message, $waitTimeMs): array {
                // Mutate groups under the conversation row lock so two
                // webhooks/jobs cannot interleave group state.
                DB::table('conversations')->where('id', $conversation->id)->lockForUpdate()->first();

                $group = MessageAggregationGroup::where('conversation_id', $conversation->id)
                    ->lockForUpdate()
                    ->first();

                $isNewGroup = false;

                if ($group === null) {
                    $legacy = $this->readLegacyCacheGroup($conversation->id);

                    $group = new MessageAggregationGroup([
                        'conversation_id' => $conversation->id,
                        'group_id' => $legacy['group_id'] ?? (string) Str::uuid(),
                        'message_ids' => $legacy['message_ids'] ?? [],
                        'started_at' => isset($legacy['started_at'])
                            ? Carbon::createFromTimestamp((int) $legacy['started_at'])
                            : now(),
                    ]);
                    $isNewGroup = ($legacy === null || $legacy['group_id'] === null);
                }

                // Timer reset: every added message takes a NEW group id, so
                // older delayed jobs supersede themselves.
                $groupId = (string) Str::uuid();
                $messageIds = $group->message_ids ?? [];
                $messageIds[] = $message->id;

                $group->group_id = $groupId;
                $group->message_ids = $messageIds;
                $group->due_at = now()->addMilliseconds($waitTimeMs);
                $group->save();

                if ($isNewGroup) {
                    Log::debug('Started new aggregation group', [
                        'conversation_id' => $conversation->id,
                        'group_id' => $groupId,
                        'message_id' => $message->id,
                        'wait_time_ms' => $waitTimeMs,
                    ]);
                } else {
                    Log::debug('Added to existing aggregation group (timer reset)', [
                        'conversation_id' => $conversation->id,
                        'new_group_id' => $groupId,
                        'message_id' => $message->id,
                        'total_messages' => count($messageIds),
                    ]);
                }

                return [
                    'group_id' => $groupId,
                    'is_new_group' => $isNewGroup,
                    'message_count' => count($messageIds),
                ];
            });
        } catch (Throwable $e) {
            Log::warning('Message aggregation database error, falling back to immediate response', [
                'conversation_id' => $conversation->id,
                'exception_type' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return null; // Signal to use immediate response
        }
    }

    /**
     * Check if the given group ID is still the active group for a conversation.
     * Used by delayed job to verify it should still process.
     */
    public function isActiveGroup(int $conversationId, string $groupId): bool
    {
        $group = $this->authoritativeGroup($conversationId);

        if ($group !== null) {
            return $group->group_id === $groupId;
        }

        // Cut-over read-through: an old-release job with no row yet.
        $legacy = $this->readLegacyCacheGroup($conversationId);

        return $legacy !== null && $legacy['group_id'] === $groupId;
    }

    /**
     * Get the current group ID (for debugging).
     */
    public function getCurrentGroupId(int $conversationId): ?string
    {
        $group = $this->authoritativeGroup($conversationId);

        if ($group !== null) {
            return $group->group_id;
        }

        return $this->readLegacyCacheGroup($conversationId)['group_id'] ?? null;
    }

    /**
     * Get all message IDs in an aggregation group.
     */
    public function getMessageIds(int $conversationId): array
    {
        $group = $this->authoritativeGroup($conversationId);

        if ($group !== null) {
            return $group->message_ids ?? [];
        }

        return $this->readLegacyCacheGroup($conversationId)['message_ids'] ?? [];
    }

    /**
     * Get merged content from all messages in the aggregation group.
     * Messages are joined with newlines.
     */
    public function getMergedContent(int $conversationId): string
    {
        $messageIds = $this->getMessageIds($conversationId);

        if (empty($messageIds)) {
            return '';
        }

        $messages = Message::whereIn('id', $messageIds)
            ->orderBy('created_at', 'asc')
            ->get();

        $contents = $messages->pluck('content')->filter()->toArray();

        return implode("\n", $contents);
    }

    /**
     * Clear aggregation data for a conversation.
     * Should be called after successfully processing the group.
     *
     * Best-effort and never throws: a failure here must not mask the caller's
     * primary exception nor trigger duplicate sends (the response lease and
     * hasAlreadyResponded() guard those). Unsent rows are never expired by
     * time; only explicit clears remove them.
     */
    public function clearAggregation(int $conversationId): void
    {
        try {
            MessageAggregationGroup::where('conversation_id', $conversationId)->delete();
        } catch (Throwable $e) {
            Log::warning('Failed to clear aggregation group row', [
                'conversation_id' => $conversationId,
                'exception_type' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }

        $this->forgetLegacyKeys($conversationId);

        Log::debug('Cleared aggregation data', [
            'conversation_id' => $conversationId,
        ]);
    }

    /**
     * End the caller's turn while preserving any newer pending messages.
     *
     * X1 root fix: clearAggregation() after the holder's reply deleted the
     * WHOLE row — including a newer group that arrived while the holder was
     * generating. That newer message was then lost (its job found no group),
     * and hasAlreadyResponded() would suppress it again: the just-written
     * reply is newer than the newer message. Instead this leaves exactly the
     * unanswered ids in the row. The save bumps the row's updated_at past
     * the reply that was just written, which is what lets
     * hasAlreadyResponded() tell "a reply from an earlier turn" apart from
     * "a reply covering this group".
     *
     * Deleting when the row still belongs to the caller keeps
     * clearAggregation()'s old meaning for every non-overlap completion;
     * deleting an emptied newer row keeps one row per conversation.
     *
     * Best-effort, never throws. Callers must run this BEFORE releasing the
     * response lease, so the next job can only observe the post-handover row
     * while holding the fence.
     */
    public function releaseAnsweredGroup(int $conversationId, string $groupId, array $answeredMessageIds): void
    {
        try {
            DB::transaction(
                fn () => $this->handOverAnsweredIds($conversationId, $groupId, $answeredMessageIds)
            );
        } catch (Throwable $e) {
            Log::warning('Failed to hand over aggregation group after reply', [
                'conversation_id' => $conversationId,
                'exception_type' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The core of releaseAnsweredGroup() WITHOUT its own transaction and
     * WITHOUT the best-effort catch: it throws, and it joins the CALLER's
     * transaction.
     *
     * X1 round 2 (review #105): the aggregation job persists the bot reply
     * and the handover in ONE database transaction, so a throw or a process
     * kill around delivery leaves either NO reply or a handed-over row —
     * never a reply whose ids are still pending in the row (the waiter
     * would re-answer them once the lease expires). Callers that only want
     * best-effort behaviour must use releaseAnsweredGroup().
     */
    public function handOverAnsweredIds(int $conversationId, string $groupId, array $answeredMessageIds): void
    {
        DB::table('conversations')->where('id', $conversationId)->lockForUpdate()->first();

        $group = MessageAggregationGroup::where('conversation_id', $conversationId)
            ->lockForUpdate()
            ->first();

        if ($group === null) {
            return;
        }

        if ($group->group_id === $groupId) {
            $group->delete();
            $this->forgetLegacyKeys($conversationId);
            Log::debug('Cleared aggregation data', [
                'conversation_id' => $conversationId,
            ]);

            return;
        }

        $current = $group->message_ids ?? [];
        $remaining = array_values(array_diff($current, $answeredMessageIds));

        if ($remaining === $current) {
            return; // none of our ids are in the newer group
        }

        if (empty($remaining)) {
            $group->delete();
            Log::debug('Cleared emptied newer aggregation group', [
                'conversation_id' => $conversationId,
            ]);

            return;
        }

        // The save also bumps updated_at — hasAlreadyResponded() uses
        // it as the turn boundary: a bot reply created before this
        // save answered an earlier turn and must not suppress the
        // remaining messages.
        $group->message_ids = $remaining;
        $group->save();
        Log::debug('Handed unanswered messages to the newer aggregation group', [
            'conversation_id' => $conversationId,
            'remaining_count' => count($remaining),
        ]);
    }

    private function forgetLegacyKeys(int $conversationId): void
    {
        foreach ($this->legacyKeys($conversationId) as $key) {
            try {
                Cache::forget($key);
            } catch (Throwable) {
                // Best-effort: legacy keys expire on their own.
            }
        }
    }

    /**
     * Check if aggregation is enabled for a bot.
     */
    public function isEnabled(Bot $bot): bool
    {
        $settings = $bot->settings;

        return $settings && $settings->wait_multiple_bubbles_enabled;
    }

    /**
     * Get wait time in milliseconds for a bot.
     */
    public function getWaitTimeMs(Bot $bot): int
    {
        $settings = $bot->settings;

        return $settings ? ($settings->wait_multiple_bubbles_ms ?? 1500) : 1500;
    }

    /**
     * Get the timestamp when aggregation started.
     */
    public function getStartedAt(int $conversationId): ?int
    {
        $group = $this->authoritativeGroup($conversationId);

        if ($group !== null) {
            return $group->started_at?->timestamp;
        }

        $startedAt = $this->readLegacyCacheGroup($conversationId)['started_at'] ?? null;

        return $startedAt !== null ? (int) $startedAt : null;
    }

    /**
     * Unix timestamp of the pending row's last mutation, or null when the
     * group lives only in the legacy cache.
     *
     * Turn boundary for hasAlreadyResponded(): a bot reply created BEFORE
     * the row was last mutated answered an earlier turn — the mutation was
     * releaseAnsweredGroup() handing over the messages it did not answer.
     */
    public function getGroupUpdatedAt(int $conversationId): ?int
    {
        $group = $this->authoritativeGroup($conversationId);

        return $group !== null ? $group->updated_at?->timestamp : null;
    }

    /**
     * Get elapsed time since aggregation started (in milliseconds).
     */
    public function getElapsedMs(int $conversationId): int
    {
        $startedAt = $this->getStartedAt($conversationId);
        if (! $startedAt) {
            return 0;
        }

        return (int) ((now()->timestamp - $startedAt) * 1000);
    }

    /**
     * Build context for smart aggregation decisions.
     */
    public function buildContext(
        Conversation $conversation,
        string $currentMessage,
        ?string $customerId = null
    ): AggregationContext {
        $conversationId = $conversation->id;
        $messageIds = $this->getMessageIds($conversationId);
        $bot = $conversation->bot;

        // Get recent messages with timestamps
        $recentMessages = [];
        if (! empty($messageIds)) {
            $recentMessages = Message::whereIn('id', $messageIds)
                ->orderBy('created_at', 'asc')
                ->get(['id', 'content', 'created_at'])
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'content' => $m->content,
                    'created_at' => $m->created_at->toIso8601String(),
                ])
                ->toArray();
        }

        // Calculate gaps between messages
        $gaps = [];
        for ($i = 1; $i < count($recentMessages); $i++) {
            $prev = Carbon::parse($recentMessages[$i - 1]['created_at']);
            $curr = Carbon::parse($recentMessages[$i]['created_at']);
            $gaps[] = $curr->diffInMilliseconds($prev);
        }

        return new AggregationContext(
            conversationId: $conversationId,
            recentMessages: $recentMessages,
            messageCount: count($messageIds) + 1, // +1 for current message being added
            elapsedMs: $this->getElapsedMs($conversationId),
            lastGapMs: ! empty($gaps) ? end($gaps) : null,
            avgGapMs: count($gaps) > 0 ? array_sum($gaps) / count($gaps) : 0,
            baseWaitMs: $this->getWaitTimeMs($bot),
            bot: $bot,
            customerId: $customerId,
        );
    }

    /**
     * The DB row is authoritative; null when none exists yet.
     */
    private function authoritativeGroup(int $conversationId): ?MessageAggregationGroup
    {
        return MessageAggregationGroup::where('conversation_id', $conversationId)->first();
    }

    /**
     * Legacy cache-only group written by the old release. Returns null when
     * the cache has no group (or cache errors — a Redis outage then simply
     * means "nothing pending" until the next DB-backed write).
     */
    private function readLegacyCacheGroup(int $conversationId): ?array
    {
        try {
            $groupId = Cache::get($this->getGroupIdKey($conversationId));

            if (! $groupId) {
                return null;
            }

            return [
                'group_id' => $groupId,
                'message_ids' => Cache::get($this->getMessagesKey($conversationId), []),
                'started_at' => Cache::get($this->getTimestampKey($conversationId)),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Legacy cache keys (old release format) for read-through and cleanup.
     *
     * @return list<string>
     */
    private function legacyKeys(int $conversationId): array
    {
        return [
            $this->getGroupIdKey($conversationId),
            $this->getMessagesKey($conversationId),
            $this->getTimestampKey($conversationId),
        ];
    }

    /**
     * Generate cache key for group ID.
     */
    private function getGroupIdKey(int $conversationId): string
    {
        return self::CACHE_PREFIX.":{$conversationId}:group_id";
    }

    /**
     * Generate cache key for message IDs.
     */
    private function getMessagesKey(int $conversationId): string
    {
        return self::CACHE_PREFIX.":{$conversationId}:messages";
    }

    /**
     * Generate cache key for started timestamp.
     */
    private function getTimestampKey(int $conversationId): string
    {
        return self::CACHE_PREFIX.":{$conversationId}:started_at";
    }
}
