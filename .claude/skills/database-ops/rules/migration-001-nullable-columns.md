---
id: migration-001-nullable-columns
title: New Columns Must Be Nullable or Have Defaults
impact: CRITICAL
impactDescription: "NOT NULL columns without defaults fail on tables with existing data"
category: migration
tags: [migration, nullable, column, production]
relatedRules: [safety-001-not-null-constraint]
---

## Why This Matters

Adding a NOT NULL column without a default to a table with existing rows will fail. PostgreSQL cannot insert NULL into a NOT NULL column, and it won't guess a default value. This breaks production deployments.

## Bad Example

```php
// Problem: NOT NULL without default - fails if table has data
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->string('status'); // NOT NULL, no default
    });
}
```

**Why it's wrong:**
- Fails on tables with existing data
- No rollback possible if migration fails mid-way
- Production deployment breaks

## Good Example

```php
// Option 1: Make nullable
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->string('status')->nullable();
    });
}

// Option 2: Add default
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->string('status')->default('active');
    });
}

// Option 3: Two-phase approach for NOT NULL
// Migration 1: Add nullable
$table->string('status')->nullable();

// Migration 2: Backfill then add constraint
DB::table('bots')->whereNull('status')->update(['status' => 'active']);
Schema::table('bots', function (Blueprint $table) {
    $table->string('status')->nullable(false)->change();
});
```

**Why it's better:**
- Migration succeeds on tables with data
- Explicit handling of existing rows
- Safe rollback possible

## Project-Specific Notes

**BotFacebook Pattern:**
```php
// Always use nullable for new columns
$table->string('new_field')->nullable()->after('existing_field');

// If default needed, be explicit
$table->boolean('is_active')->default(true);
$table->integer('retry_count')->default(0);
```

## Audit Command

```bash
# Find migrations with potential issues
grep -rn "->string\|->integer\|->boolean" database/migrations/ | grep -v "nullable\|default"
```

## References

- [Laravel Migrations](https://laravel.com/docs/migrations#column-modifiers)
