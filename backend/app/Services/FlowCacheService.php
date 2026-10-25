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
        try {
            return Cache::remember(
                $this->getDefaultFlowKey($botId),
                self::CACHE_TTL,
                fn () => Flow::where('bot_id', $botId)
                    ->where('is_default', true)
                    ->first()
            );
        } catch (\Throwable) {
            // Redis down (Wave 2 #4b): this read sits on the AI reply path —
            // degrade to the database instead of killing the reply.
            return Flow::where('bot_id', $botId)
                ->where('is_default', true)
                ->first();
        }
    }

    /**
     * Check if bot has any flows (cached).
     */
    public function hasFlows(int $botId): bool
    {
        try {
            return Cache::remember(
                $this->getHasFlowsKey($botId),
                self::CACHE_TTL,
                fn () => Flow::where('bot_id', $botId)->exists()
            );
        } catch (\Throwable) {
            return Flow::where('bot_id', $botId)->exists();
        }
    }

    /**
     * Invalidate all cache for a specific bot.
     * Call this when flows are created, updated, or deleted.
     */
    public function invalidateBot(int $botId): void
    {
        foreach ([$this->getDefaultFlowKey($botId), $this->getHasFlowsKey($botId)] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // Best-effort: entries carry a TTL.
            }
        }
    }

    /**
     * Invalidate only the default flow cache.
     * Call this when default flow status changes.
     */
    public function invalidateDefaultFlow(int $botId): void
    {
        try {
            Cache::forget($this->getDefaultFlowKey($botId));
        } catch (\Throwable) {
            // Best-effort: entries carry a TTL.
        }
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
