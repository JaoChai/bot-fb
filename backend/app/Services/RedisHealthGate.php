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

        $up = Cache::store('file')->remember(self::CACHE_KEY, $ttl, function (): bool {
            return $this->probe();
        });

        return $this->requestMemo = $up;
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
