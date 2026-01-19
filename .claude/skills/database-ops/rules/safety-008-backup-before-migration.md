---
id: safety-008-backup-before-migration
title: Backup Before Destructive Migrations
impact: HIGH
impactDescription: "Destructive changes without backup can cause irreversible data loss"
category: safety
tags: [safety, backup, migration, data-protection]
relatedRules: [safety-002-drop-column-production]
---

## Why This Matters

Before any migration that modifies or deletes data, you need a backup. Neon's branching makes this easy - create a branch before migrating. If something goes wrong, you can restore from the branch.

## Bad Example

```php
// Dropping data without backup
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('legacy_data');
    });

    DB::table('old_logs')->truncate();
}
```

**Why it's wrong:**
- No way to recover data
- Point-in-time restore is slow
- May lose hours of data

## Good Example

```bash
# Before running migration
neon branches create --name pre-migration-backup --parent main

# Run migration
php artisan migrate

# If successful, delete backup after verification period
# neon branches delete pre-migration-backup

# If failed, restore from backup
# neon branches reset main --parent pre-migration-backup
```

```php
// In migration: export data before deletion
public function up(): void
{
    // 1. Export to JSON (for small datasets)
    $legacyData = DB::table('users')
        ->whereNotNull('legacy_data')
        ->get(['id', 'legacy_data']);

    Storage::put(
        'backups/legacy_data_' . now()->format('Y-m-d_His') . '.json',
        $legacyData->toJson()
    );

    // 2. Proceed with deletion
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('legacy_data');
    });
}
```

**Why it's better:**
- Data preserved before deletion
- Easy rollback via branch
- Export provides additional safety

## Project-Specific Notes

**BotFacebook Backup Policy:**

| Migration Type | Backup Required |
|---------------|-----------------|
| Add column | No |
| Add index | No |
| Drop column | Yes |
| Truncate table | Yes |
| Type change | Yes |
| Data migration | Yes |

**Neon Branch Backup:**
```bash
# Quick backup before risky migration
neon branches create --name $(date +%Y%m%d_%H%M)_pre_migration
```
