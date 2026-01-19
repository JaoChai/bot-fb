---
id: backend-006-lazy-loading
title: Avoid Lazy Loading in Services
impact: MEDIUM
impactDescription: "Database queries in constructors cause unexpected load"
category: backend
tags: [laravel, service, performance, constructor]
relatedRules: [backend-005-service-dependencies, perf-001-n-plus-one]
---

## Why This Matters

Database queries or heavy operations in constructors execute on every service instantiation, even when those features aren't used. This slows down the entire application.

## Bad Example

```php
class BotService
{
    private Collection $availableModels;
    private array $pricingData;

    public function __construct()
    {
        // These run on EVERY request that instantiates BotService
        $this->availableModels = Model::where('active', true)->get();
        $this->pricingData = Http::get('https://api.pricing.com/data')->json();
    }

    public function create(array $data): Bot
    {
        // May not even use availableModels
        return Bot::create($data);
    }
}
```

**Why it's wrong:**
- DB query on every instantiation
- HTTP call blocks construction
- Wasted resources
- Harder to test

## Good Example

```php
class BotService
{
    private ?Collection $availableModels = null;

    public function __construct(
        private CacheService $cache
    ) {}

    // Load only when needed
    public function getAvailableModels(): Collection
    {
        if ($this->availableModels === null) {
            $this->availableModels = $this->cache->remember(
                'available_models',
                3600,
                fn() => Model::where('active', true)->get()
            );
        }

        return $this->availableModels;
    }

    public function create(array $data): Bot
    {
        return Bot::create($data);
    }

    public function validateModel(string $model): bool
    {
        return $this->getAvailableModels()->contains('name', $model);
    }
}
```

**Why it's better:**
- No constructor queries
- Lazy loaded when needed
- Cached for performance
- Easy to test

## Review Checklist

- [ ] No `Model::` queries in constructors
- [ ] No HTTP calls in constructors
- [ ] No file operations in constructors
- [ ] Heavy data loaded lazily or cached
- [ ] Config data accessed via `config()` helper

## Detection

```bash
# DB queries in constructors
grep -A 20 "public function __construct" app/Services/*.php | grep "::where\|::find\|::all\|DB::"

# HTTP in constructors
grep -A 20 "public function __construct" app/Services/*.php | grep "Http::\|file_get_contents"
```

## Project-Specific Notes

**BotFacebook Lazy Loading Pattern:**

```php
class ModelTierSelector
{
    private ?array $tiers = null;

    public function __construct(
        private CacheService $cache
    ) {}

    // Lazy loaded with caching
    private function getTiers(): array
    {
        if ($this->tiers === null) {
            $this->tiers = $this->cache->remember(
                'model_tiers',
                86400, // 24 hours
                fn() => config('llm-models.tiers')
            );
        }

        return $this->tiers;
    }

    public function selectModel(string $tier, array $requirements): string
    {
        $tiers = $this->getTiers();
        // Use cached data...
    }
}
```
