---
id: gotcha-004-env-vs-config
title: Env vs Config Usage
impact: HIGH
impactDescription: "Prevents caching issues and ensures proper configuration in production"
category: gotcha
tags: [config, env, caching, gotcha]
relatedRules: [gotcha-001-config-null-coalesce]
---

## Why This Matters

In production, Laravel caches configuration files. After `config:cache`, `env()` calls return `null` outside of config files. Always use `config()` in application code and `env()` only in config files.

## Bad Example

```php
// Problem: env() in application code
class OpenRouterService
{
    public function __construct()
    {
        $this->apiKey = env('OPENROUTER_API_KEY'); // Returns null when config cached!
        $this->baseUrl = env('OPENROUTER_URL');
    }
}
```

**Why it's wrong:**
- Works in development, fails in production
- `php artisan config:cache` breaks it
- Hard to debug - code works locally
- Inconsistent behavior across environments

## Good Example

```php
// config/services.php - env() only here
'openrouter' => [
    'api_key' => env('OPENROUTER_API_KEY'),
    'base_url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
    'timeout' => env('OPENROUTER_TIMEOUT', 30),
],

// Application code - use config()
class OpenRouterService
{
    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key');
        $this->baseUrl = config('services.openrouter.base_url');
        $this->timeout = config('services.openrouter.timeout') ?? 30;
    }
}
```

**Why it's better:**
- Works with config caching
- Single source of truth
- Defaults defined in config file
- Environment-agnostic code

## Project-Specific Notes

**BotFacebook Config Files:**
```
config/
├── llm-models.php    # AI models with pricing
├── rag.php           # RAG settings
├── tools.php         # Agent tool definitions
├── services.php      # External services (OpenRouter, LINE, etc.)
└── broadcasting.php  # Reverb settings
```

**Audit Command:**
```bash
# Find env() calls outside config files
grep -rn "env(" app/ --include="*.php"
```

## References

- [Laravel Configuration Caching](https://laravel.com/docs/configuration#configuration-caching)
