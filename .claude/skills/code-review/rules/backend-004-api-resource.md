---
id: backend-004-api-resource
title: API Resource Transformation
impact: MEDIUM
impactDescription: "Raw model returns expose sensitive data and lack consistency"
category: backend
tags: [laravel, api, resource, transformation]
relatedRules: [security-006-data-exposure, api-004-response-format]
---

## Why This Matters

API Resources control exactly what data is returned, preventing accidental exposure of sensitive fields and ensuring consistent response structure.

## Bad Example

```php
// Returns raw model (all fields including hidden)
public function show(Bot $bot)
{
    return $bot;
}

// Manual array transformation (inconsistent)
public function index()
{
    return response()->json([
        'data' => auth()->user()->bots->map(fn($bot) => [
            'id' => $bot->id,
            'name' => $bot->name,
            // Easy to forget fields or expose wrong ones
        ]),
    ]);
}
```

**Why it's wrong:**
- Exposes `$hidden` fields in some contexts
- Inconsistent structure
- No relationship handling
- Date formats inconsistent

## Good Example

```php
// API Resource class
class BotResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_active' => $this->is_active,
            'model' => $this->model,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),

            // Conditional relationships
            'user' => new UserResource($this->whenLoaded('user')),
            'conversations_count' => $this->when(
                $this->conversations_count !== null,
                $this->conversations_count
            ),
        ];
    }
}

// Controller returns resource
public function show(Bot $bot)
{
    return new BotResource($bot->load('user'));
}

public function index()
{
    return BotResource::collection(
        auth()->user()->bots()->paginate()
    );
}
```

**Why it's better:**
- Explicit field selection
- Consistent date formats
- Conditional relationships
- Collection support built-in

## Review Checklist

- [ ] All API responses use Resources
- [ ] No raw `return $model`
- [ ] Relationships use `whenLoaded()`
- [ ] Dates formatted consistently (ISO8601)
- [ ] Sensitive fields excluded

## Detection

```bash
# Raw model returns
grep -rn "return \$this->\|return \$bot\|return \$user" --include="*.php" app/Http/Controllers/Api/

# Missing resources
ls app/Http/Resources/ | wc -l

# Check resource usage
grep -rn "Resource::" --include="*.php" app/Http/Controllers/
```

## Project-Specific Notes

**BotFacebook Resource Pattern:**

```php
// BotResource with conditional data
class BotResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'platform' => $this->platform,
            'is_active' => $this->is_active,
            'model' => $this->model,

            // Stats (when loaded)
            'conversations_count' => $this->when(
                isset($this->conversations_count),
                $this->conversations_count
            ),
            'messages_count' => $this->when(
                isset($this->messages_count),
                $this->messages_count
            ),

            // Relationships
            'user' => new UserResource($this->whenLoaded('user')),
            'latest_conversation' => new ConversationResource(
                $this->whenLoaded('latestConversation')
            ),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```
