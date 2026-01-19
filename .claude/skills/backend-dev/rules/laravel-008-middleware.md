---
id: laravel-008-middleware
title: Middleware Usage
impact: MEDIUM
impactDescription: "Ensures consistent request processing and security checks"
category: laravel
tags: [middleware, security, request]
relatedRules: [laravel-007-route-organization, policy-001-authorization]
---

## Why This Matters

Middleware handles cross-cutting concerns like authentication, rate limiting, and logging. Proper middleware usage ensures consistent security and avoids duplicating checks across controllers.

## Bad Example

```php
// Problem: Auth check in controller instead of middleware
class BotController extends Controller
{
    public function index(Request $request)
    {
        if (!auth()->check()) {
            abort(401);
        }

        if (!$request->user()->hasSubscription()) {
            abort(403, 'Subscription required');
        }

        return Bot::where('user_id', auth()->id())->get();
    }
}
```

**Why it's wrong:**
- Auth check duplicated in every controller
- Easy to forget
- Not reusable
- Inconsistent error responses

## Good Example

```php
// Create custom middleware
// app/Http/Middleware/EnsureHasSubscription.php
class EnsureHasSubscription
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user()->hasSubscription()) {
            return response()->json([
                'data' => null,
                'errors' => [['message' => 'Active subscription required']],
            ], 403);
        }

        return $next($request);
    }
}

// Register in bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'subscribed' => EnsureHasSubscription::class,
    ]);
})

// Use in routes
Route::middleware(['auth:sanctum', 'subscribed'])->group(function () {
    Route::apiResource('bots', BotController::class);
});

// Or on specific routes
Route::post('bots', [BotController::class, 'store'])
    ->middleware(['auth:sanctum', 'subscribed', 'throttle:create-bot']);
```

**Why it's better:**
- Reusable across routes
- Consistent error format
- Easy to audit
- Single responsibility

## Project-Specific Notes

**BotFacebook Middleware:**
```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'subscribed' => EnsureHasSubscription::class,
        'bot.owner' => EnsureBotOwner::class,
        'webhook.signature' => VerifyWebhookSignature::class,
    ]);

    // Rate limiting
    $middleware->throttleApi('60,1'); // 60 requests per minute
})
```

**Common Middleware Patterns:**
```php
// Terminate middleware (runs after response sent)
public function terminate(Request $request, Response $response)
{
    Log::info('Request completed', [
        'path' => $request->path(),
        'status' => $response->status(),
    ]);
}
```

## References

- [Laravel Middleware](https://laravel.com/docs/middleware)
