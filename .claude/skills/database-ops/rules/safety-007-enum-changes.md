---
id: safety-007-enum-changes
title: PostgreSQL Enum Modifications
impact: HIGH
impactDescription: "PostgreSQL enums cannot be modified easily, causing migration issues"
category: safety
tags: [safety, enum, type, postgresql]
relatedRules: [migration-010-change-column-type]
---

## Why This Matters

PostgreSQL enums are immutable once created. You can add values, but cannot remove, rename, or reorder them. This causes issues when requirements change. Consider using string columns instead.

## Bad Example

```php
// Creating enum - seems easy
public function up(): void
{
    DB::statement("CREATE TYPE bot_status AS ENUM ('draft', 'active', 'paused')");
    Schema::table('bots', function (Blueprint $table) {
        $table->enum('status', ['draft', 'active', 'paused']);
    });
}

// Later: Need to remove 'draft' - NOT POSSIBLE directly!
public function up(): void
{
    // This doesn't work in PostgreSQL
    DB::statement("ALTER TYPE bot_status DROP VALUE 'draft'");
}
```

**Why it's wrong:**
- Cannot remove enum values
- Cannot rename values
- Complex workarounds required

## Good Example

```php
// Option 1: Use string instead of enum
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->string('status', 20)->default('draft');
        $table->index('status');
    });
}

// Validation in model
class Bot extends Model
{
    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_PAUSED = 'paused';

    const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
    ];

    protected $casts = [
        'status' => 'string',
    ];
}

// Option 2: If must change enum, create new type
public function up(): void
{
    // 1. Create new enum
    DB::statement("CREATE TYPE bot_status_new AS ENUM ('active', 'paused', 'archived')");

    // 2. Add new column
    Schema::table('bots', function (Blueprint $table) {
        $table->addColumn('status_new', 'bot_status_new')->nullable();
    });

    // 3. Migrate data
    DB::statement("UPDATE bots SET status_new = status::text::bot_status_new WHERE status != 'draft'");
    DB::statement("UPDATE bots SET status_new = 'archived' WHERE status = 'draft'");

    // 4. Drop old, rename new
    Schema::table('bots', function (Blueprint $table) {
        $table->dropColumn('status');
    });
    Schema::table('bots', function (Blueprint $table) {
        $table->renameColumn('status_new', 'status');
    });

    // 5. Drop old type
    DB::statement("DROP TYPE bot_status");
    DB::statement("ALTER TYPE bot_status_new RENAME TO bot_status");
}
```

**Why it's better:**
- Strings are flexible
- Enum changes have migration path
- Type safety via model constants

## Project-Specific Notes

**BotFacebook Recommendation:** Use strings with model constants, not enums.

```php
// config/bot.php
return [
    'statuses' => ['draft', 'active', 'paused', 'archived'],
    'platforms' => ['line', 'telegram', 'messenger'],
];
```
