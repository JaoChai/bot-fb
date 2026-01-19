---
id: migration-002-rollback-strategy
title: Always Include Rollback (down) Method
impact: CRITICAL
impactDescription: "Missing down() method makes rollback impossible during failed deployments"
category: migration
tags: [migration, rollback, down, deployment]
relatedRules: [migration-001-nullable-columns]
---

## Why This Matters

The `down()` method is your emergency exit. When a deployment fails mid-way or causes issues in production, you need to rollback. Without a proper `down()` method, you're stuck with manual database fixes under pressure.

## Bad Example

```php
// Problem: No down() method
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->string('webhook_url')->nullable();
        $table->index('webhook_url');
    });
}

// Or worse: Empty down()
public function down(): void
{
    // TODO: implement rollback
}
```

**Why it's wrong:**
- Cannot rollback if deployment fails
- Leaves database in inconsistent state
- Manual intervention required under pressure

## Good Example

```php
public function up(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->string('webhook_url')->nullable();
        $table->index('webhook_url');
    });
}

public function down(): void
{
    Schema::table('bots', function (Blueprint $table) {
        $table->dropIndex(['webhook_url']);
        $table->dropColumn('webhook_url');
    });
}
```

**Why it's better:**
- Clean rollback path
- Reverses changes in correct order (index before column)
- Safe deployment recovery

## Project-Specific Notes

**BotFacebook Rollback Order:**
```php
public function down(): void
{
    Schema::table('table', function (Blueprint $table) {
        // 1. Drop indexes first
        $table->dropIndex(['column']);

        // 2. Drop foreign keys
        $table->dropForeign(['foreign_id']);

        // 3. Drop columns last
        $table->dropColumn('column');
    });
}
```

**For create table migrations:**
```php
public function up(): void
{
    Schema::create('new_table', function (Blueprint $table) {
        // ...
    });
}

public function down(): void
{
    Schema::dropIfExists('new_table');
}
```

## Audit Command

```bash
# Find migrations without proper down()
grep -L "function down" database/migrations/*.php
```

## References

- [Laravel Rollback](https://laravel.com/docs/migrations#rolling-back-migrations)
