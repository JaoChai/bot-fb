---
id: backend-008-relationships
title: Define All Relationships
impact: MEDIUM
impactDescription: "Missing relationships lead to manual queries and N+1 problems"
category: backend
tags: [laravel, eloquent, relationships, orm]
relatedRules: [perf-001-n-plus-one, backend-007-model-logic]
---

## Why This Matters

Eloquent relationships enable eager loading, automatic joins, and clean query syntax. Missing relationships force manual queries and cause N+1 problems.

## Bad Example

```php
class Bot extends Model
{
    // Missing relationship definition
}

// Manual query instead of relationship
$conversations = Conversation::where('bot_id', $bot->id)->get();

// N+1 problem without eager loading
foreach ($bots as $bot) {
    $count = Conversation::where('bot_id', $bot->id)->count();
}

// Missing inverse relationship
class Conversation extends Model
{
    public function bot() { return $this->belongsTo(Bot::class); }
    // Missing: user(), messages()
}
```

**Why it's wrong:**
- Can't eager load
- Manual queries everywhere
- N+1 performance issues
- Inconsistent access patterns

## Good Example

```php
class Bot extends Model
{
    // All relationships defined
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function messages(): HasManyThrough
    {
        return $this->hasManyThrough(Message::class, Conversation::class);
    }

    public function knowledgeDocuments(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }

    // Useful relationship variants
    public function activeConversations(): HasMany
    {
        return $this->conversations()->where('status', 'active');
    }

    public function latestConversation(): HasOne
    {
        return $this->hasOne(Conversation::class)->latestOfMany();
    }
}

// Usage with eager loading
$bots = Bot::with(['conversations', 'user'])->get();

// Relationship methods
$bot->conversations()->count();
$bot->latestConversation;
```

**Why it's better:**
- Eager loading possible
- Clean syntax
- IDE autocomplete
- Consistent patterns

## Review Checklist

- [ ] All foreign keys have relationships
- [ ] Inverse relationships defined
- [ ] `HasManyThrough` for deep relations
- [ ] Useful variants (active, latest) added
- [ ] Return types specified

## Detection

```bash
# Foreign keys without relationships
grep -rn "_id'" --include="*.php" app/Models/ | head -20

# Manual where queries (should be relationship)
grep -rn "where('.*_id'" --include="*.php" app/

# Missing inverse relationships
# Compare belongsTo count vs hasMany count in models
```

## Project-Specific Notes

**BotFacebook Relationship Map:**

```php
// Bot relationships
class Bot extends Model
{
    public function user(): BelongsTo;
    public function conversations(): HasMany;
    public function messages(): HasManyThrough;
    public function knowledgeDocuments(): HasMany;
    public function toolExecutions(): HasMany;

    // Variants
    public function activeConversations(): HasMany;
    public function recentConversations(): HasMany;
    public function latestConversation(): HasOne;
}

// Conversation relationships
class Conversation extends Model
{
    public function bot(): BelongsTo;
    public function user(): BelongsTo;
    public function messages(): HasMany;
    public function latestMessage(): HasOne;
    public function participant(): BelongsTo; // Platform user
}

// Message relationships
class Message extends Model
{
    public function conversation(): BelongsTo;
    public function bot(): BelongsTo; // Via conversation
    public function toolCalls(): HasMany;
}

// Enable efficient queries
Bot::with([
    'conversations' => fn($q) => $q->latest()->limit(5),
    'conversations.latestMessage'
])->get();
```
