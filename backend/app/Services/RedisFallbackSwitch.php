<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Swaps cache/session drivers from redis (or the redis-backed `failover`
 * cache store) to database when Redis is unreachable. Shared by the HTTP
 * middleware and the queue worker hook so the swap logic (independent
 * guards, fail-open) stays single-sourced.
 */
class RedisFallbackSwitch
{
    /** Cache/session drivers that reach Redis and need the fallback. */
    private const REDIS_BACKED_DRIVERS = ['redis', 'failover'];

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

        if (! $this->isRedisBacked($cache) && ! $this->isRedisBacked($session)) {
            return;
        }
        if ($this->gate->isRedisUp()) {
            return;
        }

        if ($this->isRedisBacked($cache)) {
            config(['cache.default' => self::DRIVER_FALLBACK]);
        }
        if ($this->isRedisBacked($session)) {
            config(['session.driver' => self::DRIVER_FALLBACK]);
        }

        Log::warning('Redis unavailable — falling back to database for cache/session');
    }

    /**
     * Reset cache/session to their baseline drivers, then re-apply the fallback.
     * Used by the long-lived queue worker so each job re-evaluates Redis health
     * and automatically reverts to its baseline (redis or failover) once it
     * recovers.
     */
    public function refresh(string $cacheBaseline, string $sessionBaseline): void
    {
        config([
            'cache.default' => $cacheBaseline,
            'session.driver' => $sessionBaseline,
        ]);

        $this->apply();
    }

    private function isRedisBacked(string $driver): bool
    {
        return in_array($driver, self::REDIS_BACKED_DRIVERS, true);
    }
}
