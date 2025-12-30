<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MessageAggregationService
{
    /**
     * Cache key prefixes
     */
    private const CACHE_PREFIX = 'msg_agg';

    /**
     * Start or continue an aggregation group for a conversation.
     * Returns the group ID and whether this is a new group.
     * Returns null if cache is unavailable (fallback to immediate response).
     */
    public function startOrContinueAggregation(
        Conversation $conversation,
        Message $message,
        int $waitTimeMs
    ): ?array {
        $conversationId = $conversation->id;
        $groupKey = $this->getGroupIdKey($conversationId);
        $messagesKey = $this->getMessagesKey($conversationId);
        $timestampKey = $this->getTimestampKey($conversationId);

        // TTL: wait time + 10 seconds buffer (in seconds)
        $ttl = (int) ceil(($waitTimeMs + 10000) / 1000);

        // Use cache lock to prevent race conditions
        $lock = Cache::lock("msg_agg_lock:{$conversationId}", 5);

        try {
            $lock->block(3);

            // Check if there's an active aggregation group
            $existingGroupId = Cache::get($groupKey);
            $isNewGroup = !$existingGroupId;

            if ($isNewGroup) {
                // Create new aggregation group
                $groupId = (string) Str::uuid();
                Cache::put($groupKey, $groupId, $ttl);
                Cache::put($messagesKey, [$message->id], $ttl);
                Cache::put($timestampKey, now()->timestamp, $ttl);

                Log::debug('Started new aggregation group', [
                    'conversation_id' => $conversationId,
                    'group_id' => $groupId,
                    'message_id' => $message->id,
                    'wait_time_ms' => $waitTimeMs,
                ]);
            } else {
                // Add to existing group with NEW group_id to invalidate old jobs
                // This implements timer reset: old jobs will see group_id mismatch
                $groupId = (string) Str::uuid();  // New UUID to invalidate old delayed jobs
                $messageIds = Cache::get($messagesKey, []);
                $messageIds[] = $message->id;

                // Update with new group_id (timer reset mechanism)
                Cache::put($groupKey, $groupId, $ttl);
                Cache::put($messagesKey, $messageIds, $ttl);
                // Keep original timestamp for debugging
                $originalTimestamp = Cache::get($timestampKey, now()->timestamp);
                Cache::put($timestampKey, $originalTimestamp, $ttl);

                Log::debug('Added to existing aggregation group (timer reset)', [
                    'conversation_id' => $conversationId,
                    'old_group_id' => $existingGroupId,
                    'new_group_id' => $groupId,
                    'message_id' => $message->id,
                    'total_messages' => count($messageIds),
                ]);
            }

            return [
                'group_id' => $groupId,
                'is_new_group' => $isNewGroup,
                'message_count' => count(Cache::get($messagesKey, [])),
            ];
        } catch (\Exception $e) {
            Log::warning('Message aggregation cache error, falling back to immediate response', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
            return null; // Signal to use immediate response
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Check if the given group ID is still the active group for a conversation.
     * Used by delayed job to verify it should still process.
     */
    public function isActiveGroup(int $conversationId, string $groupId): bool
    {
        $currentGroupId = Cache::get($this->getGroupIdKey($conversationId));
        return $currentGroupId === $groupId;
    }

    /**
     * Get all message IDs in an aggregation group.
     */
    public function getMessageIds(int $conversationId): array
    {
        return Cache::get($this->getMessagesKey($conversationId), []);
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
     */
    public function clearAggregation(int $conversationId): void
    {
        $lock = Cache::lock("msg_agg_lock:{$conversationId}", 5);

        try {
            $lock->block(3);

            Cache::forget($this->getGroupIdKey($conversationId));
            Cache::forget($this->getMessagesKey($conversationId));
            Cache::forget($this->getTimestampKey($conversationId));

            Log::debug('Cleared aggregation data', [
                'conversation_id' => $conversationId,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to clear aggregation data', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
            // Don't rethrow - clearing is best-effort, data will expire anyway
        } finally {
            optional($lock)->release();
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
        return Cache::get($this->getTimestampKey($conversationId));
    }

    /**
     * Generate cache key for group ID.
     */
    private function getGroupIdKey(int $conversationId): string
    {
        return self::CACHE_PREFIX . ":{$conversationId}:group_id";
    }

    /**
     * Generate cache key for message IDs.
     */
    private function getMessagesKey(int $conversationId): string
    {
        return self::CACHE_PREFIX . ":{$conversationId}:messages";
    }

    /**
     * Generate cache key for started timestamp.
     */
    private function getTimestampKey(int $conversationId): string
    {
        return self::CACHE_PREFIX . ":{$conversationId}:started_at";
    }
}
