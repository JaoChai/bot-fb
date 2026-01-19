---
id: policy-002-gates
title: Use Gates for Non-Model Authorization
impact: MEDIUM
impactDescription: "Feature access controlled inconsistently"
category: policy
tags: [authorization, gates, laravel, features]
relatedRules: [policy-001-resource-policies, owasp-004-broken-access]
---

## Why This Matters

Not all authorization is model-based. Gates handle feature access, subscription checks, and admin-only actions that don't tie to specific resources.

## Threat Model

**Attack Vector:** Accessing features without proper authorization
**Impact:** Premium features used without payment, admin features exposed
**Likelihood:** Medium - common in freemium applications

## Bad Example

```php
// Subscription checks scattered everywhere
class AnalyticsController extends Controller
{
    public function index()
    {
        // Copy-pasted check
        if (!auth()->user()->subscription?->plan === 'premium') {
            abort(403, 'Premium required');
        }
    }
}

class ExportController extends Controller
{
    public function export()
    {
        // Slightly different check
        if (auth()->user()->subscription?->plan !== 'premium') {
            return response()->json(['error' => 'Upgrade required'], 403);
        }
    }
}

// Admin checks inconsistent
if ($user->role === 'admin') { ... }
if ($user->is_admin) { ... }
if ($user->hasRole('admin')) { ... }
```

**Why it's vulnerable:**
- Inconsistent checks
- Easy to forget
- Hard to change logic
- Mixed response formats

## Good Example

```php
// Define gates in AuthServiceProvider
// app/Providers/AuthServiceProvider.php
class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Feature gates
        Gate::define('access-analytics', function (User $user) {
            return $user->subscription?->hasFeature('analytics');
        });

        Gate::define('export-data', function (User $user) {
            return $user->subscription?->hasFeature('export');
        });

        Gate::define('use-ai-features', function (User $user) {
            return $user->subscription?->hasFeature('ai')
                || $user->ai_credits > 0;
        });

        Gate::define('create-unlimited-bots', function (User $user) {
            return $user->subscription?->plan === 'unlimited';
        });

        // Role gates
        Gate::define('admin', function (User $user) {
            return $user->role === 'admin';
        });

        Gate::define('manage-users', function (User $user) {
            return $user->role === 'admin' || $user->role === 'moderator';
        });

        // Before hook for super admin
        Gate::before(function (User $user, string $ability) {
            if ($user->role === 'super_admin') {
                return true;
            }
        });
    }
}

// Controller using gates
class AnalyticsController extends Controller
{
    public function index()
    {
        $this->authorize('access-analytics');

        return AnalyticsResource::collection(
            auth()->user()->bots()->with('analytics')->get()
        );
    }
}

class ExportController extends Controller
{
    public function export()
    {
        $this->authorize('export-data');

        return Excel::download(new DataExport, 'data.xlsx');
    }
}

// Blade templates
@can('access-analytics')
    <a href="{{ route('analytics') }}">Analytics</a>
@endcan

@cannot('use-ai-features')
    <a href="{{ route('upgrade') }}">Upgrade to use AI</a>
@endcannot

// Middleware using gates
Route::middleware(['auth:sanctum', 'can:access-analytics'])->group(function () {
    Route::get('/analytics', [AnalyticsController::class, 'index']);
    Route::get('/analytics/export', [AnalyticsController::class, 'export']);
});

// Checking in code
if (Gate::allows('admin')) {
    // Show admin panel
}

if (Gate::denies('use-ai-features')) {
    throw new FeatureNotAvailableException('AI features require subscription');
}

// Response trait for consistent errors
trait AuthorizesRequests
{
    protected function authorizeFeature(string $ability): void
    {
        if (Gate::denies($ability)) {
            throw new FeatureNotAvailableException(
                "This feature requires a subscription upgrade."
            );
        }
    }
}
```

**Why it's secure:**
- Central gate definitions
- Consistent authorization
- Easy to audit
- Reusable across app

## Audit Command

```bash
# List all gates
php artisan tinker --execute="
    print_r(array_keys(app(\Illuminate\Contracts\Auth\Access\Gate::class)->abilities()));
"

# Check gate usage
grep -rn "Gate::\|@can\|@cannot\|->authorize(" app/ resources/ --include="*.php" --include="*.blade.php"

# Find direct role checks (should use gates)
grep -rn "->role\|->is_admin\|hasRole(" app/Http/Controllers/ --include="*.php"
```

## Project-Specific Notes

**BotFacebook Gates:**

```php
// app/Providers/AuthServiceProvider.php
public function boot(): void
{
    // Subscription-based features
    Gate::define('access-analytics', fn (User $user) =>
        $user->subscription?->hasFeature('analytics')
    );

    Gate::define('use-agent-tools', fn (User $user) =>
        $user->subscription?->hasFeature('agent_tools')
    );

    Gate::define('custom-prompts', fn (User $user) =>
        $user->subscription?->hasFeature('custom_prompts')
    );

    // Usage-based features
    Gate::define('send-message', fn (User $user) =>
        $user->messages_remaining > 0
        || $user->subscription?->unlimited_messages
    );

    // Admin features
    Gate::define('admin', fn (User $user) => $user->role === 'admin');
    Gate::define('impersonate', fn (User $user) => $user->role === 'admin');
    Gate::define('view-all-bots', fn (User $user) => $user->role === 'admin');
}

// Subscription model
class Subscription extends Model
{
    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? []);
    }
}

// Example features by plan
$plans = [
    'free' => [],
    'pro' => ['analytics', 'export'],
    'premium' => ['analytics', 'export', 'agent_tools', 'custom_prompts'],
];
```
