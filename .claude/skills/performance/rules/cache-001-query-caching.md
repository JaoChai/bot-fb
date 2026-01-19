---
id: cache-001-query-caching
title: Database Query Caching
impact: HIGH
impactDescription: "Repeated database queries that should be cached"
category: cache
tags: [cache, database, laravel, redis]
relatedRules: [query-002-slow-queries, cache-002-http-caching]
---

## Symptom

- Same queries executed repeatedly
- High database load for read-heavy operations
- Slow API responses for frequently accessed data
- Database CPU spikes

## Root Cause

1. No query result caching
2. Cache not used for expensive queries
3. Missing cache layer for config/settings
4. Over-caching (too long TTL)
5. No cache warming

## Diagnosis

### Quick Check

```php
// Check query count
DB::enableQueryLog();
$response = $this->getJson('/api/bots');
Log::info('Queries: ' . count(DB::getQueryLog()));

// Check cache usage
Cache::get('key'); // Returns null if not cached
```

### Detailed Analysis

```php
// Log all queries with timing
DB::listen(function ($query) {
    Log::info('Query', [
        'sql' => $query->sql,
        'time' => $query->time . 'ms',
        'bindings' => $query->bindings,
    ]);
});
```

## Measurement

```
Before: 20+ DB queries per request
Target: < 5 DB queries (rest from cache)
```

## Solution

### Fix Steps

1. **Cache expensive queries**
```php
// Cache with remember
$models = Cache::remember('llm-models', 3600, function () {
    return LlmModel::with('provider')
        ->where('active', true)
        ->get();
});

// With tags (requires Redis)
$bots = Cache::tags(['bots', "user:{$userId}"])
    ->remember("bots:user:{$userId}", 600, function () use ($userId) {
        return Bot::where('user_id', $userId)->get();
    });
```

2. **Invalidate on update**
```php
// In Model observer or event
class BotObserver
{
    public function saved(Bot $bot): void
    {
        Cache::tags(['bots', "user:{$bot->user_id}"])->flush();
    }

    public function deleted(Bot $bot): void
    {
        Cache::tags(['bots', "user:{$bot->user_id}"])->flush();
    }
}
```

3. **Cache config/settings**
```php
// AppServiceProvider
public function boot(): void
{
    // Cache config for 1 hour in production
    if (app()->environment('production')) {
        Cache::remember('app-settings', 3600, function () {
            return Setting::all()->pluck('value', 'key')->toArray();
        });
    }
}
```

4. **Use query cache for search**
```php
// Cache search results
public function search(string $query): Collection
{
    $cacheKey = 'search:' . md5($query);

    return Cache::remember($cacheKey, 300, function () use ($query) {
        return $this->semanticSearch($query);
    });
}
```

5. **Warm cache on deploy**
```php
// Command: php artisan cache:warm
class WarmCacheCommand extends Command
{
    protected $signature = 'cache:warm';

    public function handle(): void
    {
        // Warm frequently accessed data
        Cache::remember('llm-models', 3600, fn() => LlmModel::all());
        Cache::remember('active-prompts', 3600, fn() => SystemPrompt::active()->get());
    }
}
```

### Cache Strategy Matrix

| Data Type | TTL | Invalidation |
|-----------|-----|--------------|
| Config/settings | 1 hour | On change |
| User data | 10 min | On update |
| Search results | 5 min | TTL only |
| API responses | 1 min | Stale-while-revalidate |
| Static content | 24 hours | On deploy |

## Verification

```php
// Test cache hit rate
$start = microtime(true);
$data = Cache::get('key');
$hit = microtime(true) - $start;
Log::info("Cache lookup: {$hit}ms");

// Should be < 1ms for cache hit
// vs > 100ms for DB query
```

## Prevention

- Identify slow/repeated queries
- Add caching to expensive operations
- Set appropriate TTLs
- Implement cache invalidation
- Monitor cache hit rates

## Project-Specific Notes

**BotFacebook Context:**
- Cache driver: Redis (Railway)
- LLM models: Cache 1 hour
- Bot settings: Cache 10 min
- Search results: Cache 5 min
- User preferences: Cache 10 min
