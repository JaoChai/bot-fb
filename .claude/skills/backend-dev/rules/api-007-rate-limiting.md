---
id: api-007-rate-limiting
title: Rate Limiting
impact: MEDIUM
impactDescription: "Protects API from abuse and ensures fair resource usage"
category: api
tags: [api, rate-limiting, security, throttle]
relatedRules: [laravel-008-middleware]
---

## Why This Matters

Rate limiting protects your API from abuse, prevents DoS attacks, and ensures fair usage across clients. Without it, a single client can monopolize resources and degrade service for everyone.

## Bad Example

```php
// Problem: No rate limiting
Route::post('/ai/generate', [AIController::class, 'generate']); // Unlimited calls!
// Attacker can hammer expensive AI endpoint
```

**Why it's wrong:**
- No protection from abuse
- Expensive endpoints unprotected
- DoS vulnerability
- Unfair resource usage

## Good Example

```php
// Global rate limiting (bootstrap/app.php)
->withMiddleware(function (Middleware $middleware) {
    $middleware->throttleApi('60,1'); // 60 requests per minute
})

// Custom rate limiters (AppServiceProvider)
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

public function boot(): void
{
    // Standard API limit
    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    });

    // Strict limit for auth endpoints
    RateLimiter::for('auth', function (Request $request) {
        return Limit::perMinute(5)->by($request->ip());
    });

    // Expensive AI endpoints
    RateLimiter::for('ai', function (Request $request) {
        return Limit::perMinute(10)
            ->by($request->user()?->id)
            ->response(function () {
                return response()->json([
                    'data' => null,
                    'errors' => [['message' => 'AI rate limit exceeded. Please wait.']],
                ], 429);
            });
    });

    // Tiered by subscription
    RateLimiter::for('api.tiered', function (Request $request) {
        $user = $request->user();

        return match($user?->subscription_tier) {
            'premium' => Limit::perMinute(200)->by($user->id),
            'pro' => Limit::perMinute(100)->by($user->id),
            default => Limit::perMinute(30)->by($user?->id ?: $request->ip()),
        };
    });
}

// Apply to routes
Route::middleware('throttle:ai')->group(function () {
    Route::post('ai/generate', [AIController::class, 'generate']);
});
```

**Why it's better:**
- Protection from abuse
- Different limits per endpoint
- Tiered by user type
- Clear error responses

## Project-Specific Notes

**BotFacebook Rate Limits:**
| Endpoint | Limit | Why |
|----------|-------|-----|
| `/api/*` | 60/min | Standard API |
| `/auth/*` | 5/min | Prevent brute force |
| `/ai/*` | 10/min | Expensive operations |
| `/webhooks/*` | 1000/min | Platform traffic |

**Response Headers:**
```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1705234567
```

## References

- [Laravel Rate Limiting](https://laravel.com/docs/routing#rate-limiting)
