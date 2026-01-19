---
id: migration-004-add-column-safe
title: Safe Column Addition Pattern
impact: HIGH
impactDescription: "Ensures new columns don't break existing functionality"
category: migration
tags: [migration, column, nullable, default]
relatedRules: [migration-001-nullable-columns]
---

## Why This Matters

Adding columns safely requires considering existing data, application code timing, and rollback scenarios. A well-structured column addition migration prevents deployment issues and data inconsistencies.

## Bad Example

```php
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->string('webhook_url'); // No nullable, no default
        $table->boolean('is_verified'); // No default
    });
}
```

**Why it's wrong:**
- Fails on tables with data
- No positioning consideration
- No index for queried columns

## Good Example

```php
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        // Nullable for optional fields
        $table->string('webhook_url')->nullable()->after('platform');

        // Default for boolean flags
        $table->boolean('is_verified')->default(false)->after('is_active');

        // Index for frequently queried columns
        $table->index('is_verified');
    });
}

public function down(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->dropIndex(['is_verified']);
        $table->dropColumn(['webhook_url', 'is_verified']);
    });
}
```

**Why it's better:**
- Safe for existing data
- Column positioning with `after()`
- Index for query performance
- Clean rollback

## Project-Specific Notes

**BotFacebook Column Patterns:**

```php
// String fields - always nullable unless required
$table->string('field')->nullable();

// Boolean flags - always default
$table->boolean('is_active')->default(true);
$table->boolean('is_deleted')->default(false);

// Counters - default to 0
$table->integer('message_count')->default(0);
$table->decimal('total_cost', 10, 4)->default(0);

// Timestamps - nullable
$table->timestamp('verified_at')->nullable();
$table->timestamp('last_used_at')->nullable();

// JSON - nullable with default
$table->jsonb('settings')->nullable()->default('{}');
```
