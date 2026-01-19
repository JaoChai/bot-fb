---
id: perf-001-n-plus-one
title: N+1 Query Detection
impact: HIGH
impactDescription: "N+1 queries can make pages 100x slower"
category: perf
tags: [performance, database, eloquent, n+1]
relatedRules: [backend-008-relationships, perf-003-over-fetching]
---

## Why This Matters

N+1 queries occur when you load a list then query each item individually. A page loading 100 bots could make 101 queries instead of 2.

## Bad Example

```php
// Controller
public function index()
{
    $bots = Bot::all(); // 1 query
    return BotResource::collection($bots);
}

// Resource
class BotResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'user' => $this->user->name, // N queries!
            'conversation_count' => $this->conversations->count(), // N more queries!
        ];
    }
}
// Total: 1 + N + N = 201 queries for 100 bots!
```

**Why it's wrong:**
- Each access triggers query
- Scales linearly with data
- Page load time explodes
- Database overwhelmed

## Good Example

```php
// Eager load relationships
public function index()
{
    $bots = Bot::with(['user', 'conversations'])->get(); // 3 queries total
    return BotResource::collection($bots);
}

// Or use withCount for counts
public function index()
{
    $bots = Bot::with('user')
        ->withCount('conversations')
        ->get(); // 2 queries
    return BotResource::collection($bots);
}

// Resource uses preloaded data
class BotResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'user' => $this->whenLoaded('user', fn() => $this->user->name),
            'conversation_count' => $this->conversations_count ?? 0,
        ];
    }
}
// Total: 2-3 queries regardless of bot count
```

**Why it's better:**
- Fixed query count
- Scales to any size
- Fast page loads
- Database happy

## Review Checklist

- [ ] All list endpoints use `with()` for relationships
- [ ] Use `withCount()` instead of `->count()` in loops
- [ ] Resources use `whenLoaded()` for optional relations
- [ ] No relationship access in loops without eager loading
- [ ] Query count checked in tests

## Detection

```bash
# Enable query logging
DB::listen(fn($q) => logger($q->sql, $q->bindings));

# Or use Laravel Debugbar
# Check for repeated similar queries

# Code patterns
grep -rn "->get()\|::all()" --include="*.php" app/Http/Controllers/
# Then check if relationships accessed in resource
```

## Project-Specific Notes

**BotFacebook N+1 Prevention:**

```php
// BotController - always eager load
public function index()
{
    $bots = auth()->user()
        ->bots()
        ->with(['latestConversation.latestMessage'])
        ->withCount(['conversations', 'messages'])
        ->latest()
        ->paginate();

    return BotResource::collection($bots);
}

// ConversationController
public function index(Bot $bot)
{
    $conversations = $bot->conversations()
        ->with(['latestMessage', 'participant'])
        ->withCount('messages')
        ->latest('updated_at')
        ->paginate();

    return ConversationResource::collection($conversations);
}

// Prevent lazy loading in development
// AppServiceProvider.php
public function boot(): void
{
    Model::preventLazyLoading(!app()->isProduction());
}
```
