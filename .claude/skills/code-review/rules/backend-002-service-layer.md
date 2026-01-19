---
id: backend-002-service-layer
title: Service Layer Pattern
impact: HIGH
impactDescription: "Business logic scattered across codebase is unmaintainable"
category: backend
tags: [laravel, service, architecture, pattern]
relatedRules: [backend-001-thin-controller, backend-005-service-dependencies]
---

## Why This Matters

Services encapsulate business logic in reusable, testable classes. Without services, logic gets duplicated across controllers, jobs, and commands.

## Bad Example

```php
// Duplicated logic in controller
class BotController
{
    public function activate(Bot $bot)
    {
        $bot->is_active = true;
        $bot->activated_at = now();
        $bot->save();
        event(new BotActivated($bot));
        // 20 more lines...
    }
}

// Same logic in job
class ProcessBotJob
{
    public function handle()
    {
        $this->bot->is_active = true;
        $this->bot->activated_at = now();
        $this->bot->save();
        event(new BotActivated($this->bot));
        // Same 20 lines duplicated...
    }
}
```

**Why it's wrong:**
- Logic duplicated
- Changes require multiple updates
- Hard to test
- No single source of truth

## Good Example

```php
// Service with all business logic
class BotService
{
    public function activate(Bot $bot): Bot
    {
        $bot->update([
            'is_active' => true,
            'activated_at' => now(),
        ]);

        event(new BotActivated($bot));

        return $bot;
    }
}

// Controller uses service
public function activate(Bot $bot)
{
    $this->authorize('update', $bot);
    return new BotResource(
        $this->botService->activate($bot)
    );
}

// Job uses same service
public function handle(BotService $botService)
{
    $botService->activate($this->bot);
}
```

**Why it's better:**
- Single source of truth
- Reusable across contexts
- Easy to unit test
- Changes in one place

## Review Checklist

- [ ] Business logic in `app/Services/`
- [ ] Services injected via constructor
- [ ] No duplicated logic across classes
- [ ] Services return models, not responses
- [ ] Services don't use `auth()` (pass user as param)

## Detection

```bash
# Duplicated logic patterns
grep -rn "->save()" --include="*.php" app/Http/Controllers/ app/Jobs/

# Missing services
ls app/Services/ | wc -l  # Should have many services

# Controller calling other controllers (red flag)
grep -rn "Controller::class" --include="*.php" app/Http/Controllers/
```

## Project-Specific Notes

**BotFacebook Service Organization:**

```
app/Services/
├── Bot/
│   ├── BotService.php           # CRUD operations
│   └── BotConfigService.php     # Configuration logic
├── AI/
│   ├── RAGService.php           # Main orchestrator
│   ├── OpenRouterService.php    # LLM API
│   └── EmbeddingService.php     # Vector operations
├── Search/
│   ├── SemanticSearchService.php
│   └── HybridSearchService.php
└── Platform/
    ├── LINEService.php
    └── TelegramService.php
```

**Service Pattern:**

```php
class BotService
{
    public function __construct(
        private EmbeddingService $embeddingService,
        private CacheService $cacheService
    ) {}

    public function create(User $user, array $data): Bot
    {
        return DB::transaction(function () use ($user, $data) {
            $bot = $user->bots()->create($data);
            $this->cacheService->invalidateBotList($user->id);
            return $bot;
        });
    }
}
```
