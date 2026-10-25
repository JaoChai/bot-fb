<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class RedisHealthGate
{
    private const CACHE_KEY = 'redis_health:up';

    private ?bool $requestMemo = null;

    public function isRedisUp(): bool
    {
        if ($this->requestMemo !== null) {
            return $this->requestMemo;
        }

        $ttl = (int) config('redis-fallback.health_ttl', 10);

        try {
            $up = Cache::store('file')->remember(self::CACHE_KEY, $ttl, fn (): bool => $this->probe());
        } catch (\Throwable $e) {
            $up = $this->probe();
        }

        return $this->requestMemo = $up;
    }

    /**
     * Record a Redis failure immediately, instead of waiting for the next
     * health-check slot. Marks this request down and shares the verdict with
     * later requests through the same file-store key the probe uses, so the
     * fallback switch engages without another dead-Redis probe.
     *
     * Fail-open: if the file store write throws, the request memo still holds.
     */
    public function markDown(): void
    {
        $this->requestMemo = false;

        $ttl = (int) config('redis-fallback.health_ttl', 10);

        try {
            Cache::store('file')->put(self::CACHE_KEY, false, $ttl);
        } catch (\Throwable) {
            return;
        }
    }

    private function probe(): bool
    {
        try {
            return Redis::connection()->ping() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
