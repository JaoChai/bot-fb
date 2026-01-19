---
id: owasp-004-broken-access
title: OWASP A01 - Broken Access Control
impact: CRITICAL
impactDescription: "Users can access other users' data (IDOR)"
category: owasp
tags: [owasp, idor, authorization, access-control]
relatedRules: [policy-001-resource-policies]
---

## Why This Matters

Broken access control allows users to access or modify resources they shouldn't. This includes IDOR (Insecure Direct Object Reference) vulnerabilities.

## Threat Model

**Attack Vector:** Changing resource IDs in URLs/requests
**Impact:** Access/modify other users' data
**Likelihood:** Very High - trivial to exploit

## Bad Example

```php
// Direct object reference without ownership check
public function show($id)
{
    return Bot::findOrFail($id); // Anyone can view any bot!
}

// Trusting user-provided IDs
public function update(Request $request, $botId)
{
    $bot = Bot::find($botId);
    $bot->update($request->all()); // Anyone can update any bot!
}

// Missing middleware
Route::get('/admin/users', [AdminController::class, 'users']);
// No admin check!
```

**Why it's vulnerable:**
- No ownership verification
- IDs are guessable (sequential)
- Missing role checks
- Horizontal & vertical privilege escalation

## Good Example

```php
// Scoped to authenticated user
public function show(Bot $bot)
{
    $this->authorize('view', $bot);
    return new BotResource($bot);
}

// Policy handles authorization
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
}

// Always scope queries to user
public function index()
{
    return BotResource::collection(
        auth()->user()->bots()->paginate()
    );
}

// Protected admin routes
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('users', [AdminController::class, 'users']);
});
```

**Why it's secure:**
- Policy checks ownership
- Queries scoped to user
- Admin routes protected
- No direct object access

## Audit Command

```bash
# Find direct ::find() without auth
grep -rn "::find(\|::findOrFail(" --include="*.php" app/Http/Controllers/

# Check for authorize calls
grep -L "authorize\|policy" app/Http/Controllers/Api/*.php

# Routes without middleware
php artisan route:list | grep -v "auth"
```

## Project-Specific Notes

**BotFacebook Access Control:**

```php
// Always use scoped queries
class BotController extends Controller
{
    public function index()
    {
        return BotResource::collection(
            auth()->user()->bots()->paginate()
        );
    }

    public function show(Bot $bot)
    {
        $this->authorize('view', $bot);
        return new BotResource($bot);
    }
}

// Register all policies
class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Bot::class => BotPolicy::class,
        Conversation::class => ConversationPolicy::class,
        KnowledgeDocument::class => KnowledgeDocumentPolicy::class,
    ];
}
```
