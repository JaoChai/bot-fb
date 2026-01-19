---
id: gotcha-003-race-condition-locks
title: Race Conditions and Database Locks
impact: CRITICAL
impactDescription: "Prevents duplicate records and data corruption from concurrent requests"
category: gotcha
tags: [race-condition, lock, database, concurrency]
relatedRules: [laravel-003-service-layer]
---

## Why This Matters

Race conditions occur when multiple requests try to read-then-write the same data simultaneously. Without proper locking, you can get duplicate records, data corruption, or lost updates. This is especially common in webhook handlers and queue jobs.

## Bad Example

```php
// Problem: Race condition - two webhooks can create duplicate conversations
public function handleWebhook(array $payload)
{
    $conversation = Conversation::where('platform_id', $payload['id'])->first();

    if (!$conversation) {
        // Two requests can both reach here before either creates
        $conversation = Conversation::create([
            'platform_id' => $payload['id'],
            // ...
        ]);
    }

    return $conversation;
}
```

**Why it's wrong:**
- Two requests can both pass the `if (!$conversation)` check
- Both create new records = duplicate data
- Race window is small but real under load
- Hard to reproduce and debug

## Good Example

```php
// Solution 1: Use lockForUpdate() within transaction
public function handleWebhook(array $payload)
{
    return DB::transaction(function () use ($payload) {
        // Lock the row for update (or table if not found)
        $conversation = Conversation::where('platform_id', $payload['id'])
            ->lockForUpdate()
            ->first();

        if (!$conversation) {
            $conversation = Conversation::create([
                'platform_id' => $payload['id'],
                // ...
            ]);
        }

        return $conversation;
    });
}

// Solution 2: Use firstOrCreate with unique constraint
public function handleWebhook(array $payload)
{
    // Relies on unique constraint in database
    return Conversation::firstOrCreate(
        ['platform_id' => $payload['id']], // Unique lookup
        ['bot_id' => $payload['bot_id'], /* ... */] // Create data
    );
}

// Solution 3: Use updateOrCreate
return Conversation::updateOrCreate(
    ['platform_id' => $payload['id']],
    ['last_activity_at' => now(), /* ... */]
);
```

**Why it's better:**
- `lockForUpdate()` blocks other transactions
- Only one request can proceed at a time
- Database enforces consistency
- No duplicate records possible

## Project-Specific Notes

**BotFacebook Webhook Handlers:**

```php
// ProcessLINEWebhook job - MUST use locking
public function handle()
{
    DB::transaction(function () {
        $conversation = Conversation::where('bot_id', $this->botId)
            ->where('platform_user_id', $this->userId)
            ->lockForUpdate()
            ->first();

        if (!$conversation) {
            $conversation = Conversation::create([...]);
        }

        // Process message...
    });
}
```

**Migration - Add Unique Constraint:**
```php
// Backup protection: unique constraint
$table->unique(['bot_id', 'platform_user_id']);
```

## References

- [Laravel Pessimistic Locking](https://laravel.com/docs/eloquent#pessimistic-locking)
- [Database Transactions](https://laravel.com/docs/database#database-transactions)
