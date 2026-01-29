<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Centralized cache management for conversation-related data.
 *
 * Consolidates cache key management and invalidation logic that was
 * previously scattered across ConversationService and TagService.
 */
class ConversationCacheService
{
    /**
     * Cache TTL constants (in seconds)
     */
    public const STATS_TTL = 30;
    public const COUNTS_TTL = 30;
    public const TAGS_TTL = 60;

    /**
     * Get the cache key for conversation stats.
     */
    public function getStatsKey(int $botId): string
    {
        return "bot:{$botId}:conversation:stats";
    }

    /**
     * Get the cache key for conversation counts (all_counts).
     */
    public function getCountsKey(int $botId): string
    {
        return "bot:{$botId}:conversation:all_counts";
    }

    /**
     * Get the cache key for conversation tags.
     */
    public function getTagsKey(int $botId): string
    {
        return "bot:{$botId}:conversation:tags";
    }

    /**
     * Invalidate stats cache for a bot.
     */
    public function invalidateStats(int $botId): void
    {
        Cache::forget($this->getStatsKey($botId));
    }

    /**
     * Invalidate counts cache for a bot.
     */
    public function invalidateCounts(int $botId): void
    {
        Cache::forget($this->getCountsKey($botId));
    }

    /**
     * Invalidate tags cache for a bot.
     */
    public function invalidateTags(int $botId): void
    {
        Cache::forget($this->getTagsKey($botId));
    }

    /**
     * Invalidate all conversation-related caches for a bot.
     */
    public function invalidateAll(int $botId): void
    {
        $this->invalidateStats($botId);
        $this->invalidateCounts($botId);
        $this->invalidateTags($botId);
    }

    /**
     * Remember stats in cache with callback.
     *
     * @param int $botId
     * @param callable $callback
     * @return mixed
     */
    public function rememberStats(int $botId, callable $callback): mixed
    {
        return Cache::remember(
            $this->getStatsKey($botId),
            self::STATS_TTL,
            $callback
        );
    }

    /**
     * Remember counts in cache with callback.
     *
     * @param int $botId
     * @param callable $callback
     * @return mixed
     */
    public function rememberCounts(int $botId, callable $callback): mixed
    {
        return Cache::remember(
            $this->getCountsKey($botId),
            self::COUNTS_TTL,
            $callback
        );
    }

    /**
     * Remember tags in cache with callback.
     *
     * @param int $botId
     * @param callable $callback
     * @return mixed
     */
    public function rememberTags(int $botId, callable $callback): mixed
    {
        return Cache::remember(
            $this->getTagsKey($botId),
            self::TAGS_TTL,
            $callback
        );
    }
}
