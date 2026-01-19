---
id: eloquent-001-eager-loading
title: Eager Loading Relationships
impact: CRITICAL
impactDescription: "Prevents N+1 query problems that cause severe performance degradation"
category: eloquent
tags: [eloquent, performance, query, relationships]
relatedRules: [gotcha-002-n-plus-one-queries, eloquent-002-query-scopes]
---

## Why This Matters

Eager loading fetches related records in a single query instead of one query per record. This is essential for performance - accessing relationships in loops without eager loading creates the N+1 query problem.

## Bad Example

```php
// Problem: Lazy loading causes N+1 queries
$bots = Bot::all(); // 1 query

foreach ($bots as $bot) {
    echo $bot->user->name;      // +N queries
    echo $bot->settings->theme; // +N queries
    foreach ($bot->flows as $flow) { // +N queries
        echo $flow->name;
    }
}
// Total: 1 + 3N queries (301 queries for 100 bots!)
```

**Why it's wrong:**
- Each relationship access triggers a query
- Queries grow linearly with record count
- Causes slow page loads
- Can exhaust database connections

## Good Example

```php
// Solution: Eager load with with()
$bots = Bot::with(['user', 'settings', 'flows'])->get();
// Only 4 queries total, regardless of bot count!

// Nested eager loading
$bots = Bot::with([
    'user',
    'settings',
    'flows.nodes' // Nested: flows and their nodes
])->get();

// Constrained eager loading (filter/limit related)
$bots = Bot::with([
    'conversations' => function ($query) {
        $query->where('created_at', '>', now()->subDays(7))
              ->latest()
              ->limit(5);
    }
])->get();

// Conditional eager loading
$bots = Bot::query()
    ->when($request->include_stats, function ($query) {
        $query->withCount(['conversations', 'messages']);
    })
    ->when($request->include_flows, function ($query) {
        $query->with('flows');
    })
    ->get();

// Select specific columns (memory optimization)
$bots = Bot::with(['user:id,name,email'])->get();
```

**Why it's better:**
- Fixed number of queries regardless of data size
- Predictable, scalable performance
- Relationships loaded in batch
- Can filter and limit related data

## Project-Specific Notes

**BotFacebook Common Patterns:**

```php
// BotService - Always eager load for lists
public function getUserBots(User $user)
{
    return $user->bots()
        ->with([
            'settings',
            'flows' => fn($q) => $q->where('is_default', true)
        ])
        ->withCount('conversations')
        ->latest()
        ->paginate(20);
}

// ConversationService - Load messages efficiently
public function getConversation(int $id)
{
    return Conversation::with([
        'bot.settings',
        'messages' => fn($q) => $q->latest()->limit(50),
        'messages.attachments'
    ])->findOrFail($id);
}

// API Resource - Load what you need
public function show(Bot $bot): BotResource
{
    return new BotResource(
        $bot->load(['settings', 'flows', 'user:id,name'])
    );
}
```

**Prevent Lazy Loading in Development:**
```php
// AppServiceProvider::boot()
Model::preventLazyLoading(!app()->isProduction());
```

## References

- [Laravel Eager Loading](https://laravel.com/docs/eloquent-relationships#eager-loading)
- Related rule: gotcha-002-n-plus-one-queries
