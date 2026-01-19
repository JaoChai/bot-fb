---
id: policy-001-resource-policies
title: Use Laravel Policies for Authorization
impact: HIGH
impactDescription: "Inconsistent authorization checks lead to IDOR vulnerabilities"
category: policy
tags: [authorization, policies, laravel, idor]
relatedRules: [policy-002-gates, owasp-004-broken-access]
---

## Why This Matters

Scattered authorization checks in controllers are easy to forget and inconsistent. Laravel Policies centralize authorization logic per model, ensuring every access is checked.

## Threat Model

**Attack Vector:** Missing or inconsistent authorization checks
**Impact:** Users access/modify other users' data (IDOR)
**Likelihood:** Very High - most common vulnerability

## Bad Example

```php
// Authorization scattered in controllers
class BotController extends Controller
{
    public function show(Bot $bot)
    {
        // Sometimes checked...
        if ($bot->user_id !== auth()->id()) {
            abort(403);
        }

        return new BotResource($bot);
    }

    public function update(Request $request, Bot $bot)
    {
        // Forgot to check here!
        $bot->update($request->validated());

        return new BotResource($bot);
    }

    public function delete(Bot $bot)
    {
        // Different check style, confusing
        abort_unless($bot->user_id === auth()->id(), 403);

        $bot->delete();
    }
}

// No policy registered
// Anyone can access any bot by changing the ID
```

**Why it's vulnerable:**
- Easy to forget checks
- Inconsistent implementations
- No central audit point
- IDOR vulnerabilities

## Good Example

```php
// app/Policies/BotPolicy.php
class BotPolicy
{
    /**
     * Determine if user can view the bot.
     */
    public function view(User $user, Bot $bot): bool
    {
        return $user->id === $bot->user_id;
    }

    /**
     * Determine if user can update the bot.
     */
    public function update(User $user, Bot $bot): bool
    {
        return $user->id === $bot->user_id;
    }

    /**
     * Determine if user can delete the bot.
     */
    public function delete(User $user, Bot $bot): bool
    {
        return $user->id === $bot->user_id;
    }

    /**
     * Determine if user can create bots.
     */
    public function create(User $user): bool
    {
        // Check subscription limit
        $botCount = $user->bots()->count();
        $limit = $user->subscription?->bot_limit ?? 1;

        return $botCount < $limit;
    }

    /**
     * Admin bypass for all actions.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return null; // Fall through to normal checks
    }
}

// Register policy
// app/Providers/AuthServiceProvider.php
class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Bot::class => BotPolicy::class,
        Conversation::class => ConversationPolicy::class,
        KnowledgeDocument::class => KnowledgeDocumentPolicy::class,
    ];
}

// Controller with policy authorization
class BotController extends Controller
{
    public function show(Bot $bot)
    {
        $this->authorize('view', $bot);

        return new BotResource($bot);
    }

    public function store(StoreBotRequest $request)
    {
        $this->authorize('create', Bot::class);

        $bot = $request->user()->bots()->create($request->validated());

        return new BotResource($bot);
    }

    public function update(UpdateBotRequest $request, Bot $bot)
    {
        $this->authorize('update', $bot);

        $bot->update($request->validated());

        return new BotResource($bot);
    }

    public function destroy(Bot $bot)
    {
        $this->authorize('delete', $bot);

        $bot->delete();

        return response()->noContent();
    }
}
```

**Why it's secure:**
- Central authorization logic
- Consistent across all controllers
- Easy to audit
- Admin bypass in one place

## Audit Command

```bash
# Check registered policies
php artisan tinker --execute="
    \$providers = app()->getProviders(App\Providers\AuthServiceProvider::class);
    foreach (\$providers as \$p) {
        print_r(\$p->policies ?? []);
    }
"

# Find controllers without authorize
grep -L "authorize\|policy" app/Http/Controllers/Api/*.php

# Check for direct user_id comparisons (should use policy)
grep -rn "user_id.*===\|===.*user_id" app/Http/Controllers/ --include="*.php"
```

## Project-Specific Notes

**BotFacebook Policies:**

```php
// All resource policies follow same pattern
class BotPolicy
{
    public function view(User $user, Bot $bot): bool
    {
        return $user->id === $bot->user_id;
    }

    public function update(User $user, Bot $bot): bool
    {
        return $user->id === $bot->user_id;
    }

    public function delete(User $user, Bot $bot): bool
    {
        return $user->id === $bot->user_id;
    }
}

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $user->id === $conversation->bot->user_id;
    }

    // Conversation belongs to bot, which belongs to user
    public function viewAny(User $user, Bot $bot): bool
    {
        return $user->id === $bot->user_id;
    }
}

class KnowledgeDocumentPolicy
{
    public function view(User $user, KnowledgeDocument $document): bool
    {
        return $user->id === $document->knowledgeBase->bot->user_id;
    }
}

// Register all policies
protected $policies = [
    Bot::class => BotPolicy::class,
    Conversation::class => ConversationPolicy::class,
    KnowledgeDocument::class => KnowledgeDocumentPolicy::class,
    Message::class => MessagePolicy::class,
];
```
