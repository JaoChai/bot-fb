---
id: backend-001-thin-controller
title: Thin Controller Pattern
impact: HIGH
impactDescription: "Fat controllers are hard to test and maintain"
category: backend
tags: [laravel, controller, architecture, solid]
relatedRules: [backend-002-service-layer, backend-003-formrequest]
---

## Why This Matters

Controllers should only handle HTTP concerns (request/response). Business logic in controllers is hard to test, reuse, and maintain. It violates Single Responsibility Principle.

## Bad Example

```php
public function store(Request $request)
{
    // Validation in controller
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'system_prompt' => 'required|string',
    ]);

    // Business logic in controller
    $bot = new Bot();
    $bot->user_id = auth()->id();
    $bot->name = $validated['name'];
    $bot->system_prompt = $validated['system_prompt'];
    $bot->slug = Str::slug($validated['name']);
    $bot->save();

    // More business logic
    event(new BotCreated($bot));
    Cache::forget("user_{$bot->user_id}_bots");

    // Manual response formatting
    return response()->json([
        'status' => 'success',
        'data' => $bot,
    ]);
}
```

**Why it's wrong:**
- Validation mixed with logic
- Business logic in controller
- Hard to unit test
- Can't reuse creation logic

## Good Example

```php
public function store(StoreBotRequest $request)
{
    $bot = $this->botService->create(
        auth()->user(),
        $request->validated()
    );

    return new BotResource($bot);
}

// In BotService
public function create(User $user, array $data): Bot
{
    $bot = $user->bots()->create([
        'name' => $data['name'],
        'system_prompt' => $data['system_prompt'],
        'slug' => Str::slug($data['name']),
    ]);

    event(new BotCreated($bot));
    Cache::forget("user_{$user->id}_bots");

    return $bot;
}
```

**Why it's better:**
- Controller only handles HTTP
- Service contains business logic
- Easy to unit test service
- Logic reusable from CLI, jobs, etc.

## Review Checklist

- [ ] Controller methods < 20 lines
- [ ] No `new Model()` in controllers
- [ ] Validation in FormRequest classes
- [ ] Business logic in Services
- [ ] Response formatting in Resources

## Detection

```bash
# Fat controllers (>100 lines in method)
awk '/public function/{count=0} {count++} count>100{print FILENAME":"NR}' app/Http/Controllers/**/*.php

# Direct model creation in controller
grep -rn "new Bot\|new User\|new Message" --include="*.php" app/Http/Controllers/
```

## Project-Specific Notes

**BotFacebook Controller Pattern:**

```php
class BotController extends Controller
{
    public function __construct(
        private BotService $botService
    ) {}

    // ~10 lines max
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
}
```
