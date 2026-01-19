---
id: perf-003-over-fetching
title: Avoid Over-Fetching Data
impact: MEDIUM
impactDescription: "Fetching unnecessary data wastes memory and bandwidth"
category: perf
tags: [performance, database, eloquent, select]
relatedRules: [perf-001-n-plus-one, perf-004-pagination]
---

## Why This Matters

Fetching all columns when you only need a few wastes memory and bandwidth. Large text fields or blobs can make queries 10-100x heavier.

## Bad Example

```php
// Fetching everything
$bots = Bot::all(); // Gets all columns including large system_prompt

// Loading full models for simple task
$names = Bot::all()->pluck('name'); // Loaded everything just for names

// Getting counts inefficiently
$total = Bot::all()->count(); // Loads all records just to count

// Eager loading too much
$bots = Bot::with('conversations.messages')->get();
// Loads potentially thousands of messages
```

**Why it's wrong:**
- Memory wasted on unused data
- Slow network transfer
- Database does extra work
- Can cause memory exhaustion

## Good Example

```php
// Select only needed columns
$bots = Bot::select(['id', 'name', 'slug', 'is_active'])->get();

// Pluck directly from database
$names = Bot::pluck('name'); // SELECT name FROM bots

// Count at database level
$total = Bot::count(); // SELECT COUNT(*) FROM bots

// Limit eager loading
$bots = Bot::with([
    'conversations' => fn($q) => $q->latest()->limit(5),
    'conversations.latestMessage'
])->get();

// Use cursor for large datasets
Bot::select(['id', 'name'])->cursor()->each(function ($bot) {
    // Process one at a time, minimal memory
});
```

**Why it's better:**
- Minimal data transfer
- Low memory usage
- Faster queries
- Scales better

## Review Checklist

- [ ] `select()` used when not all columns needed
- [ ] `pluck()` for single column lists
- [ ] `count()` instead of `->get()->count()`
- [ ] Eager loading limited/constrained
- [ ] Large fields excluded from list queries

## Detection

```bash
# Look for ::all() or ->get() without select
grep -rn "::all()\|->get()" --include="*.php" app/Http/Controllers/ | grep -v "select\|pluck"

# Large text fields being loaded
grep -rn "system_prompt\|content\|body" --include="*.php" app/Http/Controllers/
```

## Project-Specific Notes

**BotFacebook Optimization Patterns:**

```php
// Bot list - exclude large fields
public function index()
{
    return Bot::select([
        'id', 'name', 'slug', 'platform',
        'is_active', 'model', 'created_at'
        // Excludes: system_prompt (large text)
    ])
    ->withCount('conversations')
    ->paginate();
}

// Bot detail - full model OK
public function show(Bot $bot)
{
    return $bot->load('user'); // Need system_prompt here
}

// Dashboard stats - aggregates only
public function stats()
{
    return [
        'total_bots' => Bot::count(),
        'active_bots' => Bot::where('is_active', true)->count(),
        'total_messages' => Message::count(),
        'messages_today' => Message::whereDate('created_at', today())->count(),
    ];
}

// Export - use cursor for memory efficiency
public function export(Bot $bot)
{
    return response()->streamDownload(function () use ($bot) {
        $bot->messages()
            ->select(['content', 'role', 'created_at'])
            ->cursor()
            ->each(fn($m) => echo $m->toJson() . "\n");
    }, 'messages.jsonl');
}
```
