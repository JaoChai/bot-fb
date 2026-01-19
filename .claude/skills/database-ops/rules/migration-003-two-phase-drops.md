---
id: migration-003-two-phase-drops
title: Two-Phase Column Drops for Production Safety
impact: CRITICAL
impactDescription: "Dropping columns while code still uses them causes runtime errors"
category: migration
tags: [migration, drop-column, production, deployment]
relatedRules: [safety-002-drop-column-production]
---

## Why This Matters

If you drop a column while code still references it, you'll get instant 500 errors across your application. The database change is atomic, but your code deployment isn't. There's always a window where old code runs against new schema.

## Bad Example

```php
// Problem: Single migration drops column
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->dropColumn('deprecated_field'); // Code still using it!
    });
}
```

**Why it's wrong:**
- Running code immediately breaks
- Rolling servers have old code
- No way to recover without restoring backup

## Good Example

```php
// Phase 1: Stop using column in code
// - Remove all reads of deprecated_field
// - Deploy code changes
// - Wait for all servers to update

// Phase 2: Create migration to drop column (separate PR)
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->dropColumn('deprecated_field');
    });
}

public function down(): void
{
    Schema::table('bots', function (Blueprint $table) {
        // Restore column (nullable since we don't have data)
        $table->string('deprecated_field')->nullable();
    });
}
```

**Why it's better:**
- Code no longer references column before drop
- Zero downtime
- Safe rollback path

## Project-Specific Notes

**BotFacebook Two-Phase Pattern:**

```
PR #1: Remove column usage from code
├── Remove from models ($fillable, $casts, $hidden)
├── Remove from controllers/services
├── Remove from API resources
├── Remove from frontend types
└── Deploy and verify

PR #2: Drop column migration (1+ days later)
├── Create migration
├── Test on Neon branch
└── Deploy
```

**Column Deprecation Checklist:**
```php
// Before dropping 'old_field':
// [ ] Removed from Model $fillable
// [ ] Removed from Model $casts
// [ ] Removed from Model $hidden
// [ ] Removed from API Resource
// [ ] Removed from FormRequest rules
// [ ] Removed from frontend types
// [ ] No queries reference it
// [ ] Code deployed for 24+ hours
```

## Audit Command

```bash
# Check if column still referenced in code
grep -rn "deprecated_field" app/ resources/ --include="*.php" --include="*.tsx"
```

## References

- [Safe Database Migrations](https://docs.gitlab.com/ee/development/database/avoiding_downtime_in_migrations.html)
