---
id: policy-003-gates
title: Gate Definitions
impact: MEDIUM
impactDescription: "Enables feature-level authorization beyond resource policies"
category: policy
tags: [authorization, gate, security, feature]
relatedRules: [policy-001-authorization, policy-002-controller-authorize]
---

## Why This Matters

Gates handle authorization that doesn't fit the resource-policy model - like feature flags, role-based access, or cross-cutting permissions. They're simpler than policies for non-resource checks.

## Bad Example

```php
// Problem: Inline permission checks scattered everywhere
public function showAdminDashboard()
{
    if (!auth()->user()->role === 'admin') {
        abort(403);
    }
}

public function accessPremiumFeature()
{
    if (!auth()->user()->subscription?->isPremium()) {
        abort(403);
    }
}
```

**Why it's wrong:**
- Logic duplicated
- Inconsistent checks
- Hard to maintain
- Not reusable in views

## Good Example

```php
// Define gates in AuthServiceProvider or AppServiceProvider
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    // Role-based gate
    Gate::define('access-admin', function (User $user) {
        return $user->role === 'admin';
    });

    // Feature-based gate
    Gate::define('use-ai-features', function (User $user) {
        return $user->subscription?->tier !== 'free';
    });

    // Limit-based gate
    Gate::define('create-bot', function (User $user) {
        $limit = match($user->subscription?->tier) {
            'premium' => 50,
            'pro' => 20,
            default => 5,
        };
        return $user->bots()->count() < $limit;
    });

    // Super admin bypass
    Gate::before(function (User $user, string $ability) {
        if ($user->isSuperAdmin()) {
            return true;
        }
    });
}

// Usage in controller
public function showDashboard()
{
    Gate::authorize('access-admin');
    return view('admin.dashboard');
}

public function store(StoreBotRequest $request)
{
    Gate::authorize('create-bot');
    // ...
}

// Usage in views
@can('access-admin')
    <a href="/admin">Admin Dashboard</a>
@endcan

@cannot('use-ai-features')
    <div>Upgrade to use AI features</div>
@endcannot
```

**Why it's better:**
- Centralized definitions
- Reusable across controllers
- Works in Blade views
- Clear naming

## Project-Specific Notes

**BotFacebook Gates:**

```php
// Feature gates
Gate::define('use-ai', fn(User $user) => $user->subscription?->hasAI());
Gate::define('use-knowledge-base', fn(User $user) => $user->subscription?->hasKB());
Gate::define('use-analytics', fn(User $user) => $user->subscription?->tier !== 'free');

// Limit gates
Gate::define('create-knowledge-base', function (User $user) {
    $limit = $user->subscription?->kbLimit() ?? 1;
    return $user->knowledgeBases()->count() < $limit;
});
```

**Gate vs Policy:**
| Use Case | Use |
|----------|-----|
| Resource CRUD | Policy |
| Feature access | Gate |
| Role check | Gate |
| Subscription limit | Gate |
| Admin area | Gate |

## References

- [Laravel Gates](https://laravel.com/docs/authorization#gates)
