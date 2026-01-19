---
id: gotcha-002-n-plus-one-queries
title: N+1 Query Problem
impact: CRITICAL
impactDescription: "Prevents exponential database queries that cause severe performance degradation"
category: gotcha
tags: [query, performance, eloquent, n+1]
relatedRules: [eloquent-001-eager-loading, eloquent-002-query-scopes]
---

## Why This Matters

The N+1 query problem occurs when you fetch a collection of records (1 query) and then access a relationship for each record in a loop (N queries). This can turn a single page load from 2 queries into 102+ queries, causing severe performance issues.

## Bad Example

```php
// Problem: N+1 queries - 1 query for bots + N queries for settings
$bots = Bot::all(); // 1 query

foreach ($bots as $bot) {
    echo $bot->settings->theme; // N queries (one per bot)
}

// If 100 bots: 101 queries total!
```

**Why it's wrong:**
- Each `$bot->settings` triggers a new database query
- Performance degrades linearly with data size
- Can cause page timeouts
- Database connection exhaustion under load

## Good Example

```php
// Solution: Eager loading with with()
$bots = Bot::with('settings')->get(); // 2 queries total

foreach ($bots as $bot) {
    echo $bot->settings->theme; // No additional queries
}

// Or with specific columns
$bots = Bot::with('settings:id,bot_id,theme')->get();

// Nested relationships
$bots = Bot::with(['settings', 'flows.nodes'])->get();

// Conditional eager loading
$bots = Bot::when($includeConversations, function ($query) {
    $query->with(['conversations' => fn($q) => $q->latest()->limit(5)]);
})->get();
```

**Why it's better:**
- Only 2 queries regardless of bot count
- Relationships loaded in batch
- Predictable performance
- Scalable

## Project-Specific Notes

**BotFacebook Common Patterns:**

```php
// BotService - Always eager load settings
public function getUserBots(User $user)
{
    return $user->bots()
        ->with(['settings', 'flows' => fn($q) => $q->where('is_default', true)])
        ->withCount('conversations')
        ->latest()
        ->paginate(20);
}

// ConversationService - Load messages with user
public function getConversation(int $id)
{
    return Conversation::with(['messages.sender', 'bot.settings'])
        ->findOrFail($id);
}
```

**Detection Command:**
```bash
# Enable query logging in development
DB::enableQueryLog();
// ... your code
dd(DB::getQueryLog());
```

## References

- [Laravel Eager Loading](https://laravel.com/docs/eloquent-relationships#eager-loading)
- Related rule: eloquent-001-eager-loading
