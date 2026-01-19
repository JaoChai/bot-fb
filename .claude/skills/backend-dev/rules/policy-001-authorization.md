---
id: policy-001-authorization
title: Policy-Based Authorization
impact: CRITICAL
impactDescription: "Prevents unauthorized access to resources and data breaches"
category: policy
tags: [authorization, policy, security, access-control]
relatedRules: [security-001-input-validation, laravel-001-thin-controller]
---

## Why This Matters

Every resource access must be authorized. Without proper authorization checks, users can access, modify, or delete other users' data. Policies provide a clean, testable way to define authorization rules.

## Bad Example

```php
// Problem: No authorization check - IDOR vulnerability
public function show($id)
{
    return Bot::findOrFail($id);
    // Any user can view any bot by guessing IDs!
}

// Problem: Manual check in controller - not reusable
public function show($id)
{
    $bot = Bot::findOrFail($id);

    if ($bot->user_id !== auth()->id()) {
        abort(403);
    }

    return $bot;
}
```

**Why it's wrong:**
- IDOR (Insecure Direct Object Reference) vulnerability
- Manual checks are error-prone
- Logic duplicated across controllers
- Not testable in isolation

## Good Example

```php
// Solution: Define Policy
// app/Policies/BotPolicy.php
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

    public function create(User $user): bool
    {
        // Limit bots per user
        return $user->bots()->count() < 10;
    }
}

// Register in AuthServiceProvider (auto-discovered in Laravel 11+)

// Use in Controller
class BotController extends Controller
{
    public function show(Bot $bot): BotResource
    {
        $this->authorize('view', $bot);

        return new BotResource($bot->load('settings'));
    }

    public function update(UpdateBotRequest $request, Bot $bot): BotResource
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
}
```

**Why it's better:**
- Authorization logic in one place
- Reusable across controllers
- Testable in isolation
- Automatic 403 response on failure
- Clear, declarative code

## Project-Specific Notes

**BotFacebook Policy Organization:**

```
app/Policies/
├── BotPolicy.php
├── FlowPolicy.php
├── ConversationPolicy.php
├── KnowledgeBasePolicy.php
└── TeamPolicy.php
```

**Alternative: Scope to User (simpler for ownership)**
```php
// When you only need to access user's own resources
public function show($id): BotResource
{
    // Automatically scoped to current user
    $bot = auth()->user()->bots()->findOrFail($id);

    return new BotResource($bot);
}
```

**Policy Testing:**
```php
public function test_user_can_only_view_own_bots()
{
    $user = User::factory()->create();
    $bot = Bot::factory()->create(['user_id' => $user->id]);
    $otherBot = Bot::factory()->create();

    $this->assertTrue($user->can('view', $bot));
    $this->assertFalse($user->can('view', $otherBot));
}
```

## References

- [Laravel Authorization](https://laravel.com/docs/authorization)
- [Policies](https://laravel.com/docs/authorization#policies)
