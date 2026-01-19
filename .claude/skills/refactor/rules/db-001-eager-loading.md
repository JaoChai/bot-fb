---
id: db-001-eager-loading
title: Eager Loading Refactoring
impact: HIGH
impactDescription: "Fix N+1 queries with proper eager loading"
category: db
tags: [eager-loading, n-plus-one, eloquent, performance]
relatedRules: [db-002-query-optimization, laravel-002-extract-service]
---

## Code Smell

- Loop with database queries inside
- API response time increases with data size
- `DB::getQueryLog()` shows many similar queries
- Debugbar shows N+1 warning
- Each related model triggers separate query

## Root Cause

1. Lazy loading by default in Eloquent
2. Accessing relations in loops/blade
3. Missing `->with()` in queries
4. Relations added after initial implementation
5. Not monitoring query counts

## When to Apply

**Apply when:**
- Query count > N+1 (N = records)
- Same relation accessed in loop
- Response time grows linearly
- Debugbar shows N+1

**Don't apply when:**
- Single record fetches
- Relation rarely accessed
- Already using with()

## Solution

### Before (N+1 Problem)

```php
// Controller
class BotController extends Controller
{
    public function index()
    {
        $bots = Bot::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return BotResource::collection($bots);
    }
}

// Resource - triggers N+1
class BotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Each access = 1 query
            'message_count' => $this->messages()->count(),
            'platform' => $this->platform->name,
            'last_conversation' => $this->conversations()
                ->latest()
                ->first()?->started_at,
        ];
    }
}
// Result: 1 + N*3 queries (messages, platform, conversations)
```

### After (Eager Loading)

```php
// Controller
class BotController extends Controller
{
    public function index()
    {
        $bots = Bot::where('user_id', auth()->id())
            ->with([
                'platform:id,name',  // Select only needed columns
                'latestConversation',
            ])
            ->withCount('messages')  // Use withCount for counts
            ->orderBy('created_at', 'desc')
            ->get();

        return BotResource::collection($bots);
    }
}

// Model - add relationship for eager loading
class Bot extends Model
{
    public function latestConversation(): HasOne
    {
        return $this->hasOne(Conversation::class)
            ->latestOfMany();
    }
}

// Resource - uses eager loaded data
class BotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Already loaded via withCount
            'message_count' => $this->messages_count,
            // Already loaded via with
            'platform' => $this->platform?->name,
            // Already loaded via latestOfMany
            'last_conversation' => $this->latestConversation?->started_at,
        ];
    }
}
// Result: 3 queries total (bots + platforms + conversations)
```

### Step-by-Step

1. **Identify N+1 problem**
   ```php
   // Enable query log
   DB::enableQueryLog();

   // Run code
   $bots = Bot::all();
   foreach ($bots as $bot) {
       echo $bot->platform->name;
   }

   // Check queries
   dd(DB::getQueryLog());
   ```

2. **Add eager loading**
   ```php
   // Basic with()
   Bot::with('platform')->get();

   // Nested relations
   Bot::with('platform.settings')->get();

   // Select specific columns
   Bot::with('platform:id,name,icon')->get();

   // Multiple relations
   Bot::with(['platform', 'user', 'conversations'])->get();
   ```

3. **Use withCount for counts**
   ```php
   Bot::withCount('messages')->get();
   // Access as: $bot->messages_count

   // With conditions
   Bot::withCount([
       'messages',
       'messages as unread_count' => fn($q) => $q->whereNull('read_at')
   ])->get();
   ```

4. **Use withAggregate for sums/max/min**
   ```php
   Bot::withSum('messages', 'tokens_used')->get();
   // Access as: $bot->messages_sum_tokens_used

   Bot::withMax('conversations', 'updated_at')->get();
   ```

5. **Create specific relationships**
   ```php
   // In Model
   public function latestMessage(): HasOne
   {
       return $this->hasOne(Message::class)->latestOfMany();
   }

   public function oldestConversation(): HasOne
   {
       return $this->hasOne(Conversation::class)->oldestOfMany();
   }
   ```

## Verification

```bash
# Check query count before/after
php artisan tinker
>>> DB::enableQueryLog();
>>> $bots = Bot::with('platform')->get();
>>> count(DB::getQueryLog())
# Should be 2 (bots + platforms), not N+1
```

## Anti-Patterns

- **Over-eager loading**: Loading relations never used
- **Missing columns**: Forgetting foreign key in select
- **Wrong relation type**: Using hasMany when hasOne works
- **Ignoring withCount**: Using `->count()` in loops

## Project-Specific Notes

**BotFacebook Context:**
- Common N+1: Bot → Platform, Conversation → Messages
- Use Laravel Debugbar in development
- Add `->with()` in Controllers, not Resources
- Standard pattern: `->with(['relation:id,name'])`
