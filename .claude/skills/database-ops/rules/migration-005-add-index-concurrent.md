---
id: migration-005-add-index-concurrent
title: Non-Blocking Index Creation
impact: HIGH
impactDescription: "Regular CREATE INDEX locks the table, blocking all writes"
category: migration
tags: [migration, index, concurrent, lock]
relatedRules: [perf-002-sequential-scan]
---

## Why This Matters

Creating an index on a large table locks it for writes until the index is built. This can take minutes or hours on large tables, causing application timeouts. `CREATE INDEX CONCURRENTLY` allows writes to continue during index creation.

## Bad Example

```php
public function up(): void
{
    Schema::table('messages', function (Blueprint $table) {
        // Locks table during index creation
        $table->index(['bot_id', 'created_at']);
    });
}
```

**Why it's wrong:**
- Table locked during build
- Long-running migrations block deployment
- Application timeouts

## Good Example

```php
public function up(): void
{
    // Use CONCURRENTLY for non-blocking
    DB::statement('
        CREATE INDEX CONCURRENTLY idx_messages_bot_created
        ON messages (bot_id, created_at)
    ');
}

public function down(): void
{
    DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_messages_bot_created');
}
```

**Why it's better:**
- No table lock
- Writes continue during build
- Safe for large production tables

## Project-Specific Notes

**BotFacebook Index Patterns:**

```php
// For large tables (>100K rows)
DB::statement('
    CREATE INDEX CONCURRENTLY idx_name
    ON table_name (column)
');

// For small tables, regular index is fine
Schema::table('settings', function (Blueprint $table) {
    $table->index('key');
});
```

**CONCURRENTLY Limitations:**
- Cannot run in transaction
- Must be in separate migration
- May fail and leave invalid index

```php
// Check for invalid indexes after CONCURRENTLY
// SELECT * FROM pg_indexes WHERE indexdef LIKE '%INVALID%';
```
