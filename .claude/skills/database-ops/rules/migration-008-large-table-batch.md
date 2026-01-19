---
id: migration-008-large-table-batch
title: Batch Operations for Large Tables
impact: MEDIUM
impactDescription: "Updating millions of rows at once causes locks and timeouts"
category: migration
tags: [migration, batch, large-table, performance]
relatedRules: [perf-004-batch-insert]
---

## Why This Matters

Updating millions of rows in a single transaction locks the table, exhausts memory, and can timeout. Batching spreads the load, releases locks periodically, and allows progress monitoring.

## Bad Example

```php
public function up(): void
{
    // Updates all rows in one transaction
    DB::table('messages')->update(['status' => 'sent']);

    // Or with Eloquent - loads all into memory
    Message::all()->each(fn($m) => $m->update(['status' => 'sent']));
}
```

**Why it's wrong:**
- Single long transaction
- Table locked throughout
- Memory exhaustion
- Timeout risk

## Good Example

```php
public function up(): void
{
    // Batch with chunkById for efficiency
    DB::table('messages')
        ->whereNull('status')
        ->orderBy('id')
        ->chunkById(1000, function ($messages) {
            DB::table('messages')
                ->whereIn('id', $messages->pluck('id'))
                ->update(['status' => 'sent']);
        });
}

// Or raw SQL for maximum performance
public function up(): void
{
    $batchSize = 10000;
    $maxId = DB::table('messages')->max('id');

    for ($startId = 0; $startId <= $maxId; $startId += $batchSize) {
        DB::statement("
            UPDATE messages
            SET status = 'sent'
            WHERE id > ? AND id <= ?
              AND status IS NULL
        ", [$startId, $startId + $batchSize]);

        // Allow other queries to run
        usleep(100000); // 100ms pause
    }
}
```

**Why it's better:**
- Releases locks between batches
- Memory efficient
- Progress visible
- Can be interrupted

## Project-Specific Notes

**BotFacebook Batch Sizes:**

| Table Size | Batch Size | Pause |
|------------|------------|-------|
| < 100K | 5,000 | None |
| 100K - 1M | 1,000 | 50ms |
| > 1M | 500 | 100ms |
