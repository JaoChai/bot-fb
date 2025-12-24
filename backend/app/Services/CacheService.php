<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class CacheService
{
    /**
     * Default cache TTL in seconds (1 hour)
     */
    protected int $defaultTtl = 3600;

    /**
     * Cache a model by ID with automatic key generation
     */
    public function rememberModel(string $model, int|string $id, callable $callback, ?int $ttl = null): mixed
    {
        $key = $this->modelKey($model, $id);
        return Cache::remember($key, $ttl ?? $this->defaultTtl, $callback);
    }

    /**
     * Forget cached model
     */
    public function forgetModel(string $model, int|string $id): bool
    {
        return Cache::forget($this->modelKey($model, $id));
    }

    /**
     * Generate cache key for model
     */
    public function modelKey(string $model, int|string $id): string
    {
        $modelName = class_basename($model);
        return strtolower("model:{$modelName}:{$id}");
    }

    /**
     * Cache with tags (useful for bulk invalidation)
     */
    public function rememberTagged(array $tags, string $key, callable $callback, ?int $ttl = null): mixed
    {
        return Cache::tags($tags)->remember($key, $ttl ?? $this->defaultTtl, $callback);
    }

    /**
     * Flush all cache with specific tag
     */
    public function flushTag(string $tag): bool
    {
        return Cache::tags([$tag])->flush();
    }

    /**
     * Get Redis connection info for debugging
     */
    public function getRedisInfo(): array
    {
        try {
            $info = Redis::info();
            return [
                'connected' => true,
                'redis_version' => $info['redis_version'] ?? 'unknown',
                'used_memory' => $info['used_memory_human'] ?? 'unknown',
                'connected_clients' => $info['connected_clients'] ?? 0,
                'total_commands_processed' => $info['total_commands_processed'] ?? 0,
            ];
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get queue statistics
     */
    public function getQueueStats(): array
    {
        try {
            return [
                'default' => Redis::llen('queues:default') ?? 0,
                'high' => Redis::llen('queues:high') ?? 0,
                'low' => Redis::llen('queues:low') ?? 0,
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
