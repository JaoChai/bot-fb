---
id: eloquent-002-query-scopes
title: Query Scopes
impact: HIGH
impactDescription: "Ensures reusable, readable query logic without duplication"
category: eloquent
tags: [eloquent, query, scope, model]
relatedRules: [eloquent-001-eager-loading]
---

## Why This Matters

Query scopes encapsulate common query constraints in the model, making them reusable and keeping controllers clean. Without scopes, you duplicate WHERE clauses across the codebase.

## Bad Example

```php
// Problem: Duplicated query logic everywhere
// In BotController
$bots = Bot::where('is_active', true)
    ->where('user_id', auth()->id())
    ->orderBy('created_at', 'desc')
    ->get();

// In BotService
$bots = Bot::where('is_active', true)
    ->where('user_id', $user->id)
    ->orderBy('created_at', 'desc')
    ->get();

// In Dashboard
$bots = Bot::where('is_active', true)
    ->where('user_id', auth()->id())
    ->where('platform', 'line')
    ->orderBy('created_at', 'desc')
    ->get();
```

**Why it's wrong:**
- Same queries repeated
- Change requires multiple edits
- Easy to forget conditions
- Controllers bloated with query logic

## Good Example

```php
// Model with scopes
class Bot extends Model
{
    // Local scope - called with scope prefix omitted
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    // Combined scope for common use case
    public function scopeUserActive($query, User $user)
    {
        return $query->active()->forUser($user)->recent();
    }
}

// Clean usage
$bots = Bot::userActive(auth()->user())->get();
$lineBots = Bot::active()->forPlatform('line')->get();
$recentBots = Bot::forUser($user)->recent()->limit(5)->get();

// Chainable with other methods
$bots = Bot::active()
    ->forUser($user)
    ->with('settings')
    ->paginate(20);
```

**Why it's better:**
- Reusable query constraints
- Single source of truth
- Readable, expressive code
- Easy to change globally

## Project-Specific Notes

**BotFacebook Common Scopes:**

```php
// Message model
public function scopeUnread($query)
{
    return $query->whereNull('read_at');
}

public function scopeFromBot($query)
{
    return $query->where('sender_type', 'bot');
}

// Conversation model
public function scopeActive($query)
{
    return $query->where('status', 'active');
}

public function scopeRecentActivity($query, int $hours = 24)
{
    return $query->where('last_message_at', '>', now()->subHours($hours));
}
```

**Global Scopes (auto-applied):**
```php
// Only show active bots by default
protected static function booted()
{
    static::addGlobalScope('active', function ($query) {
        $query->where('is_active', true);
    });
}

// Remove global scope when needed
Bot::withoutGlobalScope('active')->get();
```

## References

- [Laravel Query Scopes](https://laravel.com/docs/eloquent#query-scopes)
