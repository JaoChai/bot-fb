---
id: gotcha-004-deadlock
title: Deadlocks from Concurrent Updates
impact: HIGH
impactDescription: "Concurrent updates to same rows can deadlock, failing transactions"
category: gotcha
tags: [gotcha, deadlock, concurrent, lock, transaction]
relatedRules: [safety-005-long-running-migration]
---

## Why This Matters

When two transactions try to update the same rows in different orders, they can deadlock - each waiting for the other's lock. PostgreSQL kills one transaction, causing unexpected failures.

## Bad Example

```php
// Transaction 1: Updates A then B
DB::transaction(function () {
    Bot::find(1)->update(['count' => DB::raw('count + 1')]);
    Bot::find(2)->update(['count' => DB::raw('count + 1')]);
});

// Transaction 2: Updates B then A (different order!)
DB::transaction(function () {
    Bot::find(2)->update(['count' => DB::raw('count + 1')]);
    Bot::find(1)->update(['count' => DB::raw('count + 1')]);
});

// DEADLOCK! One transaction fails
```

**Why it's wrong:**
- Different lock order causes deadlock
- Unpredictable failures
- Data inconsistency possible

## Good Example

```php
// Consistent lock order (always by ID ascending)
DB::transaction(function () {
    $ids = [2, 1];
    sort($ids); // Always lock in same order

    foreach ($ids as $id) {
        Bot::where('id', $id)
            ->lockForUpdate()
            ->first()
            ->increment('count');
    }
});

// Or use advisory locks for coordination
DB::transaction(function () use ($botId) {
    // Acquire advisory lock
    DB::select("SELECT pg_advisory_xact_lock(?)", [$botId]);

    // Safe to update
    Bot::find($botId)->increment('count');
    // Lock released on commit
});
```

**Why it's better:**
- Consistent lock order prevents deadlock
- Advisory locks coordinate access
- Predictable behavior

## Project-Specific Notes

**BotFacebook Anti-Deadlock Pattern:**

```php
// In MessageService
public function incrementCounters(array $botIds): void
{
    DB::transaction(function () use ($botIds) {
        // Sort to ensure consistent lock order
        sort($botIds);

        foreach ($botIds as $botId) {
            Bot::where('id', $botId)
                ->lockForUpdate()
                ->first()
                ?->increment('message_count');
        }
    });
}
```
