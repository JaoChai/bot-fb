---
id: laravel-002-extract-service
title: Extract Service Refactoring
impact: HIGH
impactDescription: "Move business logic from controllers to dedicated service classes"
category: laravel
tags: [extract, service, controller, architecture]
relatedRules: [laravel-001-extract-method, smell-001-long-method]
---

## Code Smell

- Controller method > 20 lines
- Business logic in controller
- Same logic duplicated across controllers
- Controller testing requires mocking too much
- Hard to reuse logic

## Root Cause

1. "Just add it to controller" mindset
2. Tight deadlines
3. Unfamiliar with service pattern
4. No clear layer boundaries
5. Organic growth without refactoring

## When to Apply

**Apply when:**
- Controller > 50 lines
- Logic needed elsewhere
- Logic involves multiple models
- Testing is difficult

**Don't apply when:**
- Simple CRUD operations
- Logic is truly controller-specific
- Would create single-use service

## Solution

### Before

```php
class BotController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'platform' => 'required|in:line,telegram',
            'system_prompt' => 'required|string',
        ]);

        // Check user quota
        $user = auth()->user();
        $botCount = Bot::where('user_id', $user->id)->count();
        if ($botCount >= $user->max_bots) {
            return response()->json(['error' => 'Bot limit reached'], 422);
        }

        // Create bot
        $bot = Bot::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'platform' => $validated['platform'],
            'status' => 'draft',
        ]);

        // Create system prompt
        SystemPrompt::create([
            'bot_id' => $bot->id,
            'content' => $validated['system_prompt'],
            'version' => 1,
            'is_active' => true,
        ]);

        // Create default settings
        BotSetting::create([
            'bot_id' => $bot->id,
            'model' => 'gpt-4',
            'temperature' => 0.7,
            'max_tokens' => 1000,
        ]);

        // Log creation
        Log::info('Bot created', ['bot_id' => $bot->id, 'user_id' => $user->id]);

        return new BotResource($bot->load(['systemPrompt', 'settings']));
    }
}
```

### After

```php
// app/Http/Controllers/Api/BotController.php
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

// app/Http/Requests/StoreBotRequest.php
class StoreBotRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'platform' => 'required|in:line,telegram',
            'system_prompt' => 'required|string',
        ];
    }
}

// app/Services/BotService.php
class BotService
{
    public function create(User $user, array $data): Bot
    {
        $this->validateQuota($user);

        return DB::transaction(function () use ($user, $data) {
            $bot = $this->createBot($user, $data);
            $this->createSystemPrompt($bot, $data['system_prompt']);
            $this->createDefaultSettings($bot);

            Log::info('Bot created', ['bot_id' => $bot->id, 'user_id' => $user->id]);

            return $bot->load(['systemPrompt', 'settings']);
        });
    }

    private function validateQuota(User $user): void
    {
        $botCount = Bot::where('user_id', $user->id)->count();
        if ($botCount >= $user->max_bots) {
            throw new QuotaExceededException('Bot limit reached');
        }
    }

    private function createBot(User $user, array $data): Bot
    {
        return Bot::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'platform' => $data['platform'],
            'status' => 'draft',
        ]);
    }

    private function createSystemPrompt(Bot $bot, string $content): SystemPrompt
    {
        return SystemPrompt::create([
            'bot_id' => $bot->id,
            'content' => $content,
            'version' => 1,
            'is_active' => true,
        ]);
    }

    private function createDefaultSettings(Bot $bot): BotSetting
    {
        return BotSetting::create([
            'bot_id' => $bot->id,
            'model' => config('llm.default_model'),
            'temperature' => 0.7,
            'max_tokens' => 1000,
        ]);
    }
}
```

### Step-by-Step

1. **Create Service class**
   ```bash
   # Create file
   touch app/Services/BotService.php
   ```

2. **Move logic to service**
   - Copy business logic
   - Extract to methods
   - Add proper types

3. **Update controller**
   - Inject service
   - Replace logic with service call
   - Keep controller thin

4. **Create FormRequest**
   ```bash
   php artisan make:request StoreBotRequest
   ```

5. **Update tests**
   - Service tests for business logic
   - Controller tests for HTTP layer

## Verification

```bash
# Run all tests
php artisan test

# Check controller is thin
wc -l app/Http/Controllers/Api/BotController.php
# Should be < 50 lines per method

# Verify service is used
grep -r "BotService" app/Http/Controllers/
```

## Anti-Patterns

- **Anemic service**: Just moving code without abstraction
- **God service**: One service doing everything
- **Circular dependency**: Services depending on each other
- **Over-abstraction**: Service for simple CRUD

## Project-Specific Notes

**BotFacebook Context:**
- Services location: `app/Services/`
- Key services: RAGService, BotService, MessageService
- Pattern: Constructor injection
- Use transactions for multi-model operations
