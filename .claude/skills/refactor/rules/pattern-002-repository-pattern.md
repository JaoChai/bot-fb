---
id: pattern-002-repository-pattern
title: Repository Pattern Refactoring
impact: MEDIUM
impactDescription: "Abstract data access behind repository interface"
category: pattern
tags: [design-pattern, repository, data-access, abstraction]
relatedRules: [laravel-002-extract-service, db-001-eager-loading]
---

## Code Smell

- Eloquent queries scattered across controllers
- Same query logic in multiple services
- Hard to test without database
- Direct model coupling everywhere
- Query logic duplicated

## Root Cause

1. Started with simple queries
2. Queries grew more complex
3. No abstraction layer
4. ActiveRecord pattern overused
5. Testing difficulties emerged

## When to Apply

**Apply when:**
- Same query in 3+ places
- Complex query logic
- Need to mock data access
- Multiple data sources possible
- Domain logic mixed with queries

**Don't apply when:**
- Simple CRUD operations
- Laravel conventions work fine
- Would add unnecessary layer
- Small application

## Solution

### Before (Scattered Queries)

```php
// BotController.php
class BotController extends Controller
{
    public function index()
    {
        $bots = Bot::where('user_id', auth()->id())
            ->with(['platform', 'knowledgeBases'])
            ->withCount('conversations')
            ->orderBy('updated_at', 'desc')
            ->get();

        return BotResource::collection($bots);
    }

    public function search(Request $request)
    {
        $bots = Bot::where('user_id', auth()->id())
            ->where('name', 'like', "%{$request->q}%")
            ->with(['platform', 'knowledgeBases'])
            ->orderBy('updated_at', 'desc')
            ->get();

        return BotResource::collection($bots);
    }
}

// BotAnalyticsService.php - Same queries!
class BotAnalyticsService
{
    public function getUserBots(User $user)
    {
        return Bot::where('user_id', $user->id)
            ->with(['platform', 'knowledgeBases'])
            ->withCount('conversations')
            ->get();
    }
}

// DashboardController.php - Again!
class DashboardController extends Controller
{
    public function index()
    {
        $bots = Bot::where('user_id', auth()->id())
            ->withCount(['conversations', 'messages'])
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get();

        return view('dashboard', compact('bots'));
    }
}
```

### After (Repository Pattern)

```php
// Step 1: Define interface
interface BotRepositoryInterface
{
    public function findByUser(User $user): Collection;
    public function findByUserWithStats(User $user): Collection;
    public function search(User $user, string $query): Collection;
    public function findById(int $id): ?Bot;
    public function findRecentByUser(User $user, int $limit = 5): Collection;
}

// Step 2: Implement repository
class EloquentBotRepository implements BotRepositoryInterface
{
    public function findByUser(User $user): Collection
    {
        return Bot::where('user_id', $user->id)
            ->with(['platform', 'knowledgeBases'])
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    public function findByUserWithStats(User $user): Collection
    {
        return Bot::where('user_id', $user->id)
            ->with(['platform', 'knowledgeBases'])
            ->withCount(['conversations', 'messages'])
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    public function search(User $user, string $query): Collection
    {
        return Bot::where('user_id', $user->id)
            ->where('name', 'like', "%{$query}%")
            ->with(['platform', 'knowledgeBases'])
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    public function findById(int $id): ?Bot
    {
        return Bot::with(['platform', 'settings', 'knowledgeBases'])->find($id);
    }

    public function findRecentByUser(User $user, int $limit = 5): Collection
    {
        return Bot::where('user_id', $user->id)
            ->withCount(['conversations', 'messages'])
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get();
    }
}

// Step 3: Bind in service provider
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            BotRepositoryInterface::class,
            EloquentBotRepository::class
        );
    }
}

// Step 4: Use in controllers/services
class BotController extends Controller
{
    public function __construct(
        private BotRepositoryInterface $bots
    ) {}

    public function index()
    {
        $bots = $this->bots->findByUserWithStats(auth()->user());
        return BotResource::collection($bots);
    }

    public function search(Request $request)
    {
        $bots = $this->bots->search(auth()->user(), $request->q);
        return BotResource::collection($bots);
    }
}

class BotAnalyticsService
{
    public function __construct(
        private BotRepositoryInterface $bots
    ) {}

    public function getUserBots(User $user): Collection
    {
        return $this->bots->findByUserWithStats($user);
    }
}
```

### Testing Benefits

```php
// Create fake repository for testing
class FakeBotRepository implements BotRepositoryInterface
{
    private Collection $bots;

    public function __construct(array $bots = [])
    {
        $this->bots = collect($bots);
    }

    public function findByUser(User $user): Collection
    {
        return $this->bots->where('user_id', $user->id);
    }

    // ... other methods
}

// In test
class BotControllerTest extends TestCase
{
    public function test_index_returns_user_bots(): void
    {
        $user = User::factory()->make(['id' => 1]);
        $bots = [
            Bot::factory()->make(['id' => 1, 'user_id' => 1, 'name' => 'Bot 1']),
            Bot::factory()->make(['id' => 2, 'user_id' => 1, 'name' => 'Bot 2']),
        ];

        $this->app->instance(
            BotRepositoryInterface::class,
            new FakeBotRepository($bots)
        );

        $this->actingAs($user)
            ->getJson('/api/bots')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
```

### Lightweight Alternative: Query Scopes

```php
// If full repository is overkill, use scopes
class Bot extends Model
{
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeWithStats(Builder $query): Builder
    {
        return $query
            ->with(['platform', 'knowledgeBases'])
            ->withCount(['conversations', 'messages']);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where('name', 'like', "%{$search}%");
    }
}

// Usage
Bot::forUser($user)->withStats()->search($query)->get();
```

## Step-by-Step

1. **Identify repeated queries**
   ```bash
   grep -rn "Bot::where" app/
   ```

2. **Define interface**
   - List needed operations
   - Keep it focused

3. **Implement repository**
   - Extract queries
   - Add proper eager loading

4. **Bind to container**
   - Interface → Implementation

5. **Replace direct queries**
   - Inject repository
   - Use repository methods

6. **Add tests**
   - Test repository separately
   - Use fake in other tests

## Verification

```bash
# Check no direct queries remain
grep -n "Bot::where" app/Http/Controllers/
# Should return nothing (use repository)

# Verify repository is used
grep -rn "BotRepositoryInterface" app/
```

## Project-Specific Notes

**BotFacebook Context:**
- Good candidates: Bot, Conversation, Message queries
- Location: `app/Repositories/`
- Consider: Query scopes for simple cases
- Full repository for complex/testable queries
