<?php

namespace App\Services\SmartAggregation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Per-user typing statistics service for Phase 4 personalization.
 * Learns individual user typing patterns to provide personalized wait times.
 */
class UserTypingStats
{
    private const CACHE_PREFIX = 'user_typing_stats';
    private const MIN_SAMPLES = 5;
    private const MAX_SAMPLES = 20;
    private const CACHE_TTL_DAYS = 30;

    /**
     * Update typing stats for a customer.
     */
    public function updateStats(int $botId, string $customerId, int $gapMs): void
    {
        // Skip invalid gaps
        if ($gapMs <= 0 || $gapMs > 60000) { // Max 60 seconds
            return;
        }

        $key = $this->getCacheKey($botId, $customerId);
        $stats = Cache::get($key, ['gaps' => [], 'count' => 0]);

        // Add new gap, keep last N
        $stats['gaps'][] = $gapMs;
        $stats['gaps'] = array_slice($stats['gaps'], -self::MAX_SAMPLES);
        $stats['count']++;

        // Update averages
        $stats['avg_gap_ms'] = array_sum($stats['gaps']) / count($stats['gaps']);
        $stats['std_dev'] = $this->calculateStdDev($stats['gaps'], $stats['avg_gap_ms']);
        $stats['updated_at'] = now()->timestamp;

        // Cache for 30 days
        Cache::put($key, $stats, 60 * 60 * 24 * self::CACHE_TTL_DAYS);

        Log::debug('Updated user typing stats', [
            'bot_id' => $botId,
            'customer_id' => $customerId,
            'gap_ms' => $gapMs,
            'avg_gap_ms' => $stats['avg_gap_ms'],
            'sample_count' => count($stats['gaps']),
        ]);
    }

    /**
     * Get recommended wait time for a customer.
     * Returns null if not enough data.
     */
    public function getRecommendedWaitTime(int $botId, string $customerId): ?int
    {
        $stats = Cache::get($this->getCacheKey($botId, $customerId));

        if (!$stats || count($stats['gaps'] ?? []) < self::MIN_SAMPLES) {
            return null; // Not enough data
        }

        // Use avg + 1 standard deviation (covers ~84% of cases)
        $avg = $stats['avg_gap_ms'];
        $stdDev = $stats['std_dev'] ?? 0;

        // Recommended wait = avg + stdDev, clamped to reasonable bounds
        $recommended = (int) ($avg + $stdDev);

        // Clamp between 500ms and 5000ms
        return max(500, min(5000, $recommended));
    }

    /**
     * Check if we have enough data to use personalized wait times.
     */
    public function shouldUsePersonalized(int $botId, string $customerId): bool
    {
        return $this->getRecommendedWaitTime($botId, $customerId) !== null;
    }

    /**
     * Get current stats for a customer (for debugging/display).
     */
    public function getStats(int $botId, string $customerId): ?array
    {
        return Cache::get($this->getCacheKey($botId, $customerId));
    }

    /**
     * Clear stats for a customer.
     */
    public function clearStats(int $botId, string $customerId): void
    {
        Cache::forget($this->getCacheKey($botId, $customerId));
    }

    /**
     * Generate cache key.
     * Includes botId to isolate stats per bot (same customer ID can exist across bots).
     */
    private function getCacheKey(int $botId, string $customerId): string
    {
        return self::CACHE_PREFIX . ':' . $botId . ':' . $customerId;
    }

    /**
     * Calculate standard deviation.
     */
    private function calculateStdDev(array $values, float $mean): float
    {
        if (count($values) < 2) {
            return 0;
        }

        $sumSquaredDiff = 0;
        foreach ($values as $value) {
            $sumSquaredDiff += pow($value - $mean, 2);
        }

        return sqrt($sumSquaredDiff / count($values));
    }
}
