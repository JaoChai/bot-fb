---
id: migration-006-foreign-key
title: Foreign Key Best Practices
impact: HIGH
impactDescription: "Proper FK setup ensures data integrity and correct delete behavior"
category: migration
tags: [migration, foreign-key, constraint, relationship]
relatedRules: [safety-004-cascade-deletes]
---

## Why This Matters

Foreign keys enforce referential integrity - they prevent orphaned records and ensure relationships are valid. Choosing the wrong ON DELETE action can cause unexpected data loss or prevent legitimate deletions.

## Bad Example

```php
public function up(): void
{
    Schema::create('messages', function (Blueprint $table) {
        // No foreign key - orphans possible
        $table->unsignedBigInteger('conversation_id');

        // Cascade without consideration
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    });
}
```

**Why it's wrong:**
- Missing FK allows orphaned records
- Cascade may cause unintended mass deletion
- No explicit delete behavior

## Good Example

```php
public function up(): void
{
    Schema::create('messages', function (Blueprint $table) {
        $table->id();

        // Cascade: When conversation deleted, messages deleted too
        $table->foreignId('conversation_id')
              ->constrained()
              ->cascadeOnDelete();

        // Restrict: Cannot delete user with messages (preserve data)
        $table->foreignId('user_id')
              ->constrained()
              ->restrictOnDelete();

        // Set Null: Keep message, clear sender reference
        $table->foreignId('sender_id')
              ->nullable()
              ->constrained('users')
              ->nullOnDelete();

        $table->text('content');
        $table->timestamps();
    });
}
```

**Why it's better:**
- Explicit delete behavior per relationship
- Data integrity enforced
- Appropriate cascade/restrict choices

## Project-Specific Notes

**BotFacebook FK Patterns:**

| Child | Parent | Action | Reason |
|-------|--------|--------|--------|
| Bot | User | CASCADE | User gone = bots gone |
| Message | Conversation | CASCADE | Convo gone = msgs gone |
| Message | User | SET NULL | Keep message, clear author |
| Flow | Bot | CASCADE | Bot gone = flows gone |
| KnowledgeChunk | KnowledgeBase | CASCADE | KB gone = chunks gone |

```php
// Adding FK to existing column
Schema::table('bots', function (Blueprint $table) {
    $table->foreign('user_id')
          ->references('id')
          ->on('users')
          ->onDelete('cascade');
});
```
