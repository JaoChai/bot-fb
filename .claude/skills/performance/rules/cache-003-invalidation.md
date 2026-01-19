---
id: cache-003-invalidation
title: Cache Invalidation Issues
impact: HIGH
impactDescription: "Stale data served due to improper cache invalidation"
category: cache
tags: [cache, invalidation, stale-data]
relatedRules: [cache-001-query-caching, cache-004-react-query-cache]
---

## Symptom

- Users seeing outdated data
- Updates not reflecting immediately
- "I just changed it but it shows old value"
- Inconsistent data across requests

## Root Cause

1. Not invalidating after updates
2. Wrong cache keys
3. Cache not cleared on related model changes
4. Race condition between update and invalidation
5. Forgetting to invalidate all related caches

## Diagnosis

### Quick Check

```php
// Check what's in cache
$value = Cache::get('bot:123');
Log::info('Cached value', ['value' => $value]);

// Check cache tags
Cache::tags(['bots'])->flush();  // Clear all bot caches
```

### Detailed Analysis

```php
// Add logging to cache operations
Cache::macro('rememberWithLog', function ($key, $ttl, $callback) {
    $exists = Cache::has($key);
    $value = Cache::remember($key, $ttl, $callback);
    Log::info('Cache', [
        'key' => $key,
        'hit' => $exists,
    ]);
    return $value;
});
```

## Measurement

```
Before: Stale data for minutes after update
Target: Fresh data within seconds of update
```

## Solution

### Fix Steps

1. **Use cache tags for grouped invalidation**
```php
// Store with tags
Cache::tags(['bots', "user:{$userId}"])
    ->put("bot:{$botId}", $bot, 600);

// Invalidate by tag
Cache::tags(['bots'])->flush();  // All bots
Cache::tags(["user:{$userId}"])->flush();  // User's data
```

2. **Invalidate in model events**
```php
// app/Observers/BotObserver.php
class BotObserver
{
    public function saved(Bot $bot): void
    {
        $this->clearBotCache($bot);
    }

    public function deleted(Bot $bot): void
    {
        $this->clearBotCache($bot);
    }

    private function clearBotCache(Bot $bot): void
    {
        // Clear specific bot
        Cache::forget("bot:{$bot->id}");

        // Clear lists
        Cache::tags(['bots', "user:{$bot->user_id}"])->flush();

        // Clear related caches
        Cache::forget("bot-stats:{$bot->id}");
        Cache::forget("bot-conversations:{$bot->id}");
    }
}
```

3. **Invalidate related models**
```php
// When message is created, invalidate conversation cache
class MessageObserver
{
    public function created(Message $message): void
    {
        // Invalidate conversation
        Cache::forget("conversation:{$message->conversation_id}");

        // Invalidate conversation list
        Cache::tags(["bot:{$message->conversation->bot_id}"])->flush();

        // Update stats cache
        Cache::forget("bot-stats:{$message->conversation->bot_id}");
    }
}
```

4. **Event-based invalidation**
```php
// Event
class BotUpdated
{
    public function __construct(public Bot $bot) {}
}

// Listener
class InvalidateBotCache
{
    public function handle(BotUpdated $event): void
    {
        $this->cacheService->invalidateBot($event->bot);
    }
}
```

5. **Cache versioning for complex invalidation**
```php
// Use version prefix
class BotCacheService
{
    public function getCacheKey(int $botId): string
    {
        $version = Cache::get("bot-version:{$botId}", 1);
        return "bot:v{$version}:{$botId}";
    }

    public function invalidate(int $botId): void
    {
        // Increment version instead of deleting
        Cache::increment("bot-version:{$botId}");
    }
}
```

### Invalidation Strategy Matrix

| Trigger | What to Invalidate |
|---------|-------------------|
| Bot updated | bot:{id}, bot lists, bot stats |
| Message created | conversation:{id}, bot stats |
| User settings changed | user:{id}, user preferences |
| Deploy | All config caches |
| Model change | All caches using that model |

## Verification

```php
// Test invalidation
$bot = Bot::find(1);
$cached = Cache::get("bot:{$bot->id}");
$bot->update(['name' => 'New Name']);
$afterUpdate = Cache::get("bot:{$bot->id}");

// $afterUpdate should be null or have new data
```

## Prevention

- Use cache tags for grouped data
- Register model observers
- Document cache dependencies
- Test cache invalidation
- Monitor stale data reports

## Project-Specific Notes

**BotFacebook Context:**
- Redis with tags support
- BotObserver: Clears bot + user caches
- MessageObserver: Clears conversation caches
- Deploy: Clear all caches via Artisan
