---
id: gotcha-006-foreign-key-error
title: Foreign Key Constraint Errors
impact: HIGH
impactDescription: "Cannot delete/update when dependent records exist"
category: gotcha
tags: [gotcha, foreign-key, constraint, delete]
relatedRules: [safety-004-cascade-deletes, migration-006-foreign-key]
---

## Why This Matters

Foreign key constraints prevent deleting parent records when children exist. This is good for data integrity but causes unexpected errors if not handled properly.

## Bad Example

```php
// Tries to delete bot with existing messages
$bot->delete();
// ERROR: violates foreign key constraint "messages_bot_id_fkey"
// DETAIL: Key (id)=(123) is still referenced from table "messages"

// Or tries to update with invalid FK
Message::create([
    'conversation_id' => 99999, // Doesn't exist
    'content' => 'Hello',
]);
// ERROR: violates foreign key constraint
```

**Why it's wrong:**
- Unexpected runtime errors
- No handling for constraints
- User sees cryptic error

## Good Example

```php
// Handle delete with children
public function deleteBot(Bot $bot): void
{
    DB::transaction(function () use ($bot) {
        // Check for dependent records
        if ($bot->messages()->exists()) {
            throw new BotHasMessagesException(
                'Cannot delete bot with existing messages. Archive instead.'
            );
        }

        // Or soft delete
        $bot->delete(); // SoftDeletes trait
    });
}

// Validate FK before insert
public function createMessage(array $data): Message
{
    // Validate conversation exists
    if (!Conversation::find($data['conversation_id'])) {
        throw new InvalidConversationException(
            'Conversation does not exist'
        );
    }

    return Message::create($data);
}
```

**Why it's better:**
- Graceful error handling
- User-friendly messages
- Data integrity maintained

## Project-Specific Notes

**BotFacebook FK Error Handling:**

```php
// Global exception handler
// app/Exceptions/Handler.php
public function render($request, Throwable $e)
{
    if ($e instanceof \Illuminate\Database\QueryException) {
        if (str_contains($e->getMessage(), 'foreign key constraint')) {
            return response()->json([
                'message' => 'Cannot delete: related records exist',
            ], 409);
        }
    }
    return parent::render($request, $e);
}
```
