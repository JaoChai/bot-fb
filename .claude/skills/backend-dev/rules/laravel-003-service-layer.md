---
id: laravel-003-service-layer
title: Service Layer Pattern
impact: CRITICAL
impactDescription: "Ensures testable, maintainable code with clear separation of concerns"
category: laravel
tags: [service, pattern, architecture, testing]
relatedRules: [laravel-001-thin-controller, laravel-004-formrequest]
---

## Why This Matters

The service layer pattern keeps controllers thin and business logic centralized. Services are easier to test, reuse, and maintain. Without services, controllers become bloated "god classes" that are hard to test and modify.

## Bad Example

```php
// Problem: Fat controller with business logic
class BotController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([...]);

        // Business logic in controller = bad
        DB::transaction(function () use ($data) {
            $bot = Bot::create($data);
            $bot->settings()->create(['theme' => 'default']);
            $bot->flows()->create(['name' => 'Main', 'is_default' => true]);

            // External API call
            Http::post('external-service.com/notify', [...]);
        });

        return $bot;
    }
}
```

**Why it's wrong:**
- Business logic in controller (not testable)
- Transaction scope unclear
- External API mixed with persistence
- No reusability

## Good Example

```php
// Solution: Service class with business logic
class BotService
{
    public function __construct(
        private NotificationService $notifications
    ) {}

    public function create(User $user, array $data): Bot
    {
        return DB::transaction(function () use ($user, $data) {
            $bot = $user->bots()->create([
                'name' => $data['name'],
                'platform' => $data['platform'],
            ]);

            $bot->settings()->create([
                'theme' => $data['theme'] ?? 'default',
            ]);

            $bot->flows()->create([
                'name' => 'Main Flow',
                'is_default' => true,
            ]);

            return $bot->load(['settings', 'flows']);
        });
    }
}

// Thin controller
class BotController extends Controller
{
    public function __construct(
        private BotService $botService
    ) {}

    public function store(StoreBotRequest $request): BotResource
    {
        $bot = $this->botService->create(
            auth()->user(),
            $request->validated()
        );

        return new BotResource($bot);
    }
}
```

**Why it's better:**
- Business logic in testable service
- Controller just coordinates
- Clear transaction boundaries
- Dependencies injected (mockable)
- Reusable across controllers

## Project-Specific Notes

**BotFacebook Service Organization:**

```
app/Services/
├── BotService.php           # Bot CRUD operations
├── ConversationService.php  # Conversation management
├── AI/
│   ├── RAGService.php       # AI orchestration
│   ├── OpenRouterService.php # LLM API calls
│   └── EmbeddingService.php # Vector generation
├── Platform/
│   ├── LINEService.php      # LINE API
│   └── TelegramService.php  # Telegram API
└── Search/
    ├── SemanticSearchService.php
    └── HybridSearchService.php
```

**Service Testing Pattern:**
```php
class BotServiceTest extends TestCase
{
    public function test_creates_bot_with_default_flow()
    {
        $user = User::factory()->create();
        $service = new BotService();

        $bot = $service->create($user, [
            'name' => 'Test Bot',
            'platform' => 'line',
        ]);

        $this->assertDatabaseHas('bots', ['name' => 'Test Bot']);
        $this->assertDatabaseHas('flows', ['bot_id' => $bot->id, 'is_default' => true]);
    }
}
```

## References

- [Laravel Service Container](https://laravel.com/docs/container)
- Related rule: laravel-001-thin-controller
