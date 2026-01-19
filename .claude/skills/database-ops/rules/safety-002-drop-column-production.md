---
id: safety-002-drop-column-production
title: Dropping Columns in Production
impact: CRITICAL
impactDescription: "Dropping columns with data is irreversible data loss"
category: safety
tags: [safety, drop-column, data-loss, production]
relatedRules: [migration-003-two-phase-drops]
---

## Why This Matters

`DROP COLUMN` is irreversible. Once you drop a column with data, that data is gone forever. Even with backups, restoring a single column is complex and time-consuming. This is the most dangerous migration operation.

## Bad Example

```php
// Problem: Dropping column without verification
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('phone_number'); // Data gone forever!
    });
}
```

**Why it's wrong:**
- Immediate, permanent data loss
- No confirmation step
- Cannot rollback without full backup restore

## Good Example

```php
// Step 1: Verify column is safe to drop (separate PR)
public function up(): void
{
    // Only proceed if column is truly unused
    $usageCount = DB::table('users')
        ->whereNotNull('phone_number')
        ->count();

    if ($usageCount > 0) {
        throw new \Exception(
            "Cannot drop phone_number: {$usageCount} rows have data. " .
            "Export data first or confirm deletion is intended."
        );
    }

    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('phone_number');
    });
}

// OR: Rename first, drop later
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        // Rename to indicate pending deletion
        $table->renameColumn('phone_number', '_deprecated_phone_number');
    });
}

// In a later migration (after verification period):
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('_deprecated_phone_number');
    });
}
```

**Why it's better:**
- Verification before deletion
- Grace period for catching mistakes
- Data preserved until confirmed safe

## Project-Specific Notes

**BotFacebook Drop Column Checklist:**

```markdown
Before dropping column:
- [ ] Column removed from code (Model, Controller, Resource)
- [ ] Code deployed and running in production
- [ ] No errors related to column for 24+ hours
- [ ] Data exported if potentially needed
- [ ] Backup verified
- [ ] Migration tested on Neon branch
```

**Soft Delete Approach:**
```php
// Instead of dropping, mark as deprecated
Schema::table('bots', function (Blueprint $table) {
    $table->timestamp('_deleted_at')->nullable();
});

// Set deleted_at for "deleted" rows
// Drop column only after retention period
```

## MCP Tools

```
# Backup column data before drop
mcp__neon__run_sql(
    projectId="your-project",
    sql="SELECT id, phone_number FROM users WHERE phone_number IS NOT NULL"
)
```

## References

- [Safe Database Operations](https://docs.gitlab.com/ee/development/database/avoiding_downtime_in_migrations.html)
