---
id: eloquent-003-model-casts
title: Model Attribute Casts
impact: HIGH
impactDescription: "Ensures type safety and automatic serialization/deserialization"
category: eloquent
tags: [eloquent, model, casts, types]
relatedRules: [eloquent-005-accessors-mutators]
---

## Why This Matters

Casts automatically convert database values to PHP types. Without casts, you manually convert types everywhere, leading to bugs and inconsistent handling of dates, JSON, booleans, and other types.

## Bad Example

```php
// Problem: Manual type conversion everywhere
$bot = Bot::find(1);

// Have to remember to cast
$isActive = (bool) $bot->is_active;
$settings = json_decode($bot->settings);
$createdAt = new Carbon($bot->created_at);

// Saving requires manual conversion too
$bot->settings = json_encode($data);
$bot->is_active = $active ? 1 : 0;
$bot->save();
```

**Why it's wrong:**
- Manual conversion error-prone
- Inconsistent across codebase
- Type errors possible
- Verbose code

## Good Example

```php
// Model with casts
class Bot extends Model
{
    protected $casts = [
        // Booleans
        'is_active' => 'boolean',
        'ai_enabled' => 'boolean',

        // Dates
        'created_at' => 'datetime',
        'last_active_at' => 'datetime',
        'trial_ends_at' => 'datetime:Y-m-d',

        // JSON/Array
        'settings' => 'array',
        'metadata' => 'object',
        'tags' => 'collection',

        // Encrypted (for sensitive data)
        'api_secret' => 'encrypted',

        // Enum (PHP 8.1+)
        'platform' => Platform::class,
        'status' => BotStatus::class,
    ];
}

// Usage - automatic type conversion
$bot = Bot::find(1);
$bot->is_active; // boolean true/false
$bot->settings; // array
$bot->settings['theme']; // access directly
$bot->created_at; // Carbon instance
$bot->created_at->diffForHumans(); // "2 hours ago"
$bot->platform; // Platform enum

// Saving - automatic serialization
$bot->settings = ['theme' => 'dark'];
$bot->is_active = true;
$bot->save();
```

**Why it's better:**
- Automatic type conversion
- Type-safe access
- Carbon for dates
- JSON/array seamless

## Project-Specific Notes

**BotFacebook Common Casts:**

```php
// Bot model
protected $casts = [
    'is_active' => 'boolean',
    'ai_enabled' => 'boolean',
    'settings' => 'array',
    'platform' => Platform::class, // Enum
];

// Message model
protected $casts = [
    'metadata' => 'array',
    'attachments' => 'collection',
    'sent_at' => 'datetime',
    'read_at' => 'datetime',
];

// User model
protected $casts = [
    'email_verified_at' => 'datetime',
    'preferences' => 'array',
];
```

**Custom Cast Classes:**
```php
// app/Casts/Embedding.php
class Embedding implements CastsAttributes
{
    public function get($model, $key, $value, $attributes)
    {
        return json_decode($value, true);
    }

    public function set($model, $key, $value, $attributes)
    {
        return json_encode($value);
    }
}
```

## References

- [Laravel Attribute Casting](https://laravel.com/docs/eloquent-mutators#attribute-casting)
- [Custom Casts](https://laravel.com/docs/eloquent-mutators#custom-casts)
