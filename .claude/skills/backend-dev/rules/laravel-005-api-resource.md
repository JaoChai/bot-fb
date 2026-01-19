---
id: laravel-005-api-resource
title: API Resource Transformation
impact: HIGH
impactDescription: "Ensures consistent API responses and prevents data leakage"
category: laravel
tags: [api, resource, response, transformation]
relatedRules: [api-001-response-format, laravel-001-thin-controller]
---

## Why This Matters

API Resources transform models into JSON responses. They prevent exposing sensitive fields (passwords, api_keys), ensure consistent formatting, and allow adding computed fields without modifying models.

## Bad Example

```php
// Problem: Returning raw model
public function show(Bot $bot)
{
    return $bot; // Exposes ALL model attributes
}

// Response includes sensitive data:
// {
//   "id": 1,
//   "api_key": "sk-secret...",  // EXPOSED!
//   "webhook_secret": "...",     // EXPOSED!
//   "created_at": "2026-01-19 08:00:00"  // Wrong format
// }
```

**Why it's wrong:**
- Exposes sensitive fields
- Date format inconsistent
- Can't add computed fields
- Can't conditionally include data

## Good Example

```php
// app/Http/Resources/BotResource.php
class BotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'platform' => $this->platform,
            'description' => $this->description,
            'is_active' => $this->is_active,

            // Computed field
            'platform_icon' => $this->getPlatformIcon(),

            // Conditional count
            'conversations_count' => $this->whenCounted('conversations'),

            // Related resources (only if loaded)
            'settings' => new BotSettingsResource($this->whenLoaded('settings')),
            'flows' => FlowResource::collection($this->whenLoaded('flows')),
            'user' => new UserResource($this->whenLoaded('user')),

            // Consistent date formatting
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }

    private function getPlatformIcon(): string
    {
        return match($this->platform) {
            'line' => 'line-icon.svg',
            'telegram' => 'telegram-icon.svg',
            default => 'default-icon.svg',
        };
    }
}

// Controller usage
public function show(Bot $bot): BotResource
{
    return new BotResource($bot->load('settings'));
}

public function index(): AnonymousResourceCollection
{
    $bots = Bot::with('settings')->paginate(20);

    return BotResource::collection($bots)
        ->additional(['meta' => ['timestamp' => now()->toISOString()]]);
}
```

**Why it's better:**
- Controls exactly what's exposed
- Consistent date formatting
- Conditional relationships
- Computed fields without model changes
- Type safety

## Project-Specific Notes

**BotFacebook Resource Organization:**
```
app/Http/Resources/
├── BotResource.php
├── BotSettingsResource.php
├── FlowResource.php
├── ConversationResource.php
├── MessageResource.php
└── UserResource.php
```

**Create Resource:**
```bash
php artisan make:resource BotResource
```

**Pagination with Metadata:**
```php
return BotResource::collection($bots->paginate(20))
    ->additional([
        'meta' => [
            'timestamp' => now()->toISOString(),
            'version' => 'v1',
        ]
    ]);
```

## References

- [Laravel API Resources](https://laravel.com/docs/eloquent-resources)
