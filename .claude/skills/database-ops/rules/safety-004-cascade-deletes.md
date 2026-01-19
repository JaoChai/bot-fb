---
id: safety-004-cascade-deletes
title: Cascade Deletes Can Cause Mass Data Loss
impact: CRITICAL
impactDescription: "ON DELETE CASCADE can delete thousands of related records unexpectedly"
category: safety
tags: [safety, cascade, foreign-key, data-loss]
relatedRules: [migration-006-foreign-key]
---

## Why This Matters

`ON DELETE CASCADE` automatically deletes all child records when a parent is deleted. Deleting one user could cascade through bots → flows → messages → attachments, wiping years of conversation data. This is irreversible and often unexpected.

## Bad Example

```php
// Problem: Cascade everything without thinking
Schema::create('messages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('conversation_id')
          ->constrained()
          ->cascadeOnDelete(); // Deletes all messages!
    // ...
});

// Later in code:
$conversation->delete(); // Oops, all messages gone!
```

**Why it's wrong:**
- Mass deletion from single delete
- No warning or confirmation
- Audit trail lost
- Cannot recover without backup

## Good Example

```php
// Option 1: Restrict delete (safe default)
Schema::create('messages', function (Blueprint $table) {
    $table->foreignId('conversation_id')
          ->constrained()
          ->restrictOnDelete(); // Cannot delete conversation with messages
});

// Option 2: Soft deletes instead
Schema::create('messages', function (Blueprint $table) {
    $table->foreignId('conversation_id')
          ->constrained()
          ->nullOnDelete(); // Sets to NULL, preserves messages
    $table->softDeletes();
});

// Option 3: Explicit cascade with soft delete parent
// Parent uses softDeletes, cascade only on forceDelete
```

**Why it's better:**
- Deletion requires explicit handling
- Data preserved by default
- Audit trail maintained

## Project-Specific Notes

**BotFacebook Cascade Policy:**

| Parent | Child | On Delete |
|--------|-------|-----------|
| User | Bot | Cascade (user gone = bots gone) |
| Bot | Flow | Cascade (bot gone = flows gone) |
| Bot | Conversation | SET NULL (preserve history) |
| Conversation | Message | Restrict (must handle explicitly) |
| KnowledgeBase | Document | Cascade (KB gone = docs gone) |

**Safe Delete Pattern:**
```php
// In BotService
public function delete(Bot $bot): void
{
    DB::transaction(function () use ($bot) {
        // 1. Archive conversations if needed
        $bot->conversations()->update(['archived_at' => now()]);

        // 2. Soft delete related records
        $bot->flows()->delete(); // Soft delete

        // 3. Finally delete bot
        $bot->delete(); // Also soft delete
    });
}
```

**Never Use CASCADE For:**
- User data that should be retained
- Audit logs
- Billing/payment records
- Messages with legal retention requirements

## MCP Tools

```
# Check what would be deleted
mcp__neon__run_sql(
    projectId="your-project",
    sql="SELECT COUNT(*) FROM messages WHERE conversation_id IN (SELECT id FROM conversations WHERE bot_id = 123)"
)
```

## References

- [PostgreSQL Foreign Keys](https://www.postgresql.org/docs/current/ddl-constraints.html#DDL-CONSTRAINTS-FK)
