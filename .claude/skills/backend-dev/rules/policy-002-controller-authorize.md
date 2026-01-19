---
id: policy-002-controller-authorize
title: Controller Authorization Calls
impact: HIGH
impactDescription: "Ensures consistent authorization checks in every controller action"
category: policy
tags: [authorization, policy, controller, security]
relatedRules: [policy-001-authorization, laravel-001-thin-controller]
---

## Why This Matters

Every controller action that accesses user-specific resources must have an authorization check. Missing checks create security vulnerabilities where users can access or modify others' data.

## Bad Example

```php
// Problem: Missing authorization
public function update(Request $request, Bot $bot)
{
    // Any user can update any bot!
    $bot->update($request->validated());
    return new BotResource($bot);
}

// Problem: Inconsistent authorization
public function show(Bot $bot)
{
    $this->authorize('view', $bot); // Has check
    return new BotResource($bot);
}

public function destroy(Bot $bot)
{
    $bot->delete(); // Missing check!
    return response()->noContent();
}
```

**Why it's wrong:**
- Security vulnerability
- Inconsistent protection
- Easy to miss actions
- IDOR vulnerability

## Good Example

```php
class BotController extends Controller
{
    // Method 1: Authorize in each action
    public function show(Bot $bot)
    {
        $this->authorize('view', $bot);
        return new BotResource($bot);
    }

    public function update(UpdateBotRequest $request, Bot $bot)
    {
        $this->authorize('update', $bot);
        $bot = $this->service->update($bot, $request->validated());
        return new BotResource($bot);
    }

    public function destroy(Bot $bot)
    {
        $this->authorize('delete', $bot);
        $this->service->delete($bot);
        return response()->noContent();
    }

    // Method 2: Authorize in constructor (all actions)
    public function __construct()
    {
        $this->authorizeResource(Bot::class, 'bot');
    }
}

// Custom authorization with message
public function activate(Bot $bot)
{
    $this->authorize('activate', $bot);
    // or with custom message
    abort_unless($bot->canActivate(), 403, 'Bot cannot be activated');
}
```

**Why it's better:**
- Every action protected
- Consistent checks
- Clear authorization flow
- Automatic 403 responses

## Project-Specific Notes

**BotFacebook Controller Pattern:**

```php
class BotController extends Controller
{
    public function __construct(private BotService $service)
    {
        // Authorize all resource actions
        $this->authorizeResource(Bot::class, 'bot');
    }

    // index() checks viewAny
    // show() checks view
    // store() checks create
    // update() checks update
    // destroy() checks delete
}
```

**Custom Actions:**
```php
public function activate(Bot $bot)
{
    // Custom policy method
    $this->authorize('activate', $bot);
    // ...
}

public function duplicate(Bot $bot)
{
    $this->authorize('duplicate', $bot);
    // ...
}
```

**Alternative: Scope to User:**
```php
// When resource always belongs to user
public function show($id)
{
    $bot = auth()->user()->bots()->findOrFail($id);
    return new BotResource($bot);
}
```

## References

- [Laravel Authorization](https://laravel.com/docs/authorization#via-controller-helpers)
