---
id: rate-001-api-rate-limiting
title: Implement API Rate Limiting
impact: HIGH
impactDescription: "API abuse causes service degradation or cost overrun"
category: rate
tags: [rate-limiting, api, throttle, security]
relatedRules: [rate-002-endpoint-specific, rate-003-abuse-prevention]
---

## Why This Matters

Without rate limiting, a single user or attacker can overwhelm your API, causing service degradation for all users and potentially massive costs.

## Threat Model

**Attack Vector:** Automated requests, API abuse, scraping
**Impact:** Service unavailable, high costs, resource exhaustion
**Likelihood:** High - bots constantly probe public APIs

## Bad Example

```php
// No rate limiting
Route::post('/api/chat', [ChatController::class, 'send']);
// User can send 1000 requests/second

// Global limit only
Route::middleware(['throttle:60,1'])->group(function () {
    // All endpoints share same 60/minute limit
    // Heavy endpoints starve light ones
});

// No limit on expensive operations
public function generateEmbedding(Request $request)
{
    // Each call costs money, no limit!
    return $this->embeddingService->generate($request->text);
}
```

**Why it's vulnerable:**
- No protection from abuse
- Resource-intensive ops unprotected
- Shared limits cause starvation
- Cost explosion possible

## Good Example

```php
// config/rate-limits.php (custom)
return [
    'api' => [
        'default' => '60,1',      // 60 per minute
        'auth' => '5,1',          // 5 per minute for login
        'chat' => '30,1',         // 30 per minute for chat
        'embedding' => '10,1',    // 10 per minute for embeddings
        'upload' => '5,1',        // 5 per minute for uploads
    ],
];

// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    });

    RateLimiter::for('chat', function (Request $request) {
        return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
    });

    RateLimiter::for('embedding', function (Request $request) {
        // Expensive operation - strict limit
        return Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip())
            ->response(function () {
                return response()->json([
                    'error' => 'Too many embedding requests. Please wait.',
                    'retry_after' => 60,
                ], 429);
            });
    });
})

// routes/api.php
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // Standard endpoints
    Route::apiResource('bots', BotController::class);
});

Route::middleware(['auth:sanctum', 'throttle:chat'])->group(function () {
    // Chat endpoints
    Route::post('/bots/{bot}/chat', [ChatController::class, 'send']);
});

Route::middleware(['auth:sanctum', 'throttle:embedding'])->group(function () {
    // Expensive endpoints
    Route::post('/documents/{document}/embed', [DocumentController::class, 'embed']);
});
```

**Why it's secure:**
- Endpoint-specific limits
- Expensive ops protected
- User-based tracking
- Clear error responses

## Audit Command

```bash
# Check routes for throttle middleware
php artisan route:list | grep throttle

# Find unprotected routes
php artisan route:list | grep -v "throttle\|web"

# Check rate limiter configuration
grep -rn "RateLimiter::for" bootstrap/ app/ --include="*.php"
```

## Project-Specific Notes

**BotFacebook Rate Limits:**

```php
// bootstrap/app.php
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by(
        $request->user()?->id ?: $request->ip()
    );
});

// Chat - moderate limit
RateLimiter::for('chat', function (Request $request) {
    return Limit::perMinute(30)->by($request->user()->id);
});

// Webhooks - higher limit (platforms send bursts)
RateLimiter::for('webhook', function (Request $request) {
    return Limit::perMinute(300)->by($request->ip());
});

// AI operations - strict limit
RateLimiter::for('ai', function (Request $request) {
    return Limit::perMinute(20)->by($request->user()->id);
});

// routes/api.php
Route::middleware(['throttle:webhook'])->group(function () {
    Route::post('/webhook/line/{bot}', [LINEWebhookController::class, 'handle']);
    Route::post('/webhook/telegram/{bot}', [TelegramWebhookController::class, 'handle']);
});
```
