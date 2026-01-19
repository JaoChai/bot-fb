---
id: migration-007-rename-column
title: Column Rename Strategy
impact: HIGH
impactDescription: "Column renames can break code during deployment window"
category: migration
tags: [migration, rename, column, deployment]
relatedRules: [migration-003-two-phase-drops]
---

## Why This Matters

When you rename a column, there's a window where some servers have old code expecting the old name, while the database has the new name. This causes errors until all servers are updated.

## Bad Example

```php
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        // Instant rename - old code immediately breaks
        $table->renameColumn('bot_name', 'name');
    });
}
```

**Why it's wrong:**
- Old code breaks immediately
- Rolling deployments have errors
- No graceful transition

## Good Example

```php
// Phase 1: Add new column, copy data
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->string('name')->nullable();
    });

    DB::statement('UPDATE bots SET name = bot_name');
}

// Phase 2: Update code to use new column
// Deploy and verify

// Phase 3: Make new column not null, drop old
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->string('name')->nullable(false)->change();
        $table->dropColumn('bot_name');
    });
}
```

**Why it's better:**
- Graceful transition
- Both names work during deployment
- No errors during rollout

## Project-Specific Notes

**BotFacebook Rename Pattern:**

```
Migration 1: Add new column
├── Add nullable column with new name
├── Copy data from old to new
└── Deploy - both columns exist

Migration 2: Switch code
├── Update model to use new name
├── Update queries and resources
└── Deploy - code uses new name

Migration 3: Cleanup
├── Drop old column
└── Deploy - only new name exists
```
