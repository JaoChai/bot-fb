---
id: laravel-005-query-scope
title: Extract Query Scope Refactoring
impact: MEDIUM
impactDescription: "Move repeated query constraints to model scopes"
category: laravel
tags: [query, scope, eloquent, dry]
relatedRules: [db-001-eager-loading, laravel-003-extract-trait]
---

## Code Smell

- Same where() clause repeated
- Complex queries duplicated
- Query logic in controllers
- Hard to test query conditions

## Root Cause

1. Query logic added ad-hoc
2. No awareness of scopes
3. Copy-paste development
4. Queries evolved separately
5. No query pattern established

## When to Apply

**Apply when:**
- Same where() in 3+ places
- Complex query logic
- Query represents domain concept
- Need to chain with other queries

**Don't apply when:**
- One-off query
- Query is truly unique
- Would obscure intent

## Solution

### Before

```php
// In multiple places:

// Controller
$bots = Bot::where('user_id', auth()->id())
    ->where('status', 'active')
    ->orderBy('updated_at', 'desc')
    ->get();

// Service
$bots = Bot::where('user_id', $user->id)
    ->where('status', 'active')
    ->where('platform', 'line')
    ->get();

// Another service
$count = Bot::where('user_id', $userId)
    ->where('status', 'active')
    ->count();

// Repository
$latest = Bot::where('user_id', auth()->id())
    ->where('status', 'active')
    ->orderBy('updated_at', 'desc')
    ->first();
```

### After

```php
// app/Models/Bot.php
class Bot extends Model
{
    // Local scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('status', 'inactive');
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;
        return $query->where('user_id', $userId);
    }

    public function scopeForPlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }

    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderBy('updated_at', 'desc');
    }

    public function scopeWithActivePrompt(Builder $query): Builder
    {
        return $query->whereHas('systemPrompts', fn($q) =>
            $q->where('is_active', true)
        );
    }

    // Global scope for multi-tenant (if needed)
    protected static function booted(): void
    {
        // Uncomment if auto-filtering needed
        // static::addGlobalScope('user', function (Builder $builder) {
        //     if (auth()->check()) {
        //         $builder->where('user_id', auth()->id());
        //     }
        // });
    }
}

// Usage in code:

// Controller
$bots = Bot::forUser(auth()->user())
    ->active()
    ->latest()
    ->get();

// Service
$bots = Bot::forUser($user)
    ->active()
    ->forPlatform('line')
    ->get();

// Another service
$count = Bot::forUser($userId)
    ->active()
    ->count();

// Chaining
$latest = Bot::forUser(auth()->user())
    ->active()
    ->withActivePrompt()
    ->latest()
    ->first();
```

### Step-by-Step

1. **Identify repeated queries**
   ```bash
   grep -rn "where('status', 'active')" app/
   grep -rn "where('user_id'" app/
   ```

2. **Create scopes in model**
   ```php
   public function scopeActive(Builder $query): Builder
   {
       return $query->where('status', 'active');
   }
   ```

3. **Replace query calls**
   - Find all occurrences
   - Replace with scope
   - Run tests

4. **Add type hints**
   - Builder return type
   - Parameter types

## Verification

```bash
# Test scopes work
php artisan test --filter BotTest

# Verify no raw where clauses remain
grep -rn "where('status', 'active')" app/Http/
# Should return nothing (scopes used instead)
```

## Anti-Patterns

- **God scope**: Scope doing too much
- **Hidden filters**: Unexpected global scopes
- **Scope in controller**: Should be in model
- **Inconsistent naming**: Use consistent verb patterns

## Project-Specific Notes

**BotFacebook Context:**
- Common scopes: active(), forUser(), forPlatform()
- Global scope: Be careful with multi-tenant
- Location: In model file
- Naming: scope{Name} with camelCase
