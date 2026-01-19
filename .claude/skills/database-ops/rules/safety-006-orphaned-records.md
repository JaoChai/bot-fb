---
id: safety-006-orphaned-records
title: Handling Orphaned Records
impact: HIGH
impactDescription: "Adding FK constraints fails when orphaned records exist"
category: safety
tags: [safety, foreign-key, orphan, data-integrity]
relatedRules: [migration-006-foreign-key]
---

## Why This Matters

Adding a foreign key constraint validates all existing data. If there are records pointing to non-existent parents (orphans), the constraint creation fails. This commonly happens with soft deletes or accidental deletions.

## Bad Example

```php
public function up(): void
{
    // Fails if any message.conversation_id doesn't exist in conversations
    Schema::table('messages', function (Blueprint $table) {
        $table->foreign('conversation_id')
              ->references('id')
              ->on('conversations');
    });
}
```

**Why it's wrong:**
- Fails on orphaned records
- No cleanup before constraint
- Migration blocked

## Good Example

```php
public function up(): void
{
    // 1. Find orphans
    $orphanCount = DB::table('messages')
        ->leftJoin('conversations', 'messages.conversation_id', '=', 'conversations.id')
        ->whereNull('conversations.id')
        ->count();

    if ($orphanCount > 0) {
        Log::warning("Found {$orphanCount} orphaned messages");

        // 2. Handle orphans (choose one)
        // Option A: Delete orphans
        DB::table('messages')
            ->leftJoin('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->whereNull('conversations.id')
            ->delete();

        // Option B: Set to NULL (if nullable)
        DB::table('messages')
            ->leftJoin('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->whereNull('conversations.id')
            ->update(['conversation_id' => null]);
    }

    // 3. Add constraint
    Schema::table('messages', function (Blueprint $table) {
        $table->foreign('conversation_id')
              ->references('id')
              ->on('conversations')
              ->onDelete('cascade');
    });
}
```

**Why it's better:**
- Checks for orphans first
- Handles them explicitly
- Constraint succeeds

## Project-Specific Notes

**BotFacebook Orphan Check:**

```sql
-- Find orphaned messages
SELECT m.id, m.conversation_id
FROM messages m
LEFT JOIN conversations c ON m.conversation_id = c.id
WHERE c.id IS NULL;

-- Find orphaned flows
SELECT f.id, f.bot_id
FROM flows f
LEFT JOIN bots b ON f.bot_id = b.id
WHERE b.id IS NULL;
```

**Prevention:** Use proper cascade deletes on FKs
