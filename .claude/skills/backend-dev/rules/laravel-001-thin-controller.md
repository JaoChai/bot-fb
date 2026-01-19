---
id: laravel-001-thin-controller
title: Thin Controller Pattern
impact: HIGH
impactDescription: "Ensures maintainable, testable code with proper separation of concerns"
category: laravel
tags: [controller, architecture, pattern, testing]
relatedRules: [laravel-003-service-layer, laravel-004-formrequest]
---

## Why This Matters

Controllers should be thin - they coordinate request/response flow, not implement business logic. Fat controllers are hard to test, hard to reuse, and violate single responsibility. Business logic belongs in services.

## Bad Example

```php
// Problem: Fat controller with business logic
class BotController extends Controller
{
    public function store(Request $request)
    {
        // Validation in controller
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'platform' => 'required|in:line,telegram',
        ]);

        // Business logic in controller
        $bot = new Bot();
        $bot->user_id = auth()->id();
        $bot->name = $data['name'];
        $bot->platform = $data['platform'];
        $bot->api_key = Str::random(32);
        $bot->save();

        // More business logic
        $bot->settings()->create(['theme' => 'default']);
        $bot->flows()->create(['name' => 'Main', 'is_default' => true]);

        // External API call mixed in
        Http::post('webhook.example.com', ['event' => 'bot.created']);

        return response()->json($bot);
    }
}
```

**Why it's wrong:**
- 30+ lines in single method
- Business logic not testable
- Not reusable (can't create bot from CLI/job)
- Mixed concerns

## Good Example

```php
// Thin Controller - just coordinates
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

    public function update(UpdateBotRequest $request, Bot $bot): BotResource
    {
        $this->authorize('update', $bot);

        $bot = $this->botService->update($bot, $request->validated());

        return new BotResource($bot);
    }

    public function destroy(Bot $bot)
    {
        $this->authorize('delete', $bot);

        $this->botService->delete($bot);

        return response()->noContent();
    }
}
```

**Why it's better:**
- ~5 lines per method
- Business logic in testable service
- Validation in FormRequest
- Transformation in Resource
- Each class has one job

## Project-Specific Notes

**BotFacebook Controller Guidelines:**
1. Max 10 lines per action method
2. Inject service via constructor
3. Use FormRequest for validation
4. Use API Resource for response
5. Call `$this->authorize()` for policies

**Controller Structure:**
```php
class XxxController extends Controller
{
    public function __construct(private XxxService $service) {}

    public function index(): ResourceCollection { ... }
    public function store(StoreXxxRequest $request): XxxResource { ... }
    public function show(Xxx $xxx): XxxResource { ... }
    public function update(UpdateXxxRequest $request, Xxx $xxx): XxxResource { ... }
    public function destroy(Xxx $xxx): Response { ... }
}
```

## References

- [Laravel Controllers](https://laravel.com/docs/controllers)
- Related rule: laravel-003-service-layer
