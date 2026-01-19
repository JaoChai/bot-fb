---
id: backend-007-model-logic
title: Keep Models Thin
impact: MEDIUM
impactDescription: "Business logic in models creates tight coupling and testing issues"
category: backend
tags: [laravel, eloquent, model, architecture]
relatedRules: [backend-002-service-layer, backend-001-thin-controller]
---

## Why This Matters

Models should represent data structure and relationships. Business logic in models creates tight coupling, makes testing harder, and violates Single Responsibility Principle.

## Bad Example

```php
class Bot extends Model
{
    // OK: Relationships
    public function user() { return $this->belongsTo(User::class); }

    // BAD: Business logic in model
    public function processMessage(string $message): string
    {
        $context = $this->searchKnowledge($message);
        $response = $this->callLLM($message, $context);
        $this->updateUsage($response);
        event(new MessageProcessed($this));
        return $response;
    }

    // BAD: External API calls in model
    public function callLLM(string $message, string $context): string
    {
        return Http::post('https://api.openai.com/...', [
            'messages' => [...]
        ])->json('content');
    }

    // BAD: Complex validation
    public function validateSubscription(): bool
    {
        $user = $this->user;
        $plan = $user->subscription?->plan;
        return $plan && $plan->bot_limit > $user->bots()->count();
    }
}
```

**Why it's wrong:**
- Model does HTTP calls
- Business logic mixed in
- Hard to test without DB
- Impossible to reuse logic

## Good Example

```php
// Model: Only data concerns
class Bot extends Model
{
    // Relationships
    public function user() { return $this->belongsTo(User::class); }
    public function conversations() { return $this->hasMany(Conversation::class); }

    // Scopes (query helpers)
    public function scopeActive($query) { return $query->where('is_active', true); }

    // Accessors/Mutators
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn($value) => ucfirst($value),
            set: fn($value) => strtolower($value)
        );
    }

    // Simple computed properties
    public function isConfigured(): bool
    {
        return $this->system_prompt && $this->model;
    }
}

// Business logic in Service
class BotChatService
{
    public function processMessage(Bot $bot, string $message): string
    {
        $context = $this->searchService->search($bot, $message);
        $response = $this->llmService->generate($bot, $message, $context);
        $this->usageService->track($bot, $response);
        return $response;
    }
}
```

**Why it's better:**
- Model is data-focused
- Services handle business logic
- Easy to test separately
- Logic reusable

## Review Checklist

- [ ] No HTTP/API calls in models
- [ ] No event dispatching in models (except via observers)
- [ ] No complex validation logic
- [ ] Business logic in services
- [ ] Models contain: relationships, scopes, accessors

## Detection

```bash
# HTTP calls in models
grep -rn "Http::\|Guzzle\|curl" --include="*.php" app/Models/

# Event dispatching in models
grep -rn "event(\|Event::" --include="*.php" app/Models/

# Long model files (potential logic)
wc -l app/Models/*.php | sort -n | tail -10
```

## Project-Specific Notes

**BotFacebook Model Pattern:**

```php
// Bot model - data only
class Bot extends Model
{
    protected $fillable = ['name', 'system_prompt', 'model', 'is_active'];

    // Relationships
    public function user() { return $this->belongsTo(User::class); }
    public function conversations() { return $this->hasMany(Conversation::class); }
    public function knowledgeDocuments() { return $this->hasMany(KnowledgeDocument::class); }

    // Scopes
    public function scopeActive($q) { return $q->where('is_active', true); }
    public function scopeForPlatform($q, $p) { return $q->where('platform', $p); }

    // Simple accessors
    public function getHasKnowledgeBaseAttribute(): bool
    {
        return $this->knowledgeDocuments()->exists();
    }
}

// RAGService handles all chat logic
// BotService handles CRUD operations
// UsageService handles analytics
```
