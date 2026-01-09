<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

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
     * Simple cache remember (database driver compatible)
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        return Cache::remember($key, $ttl ?? $this->defaultTtl, $callback);
    }

    /**
     * Forget a cache key
     */
    public function forget(string $key): bool
    {
        return Cache::forget($key);
    }
}
