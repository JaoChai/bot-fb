---
id: gotcha-001-config-null-coalesce
title: Config Default Returns Null, Not Default
impact: CRITICAL
impactDescription: "Prevents null reference errors and undefined behavior from incorrect config defaults"
category: gotcha
tags: [config, null, gotcha, laravel]
relatedRules: [gotcha-004-env-vs-config]
---

## Why This Matters

Laravel's `config('key', 'default')` returns the second parameter ONLY if the key doesn't exist. If the key exists but has a `null` value, it returns `null` - not the default. This is a common source of bugs that can cause runtime failures or unexpected behavior.

## Bad Example

```php
// Problem: config() returns null when key exists but value is null
$timeout = config('services.api.timeout', 30);

// config/services.php
'api' => [
    'timeout' => null, // Key exists, value is null
]

// Result: $timeout = null (NOT 30!)
// This can cause: TypeError, connection timeouts, or undefined behavior
```

**Why it's wrong:**
- Config key exists, so default is ignored
- `null` is returned, not `30`
- May cause type errors or unexpected behavior downstream
- Silent failure - hard to debug

## Good Example

```php
// Solution: Use null coalescing operator
$timeout = config('services.api.timeout') ?? 30;

// Now works correctly:
// - If key doesn't exist: 30
// - If key is null: 30
// - If key has value: that value
```

**Why it's better:**
- `??` checks for null explicitly
- Works regardless of whether key exists
- Clear intent to handle null case
- Consistent behavior

## Project-Specific Notes

**BotFacebook Config Files:**
- `config/llm-models.php` - AI model configurations
- `config/rag.php` - RAG settings with thresholds
- `config/tools.php` - Agent tool definitions

**Common affected configs:**
```php
// In RAGService
$threshold = config('rag.threshold') ?? 0.7;
$maxResults = config('rag.max_results') ?? 10;

// In OpenRouterService
$timeout = config('services.openrouter.timeout') ?? 30;
```

## References

- [Laravel Config Documentation](https://laravel.com/docs/configuration)
- Related rule: gotcha-004-env-vs-config
