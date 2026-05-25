<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Swaps cache/session drivers from redis to database when Redis is unreachable.
 * Shared by the HTTP middleware and the queue worker hook so the swap logic
 * (independent guards, fail-open) stays single-sourced.
 */
class RedisFallbackSwitch
{
    private const DRIVER_REDIS = 'redis';

    private const DRIVER_FALLBACK = 'database';

    public function __construct(private RedisHealthGate $gate) {}

    /**
     * Re-evaluate Redis health before every queued job. Workers are long-lived
     * and never pass through the HTTP middleware, so without this their cache
     * locks + aggregation state would hit dead Redis during an outage.
     */
    public static function registerWorkerHook(): void
    {
        $cacheBaseline = config('cache.default');
        $sessionBaseline = config('session.driver');

        Queue::looping(function () use ($cacheBaseline, $sessionBaseline): void {
            app(self::class)->refresh($cacheBaseline, $sessionBaseline);
        });
    }

    public function apply(): void
    {
        $cache = config('cache.default');
        $session = config('session.driver');

        if ($cache !== self::DRIVER_REDIS && $session !== self::DRIVER_REDIS) {
            return;
        }
        if ($this->gate->isRedisUp()) {
            return;
        }

        if ($cache === self::DRIVER_REDIS) {
            config(['cache.default' => self::DRIVER_FALLBACK]);
        }
        if ($session === self::DRIVER_REDIS) {
            config(['session.driver' => self::DRIVER_FALLBACK]);
        }

        Log::warning('Redis unavailable — falling back to database for cache/session');
    }

    /**
     * Reset cache/session to their baseline drivers, then re-apply the fallback.
     * Used by the long-lived queue worker so each job re-evaluates Redis health
     * and automatically reverts to redis once it recovers.
     */
    public function refresh(string $cacheBaseline, string $sessionBaseline): void
    {
        config([
            'cache.default' => $cacheBaseline,
            'session.driver' => $sessionBaseline,
        ]);

        $this->apply();
    }
}
