---
id: safety-005-long-running-migration
title: Long-Running Migrations and Lock Timeouts
impact: HIGH
impactDescription: "Migrations that run too long cause lock timeouts and deployment failures"
category: safety
tags: [safety, lock, timeout, migration]
relatedRules: [migration-008-large-table-batch]
---

## Why This Matters

Long-running migrations hold locks that block other operations. When deployment scripts timeout, the migration may be left in an inconsistent state. Large table operations need careful planning.

## Bad Example

```php
public function up(): void
{
    // Full table scan on 10M rows - takes 30+ minutes
    DB::table('messages')
        ->where('created_at', '<', now()->subYear())
        ->update(['archived' => true]);

    // Adding index on huge table - locks for duration
    Schema::table('messages', function (Blueprint $table) {
        $table->index('archived');
    });
}
```

**Why it's wrong:**
- Deployment timeout (usually 10-15 min)
- Table locked during operation
- May leave database inconsistent

## Good Example

```php
public function up(): void
{
    // 1. Create index concurrently (non-blocking)
    DB::statement('
        CREATE INDEX CONCURRENTLY idx_messages_archived
        ON messages (archived)
        WHERE archived = true
    ');
}

// Separate job for data backfill
class BackfillArchivedJob implements ShouldQueue
{
    public function handle(): void
    {
        DB::table('messages')
            ->where('created_at', '<', now()->subYear())
            ->whereNull('archived')
            ->orderBy('id')
            ->chunkById(5000, function ($messages) {
                DB::table('messages')
                    ->whereIn('id', $messages->pluck('id'))
                    ->update(['archived' => true]);
            });
    }
}
```

**Why it's better:**
- Index creation non-blocking
- Data update in background job
- Deployment completes quickly

## Project-Specific Notes

**BotFacebook Time Limits:**

| Operation | Max Time | Approach |
|-----------|----------|----------|
| Add column | 5 sec | Direct |
| Add index (small) | 30 sec | Direct |
| Add index (large) | N/A | CONCURRENTLY |
| Update rows | 60 sec | Batch in job |
| Full migration | 5 min | Split into multiple |

```bash
# Set statement timeout for safety
SET statement_timeout = '5min';
```
