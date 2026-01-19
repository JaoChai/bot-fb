---
id: eloquent-004-soft-deletes
title: Soft Deletes
impact: MEDIUM
impactDescription: "Enables data recovery and audit trails for deleted records"
category: eloquent
tags: [eloquent, model, soft-delete, database]
relatedRules: [eloquent-002-query-scopes]
---

## Why This Matters

Soft deletes keep deleted records in the database with a `deleted_at` timestamp instead of permanently removing them. This enables data recovery, audit trails, and maintaining referential integrity.

## Bad Example

```php
// Problem: Hard delete - data lost forever
public function destroy(Bot $bot)
{
    $bot->delete(); // Gone permanently!
    // Can't recover, no audit trail
}
```

**Why it's wrong:**
- Data permanently lost
- No recovery option
- No audit trail
- Foreign key issues

## Good Example

```php
// Migration
Schema::create('bots', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->timestamps();
    $table->softDeletes(); // Adds deleted_at column
});

// Model
class Bot extends Model
{
    use SoftDeletes;
}

// Usage
$bot->delete(); // Sets deleted_at, doesn't remove row

// Queries automatically exclude soft deleted
Bot::all(); // Only non-deleted bots

// Include soft deleted
Bot::withTrashed()->get();

// Only soft deleted
Bot::onlyTrashed()->get();

// Restore
$bot->restore();

// Force delete (permanent)
$bot->forceDelete();

// Check if soft deleted
if ($bot->trashed()) {
    // Handle deleted bot
}
```

**Why it's better:**
- Data recoverable
- Audit trail maintained
- Referential integrity preserved
- Queries auto-filter

## Project-Specific Notes

**BotFacebook Soft Delete Models:**
- `Bot` - Users might want to recover
- `Flow` - Important workflow data
- `Conversation` - Historical data
- `KnowledgeBase` - Expensive to recreate

**Hard Delete Models (no soft delete):**
- `Message` - High volume, archival instead
- `ActivityLog` - Rotate/archive instead
- `Token` - Security, must hard delete

**Cascade Soft Deletes:**
```php
// When bot is soft deleted, also soft delete flows
protected static function booted()
{
    static::deleting(function (Bot $bot) {
        if (!$bot->isForceDeleting()) {
            $bot->flows()->delete(); // Soft delete
        }
    });

    static::restoring(function (Bot $bot) {
        $bot->flows()->restore();
    });
}
```

## References

- [Laravel Soft Deleting](https://laravel.com/docs/eloquent#soft-deleting)
