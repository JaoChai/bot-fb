<?php

namespace App\Services;

use App\Models\Flow;
use Illuminate\Support\Facades\Cache;

/**
 * Cache service for Flow-related queries.
 * Reduces database load for frequently accessed flow data.
 */
class FlowCacheService
{
    /**
     * Cache TTL in seconds (30 minutes).
     */
    private const CACHE_TTL = 1800;

    /**
     * Get the default flow for a bot with caching.
     */
    public function getDefaultFlow(int $botId): ?Flow
    {
        return Cache::remember(
            $this->getDefaultFlowKey($botId),
            self::CACHE_TTL,
            fn () => Flow::where('bot_id', $botId)
                ->where('is_default', true)
                ->first()
        );
    }

    /**
     * Check if bot has any flows (cached).
     */
    public function hasFlows(int $botId): bool
    {
        return Cache::remember(
            $this->getHasFlowsKey($botId),
            self::CACHE_TTL,
            fn () => Flow::where('bot_id', $botId)->exists()
        );
    }

    /**
     * Invalidate all cache for a specific bot.
     * Call this when flows are created, updated, or deleted.
     */
    public function invalidateBot(int $botId): void
    {
        Cache::forget($this->getDefaultFlowKey($botId));
        Cache::forget($this->getHasFlowsKey($botId));
    }

    /**
     * Invalidate only the default flow cache.
     * Call this when default flow status changes.
     */
    public function invalidateDefaultFlow(int $botId): void
    {
        Cache::forget($this->getDefaultFlowKey($botId));
    }

    /**
     * Get cache key for default flow.
     */
    private function getDefaultFlowKey(int $botId): string
    {
        return "bot:{$botId}:default_flow";
    }

    /**
     * Get cache key for has-flows check.
     */
    private function getHasFlowsKey(int $botId): string
    {
        return "bot:{$botId}:has_flows";
    }
}
