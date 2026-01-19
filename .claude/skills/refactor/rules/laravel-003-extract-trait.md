---
id: laravel-003-extract-trait
title: Extract Trait Refactoring
impact: MEDIUM
impactDescription: "Share common functionality across classes using traits"
category: laravel
tags: [extract, trait, reuse, dry]
relatedRules: [laravel-001-extract-method, smell-002-duplicate-code]
---

## Code Smell

- Same methods in multiple classes
- Copy-pasted code across models
- Similar behavior needing shared
- Multiple classes with same helper methods

## Root Cause

1. Copy-paste development
2. Lack of shared abstractions
3. Fear of changing existing code
4. No clear place for shared logic
5. Organic codebase growth

## When to Apply

**Apply when:**
- Same code in 3+ places
- Behavior is truly shared
- No inheritance makes sense
- Methods are self-contained

**Don't apply when:**
- Only 2 places have code
- Behavior varies significantly
- Would create confusion
- Better as service

## Solution

### Before

```php
// app/Models/Bot.php
class Bot extends Model
{
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function logActivity(string $action): void
    {
        Log::info("Bot {$action}", ['bot_id' => $this->id]);
    }
}

// app/Models/Conversation.php
class Conversation extends Model
{
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function logActivity(string $action): void
    {
        Log::info("Conversation {$action}", ['conversation_id' => $this->id]);
    }
}
```

### After

```php
// app/Models/Traits/HasOwner.php
trait HasOwner
{
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

// app/Models/Traits/HasStatus.php
trait HasStatus
{
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function activate(): void
    {
        $this->update(['status' => 'active']);
    }

    public function deactivate(): void
    {
        $this->update(['status' => 'inactive']);
    }
}

// app/Models/Traits/LogsActivity.php
trait LogsActivity
{
    public function logActivity(string $action, array $extra = []): void
    {
        Log::info("{$this->getTable()} {$action}", [
            'id' => $this->id,
            'model' => get_class($this),
            ...$extra,
        ]);
    }
}

// app/Models/Bot.php
class Bot extends Model
{
    use HasOwner, HasStatus, LogsActivity;
}

// app/Models/Conversation.php
class Conversation extends Model
{
    use HasOwner, HasStatus, LogsActivity;
}
```

### Step-by-Step

1. **Identify duplicate code**
   ```bash
   # Find similar methods
   grep -rn "scopeActive" app/Models/
   ```

2. **Create trait file**
   ```bash
   mkdir -p app/Models/Traits
   touch app/Models/Traits/HasOwner.php
   ```

3. **Move shared methods**
   - Copy method to trait
   - Make it generic (use `$this->getTable()` etc.)
   - Add proper types

4. **Use trait in models**
   ```php
   class Bot extends Model
   {
       use HasOwner;
   }
   ```

5. **Remove duplicate code**
   - Delete original methods
   - Run tests

## Verification

```bash
# Verify traits are used
grep -rn "use HasOwner" app/Models/

# Run tests
php artisan test

# Check no duplication
# Methods should exist only in traits
```

## Anti-Patterns

- **Trait hell**: Too many traits on one class
- **State in traits**: Traits with complex state
- **Trait conflict**: Same method in multiple traits
- **Hidden complexity**: Traits obscuring behavior

## Project-Specific Notes

**BotFacebook Context:**
- Traits location: `app/Models/Traits/`
- Common traits: HasOwner, HasStatus, HasUuid
- Models using traits: Bot, Conversation, KnowledgeBase
- Keep traits focused (one concern each)
