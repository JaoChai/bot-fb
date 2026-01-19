---
id: safety-001-not-null-constraint
title: NOT NULL Constraint on Existing Data
impact: CRITICAL
impactDescription: "Adding NOT NULL to column with NULL values fails immediately"
category: safety
tags: [safety, not-null, constraint, data-integrity]
relatedRules: [migration-001-nullable-columns]
---

## Why This Matters

You cannot add a NOT NULL constraint to a column that already contains NULL values. PostgreSQL will reject the constraint immediately. This breaks migrations and can leave your database in a partially migrated state.

## Bad Example

```php
// Problem: Column has NULL values, adding NOT NULL fails
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        // This fails if any row has status = NULL
        $table->string('status')->nullable(false)->change();
    });
}
```

**Why it's wrong:**
- Migration fails immediately
- No partial success - all or nothing
- Must fix data manually before retrying

## Good Example

```php
public function up(): void
{
    // Step 1: Backfill NULL values
    DB::table('bots')
        ->whereNull('status')
        ->update(['status' => 'active']);

    // Step 2: Add NOT NULL constraint
    Schema::table('bots', function (Blueprint $table) {
        $table->string('status')->nullable(false)->change();
    });
}

public function down(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->string('status')->nullable()->change();
    });
}
```

**Why it's better:**
- Handles existing NULL values
- Migration completes successfully
- Data integrity maintained

## Project-Specific Notes

**BotFacebook Safe Pattern:**

```php
// For large tables, use batched updates
public function up(): void
{
    // Backfill in batches to avoid locks
    DB::table('messages')
        ->whereNull('status')
        ->orderBy('id')
        ->chunk(1000, function ($rows) {
            DB::table('messages')
                ->whereIn('id', $rows->pluck('id'))
                ->update(['status' => 'sent']);
        });

    // Then add constraint
    Schema::table('messages', function (Blueprint $table) {
        $table->string('status')->nullable(false)->change();
    });
}
```

**Check Before Migration:**
```sql
-- Count NULL values before migration
SELECT COUNT(*) FROM bots WHERE status IS NULL;
```

## MCP Tools

```
# Check for NULL values using Neon MCP
mcp__neon__run_sql(
    projectId="your-project",
    sql="SELECT COUNT(*) as null_count FROM bots WHERE status IS NULL"
)
```

## References

- [PostgreSQL Constraints](https://www.postgresql.org/docs/current/ddl-constraints.html)
