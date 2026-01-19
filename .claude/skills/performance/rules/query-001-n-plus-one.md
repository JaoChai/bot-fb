---
id: query-001-n-plus-one
title: N+1 Query Problem
impact: CRITICAL
impactDescription: "Exponential database queries causing severe slowdowns"
category: query
tags: [database, n+1, eager-loading, laravel]
relatedRules: [query-002-slow-queries, cache-001-query-caching]
---

## Symptom

- API response times increase with data size
- Debugbar shows many similar queries
- Log shows repeated SELECT statements
- Database CPU spikes during list operations

## Root Cause

1. Accessing relationships in loops without eager loading
2. Blade templates accessing unloaded relationships
3. API resources accessing relationships without whenLoaded()
4. Missing ->with() on queries

## Diagnosis

### Quick Check

```php
// Enable N+1 detection in development
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    Model::preventLazyLoading(!app()->isProduction());
}
```

### Detailed Analysis

```php
// Check query count
DB::enableQueryLog();

$bots = Bot::all();
foreach ($bots as $bot) {
    echo $bot->user->name;  // N+1!
}

$queries = DB::getQueryLog();
Log::info('Query count: ' . count($queries));  // Will show N+1 queries
```

## Measurement

```
Before: N queries for N records (100 records = 101 queries)
Target: 2 queries regardless of records (1 for parent, 1 for relation)
```

## Solution

### Fix Steps

1. **Use eager loading**
```php
// Before (N+1)
$bots = Bot::all();
foreach ($bots as $bot) {
    echo $bot->user->name;
}

// After (2 queries)
$bots = Bot::with('user')->get();
foreach ($bots as $bot) {
    echo $bot->user->name;
}
```

2. **Nested eager loading**
```php
// Multiple levels
$bots = Bot::with([
    'user',
    'conversations.messages',
    'knowledgeBase.documents',
])->get();
```

3. **Constrained eager loading**
```php
// Load only what you need
$bots = Bot::with([
    'conversations' => fn($q) => $q->latest()->limit(10),
])->get();
```

4. **In API Resources**
```php
// app/Http/Resources/BotResource.php
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        // Only include if loaded
        'user' => new UserResource($this->whenLoaded('user')),
        'conversations_count' => $this->whenCounted('conversations'),
    ];
}
```

5. **WithCount for aggregates**
```php
// Instead of $bot->conversations->count()
$bots = Bot::withCount('conversations')->get();
// Access as $bot->conversations_count
```

### Code Patterns

```php
// Controller pattern
class BotController extends Controller
{
    public function index(): JsonResponse
    {
        $bots = Bot::query()
            ->with(['user:id,name,avatar', 'activePlatforms'])
            ->withCount(['conversations', 'knowledgeBase'])
            ->where('user_id', auth()->id())
            ->paginate(20);

        return BotResource::collection($bots);
    }
}
```

## Verification

```php
// Test query count
DB::enableQueryLog();

$response = $this->getJson('/api/bots');

$queryCount = count(DB::getQueryLog());
$this->assertLessThan(10, $queryCount, "Too many queries: {$queryCount}");
```

## Prevention

- Enable `preventLazyLoading()` in development
- Review query logs in code review
- Use Laravel Debugbar to monitor queries
- Add query count assertions to tests
- Document required eager loads in docblocks

## Project-Specific Notes

**BotFacebook Context:**
- N+1 detection: Enabled in AppServiceProvider
- Common relations to eager load: user, conversations, platforms
- Use withCount for: conversations, messages, documents
