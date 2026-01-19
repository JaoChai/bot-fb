---
id: security-005-auth-bypass
title: Authentication Bypass Prevention
impact: HIGH
impactDescription: "Unauthorized users can access protected resources"
category: security
tags: [security, authentication, authorization, owasp]
relatedRules: [backend-001-thin-controller, api-003-validation]
---

## Why This Matters

Authentication bypass allows attackers to access protected resources without proper credentials, potentially viewing, modifying, or deleting other users' data.

## Bad Example

```php
// Missing auth middleware
Route::get('/bots/{bot}', [BotController::class, 'show']);

// Trusting user-provided IDs without ownership check
public function update(Request $request, $botId)
{
    $bot = Bot::findOrFail($botId);
    $bot->update($request->all());
}

// Inconsistent auth checks
public function show(Bot $bot)
{
    return $bot; // No ownership verification!
}
```

**Why it's wrong:**
- Anyone can access any bot by ID
- No ownership verification
- Missing authorization policies

## Good Example

```php
// Route with auth middleware
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('bots', BotController::class);
});

// Policy-based authorization
public function update(UpdateBotRequest $request, Bot $bot)
{
    $this->authorize('update', $bot);
    $bot->update($request->validated());
}

// Policy definition
class BotPolicy
{
    public function update(User $user, Bot $bot): bool
    {
        return $user->id === $bot->user_id;
    }
}

// Scoped queries (can't access others' resources)
public function index()
{
    return auth()->user()->bots()->paginate();
}
```

**Why it's better:**
- Middleware ensures authentication
- Policies centralize authorization
- Scoped queries prevent enumeration

## Review Checklist

- [ ] All protected routes have `auth:sanctum` middleware
- [ ] `$this->authorize()` called in controller methods
- [ ] Policies defined for all models with user ownership
- [ ] No direct `Model::find($id)` without auth check
- [ ] Queries scoped to authenticated user

## Detection

```bash
# Routes without auth middleware
grep -rn "Route::" routes/api.php | grep -v "middleware\|auth"

# Controllers without authorize
grep -L "authorize\|policy" app/Http/Controllers/Api/*.php

# Direct find without scope
grep -rn "::find(\|::findOrFail(" --include="*.php" app/Http/Controllers/
```

## Project-Specific Notes

**BotFacebook Authorization Pattern:**

```php
// BotController - consistent authorization
class BotController extends Controller
{
    public function show(Bot $bot)
    {
        $this->authorize('view', $bot);
        return new BotResource($bot);
    }

    public function update(UpdateBotRequest $request, Bot $bot)
    {
        $this->authorize('update', $bot);
        // FormRequest also validates ownership
        $bot->update($request->validated());
        return new BotResource($bot);
    }
}

// BotPolicy
public function view(User $user, Bot $bot): bool
{
    return $user->id === $bot->user_id;
}

public function update(User $user, Bot $bot): bool
{
    return $user->id === $bot->user_id;
}
```
