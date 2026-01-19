---
id: laravel-006-config-organization
title: Config File Organization
impact: MEDIUM
impactDescription: "Ensures maintainable configuration with proper separation of concerns"
category: laravel
tags: [config, organization, settings]
relatedRules: [gotcha-004-env-vs-config]
---

## Why This Matters

Well-organized config files make settings discoverable and maintainable. Scattered configuration leads to duplication, missed updates, and confusion about where settings live.

## Bad Example

```php
// Problem: Settings scattered across files
// In services.php
'ai_model' => env('AI_MODEL'),

// In app.php
'ai_timeout' => env('AI_TIMEOUT'),

// In custom.php
'ai_max_tokens' => env('AI_MAX_TOKENS'),
```

**Why it's wrong:**
- Related settings scattered
- Hard to find all AI config
- Easy to miss settings
- No logical grouping

## Good Example

```php
// config/llm-models.php - All AI model config in one place
return [
    'default' => env('DEFAULT_LLM_MODEL', 'gpt-4o-mini'),

    'providers' => [
        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'base_url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
            'timeout' => env('OPENROUTER_TIMEOUT', 30),
        ],
    ],

    'models' => [
        'gpt-4o' => [
            'id' => 'openai/gpt-4o',
            'context' => 128000,
            'pricing' => ['input' => 2.5, 'output' => 10.0],
        ],
        // ... more models
    ],

    'defaults' => [
        'max_tokens' => 4096,
        'temperature' => 0.7,
    ],
];

// Usage
$model = config('llm-models.models.gpt-4o');
$timeout = config('llm-models.providers.openrouter.timeout');
```

**Why it's better:**
- All AI config in one file
- Easy to find and update
- Logical grouping
- Self-documenting

## Project-Specific Notes

**BotFacebook Config Files:**
```
config/
├── app.php           # Core Laravel
├── auth.php          # Authentication
├── broadcasting.php  # Reverb/WebSocket
├── database.php      # Database
├── queue.php         # Queue connections
├── services.php      # External services (LINE, Telegram)
├── llm-models.php    # AI models and pricing
├── rag.php           # RAG settings
└── tools.php         # Agent tool definitions
```

**Custom Config Pattern:**
```php
// Create new config file for feature
// config/feature-name.php
return [
    'enabled' => env('FEATURE_ENABLED', false),
    'options' => [
        // Group related settings
    ],
];
```

## References

- [Laravel Configuration](https://laravel.com/docs/configuration)
