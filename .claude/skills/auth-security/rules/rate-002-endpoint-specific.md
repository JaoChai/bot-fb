---
id: rate-002-endpoint-specific
title: Configure Endpoint-Specific Limits
impact: MEDIUM
impactDescription: "All endpoints share same limit, causing poor UX"
category: rate
tags: [rate-limiting, api, endpoints, configuration]
relatedRules: [rate-001-api-rate-limiting, rate-003-abuse-prevention]
---

## Why This Matters

Different endpoints have different costs and usage patterns. A one-size-fits-all limit either over-restricts light endpoints or under-protects heavy ones.

## Threat Model

**Attack Vector:** Abusing expensive endpoints while staying under global limit
**Impact:** Resource exhaustion, cost overrun
**Likelihood:** Medium - sophisticated attackers target weaknesses

## Bad Example

```php
// Same limit for everything
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::get('/users', ...);           // Light - DB query
    Route::post('/chat', ...);           // Heavy - AI call
    Route::post('/embed', ...);          // Very heavy - embedding
    Route::post('/upload', ...);         // Heavy - file processing
});

// User hits embedding 60 times/minute = massive cost
// User can't check their profile because limit exhausted
```

**Why it's vulnerable:**
- Expensive ops share limit with cheap ones
- No cost-based differentiation
- Heavy endpoints insufficiently protected
- Light endpoints over-restricted

## Good Example

```php
// Endpoint categories by cost/risk
// bootstrap/app.php

// Light operations - generous limit
RateLimiter::for('light', function (Request $request) {
    return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
});

// Standard operations
RateLimiter::for('standard', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

// Heavy operations - strict limit
RateLimiter::for('heavy', function (Request $request) {
    return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
});

// Very heavy operations - very strict
RateLimiter::for('expensive', function (Request $request) {
    return Limit::perMinute(10)
        ->by($request->user()?->id ?: $request->ip())
        ->response(fn () => response()->json([
            'error' => 'Rate limit exceeded for expensive operations',
            'retry_after' => 60,
        ], 429));
});

// Authentication - prevent brute force
RateLimiter::for('auth', function (Request $request) {
    return Limit::perMinute(5)->by($request->ip());
});

// routes/api.php
Route::middleware(['throttle:light'])->group(function () {
    Route::get('/user', ...);
    Route::get('/bots', ...);
    Route::get('/stats', ...);
});

Route::middleware(['throttle:standard'])->group(function () {
    Route::post('/bots', ...);
    Route::put('/bots/{bot}', ...);
    Route::get('/conversations', ...);
});

Route::middleware(['throttle:heavy'])->group(function () {
    Route::post('/bots/{bot}/chat', ...);
    Route::post('/conversations/{id}/reply', ...);
});

Route::middleware(['throttle:expensive'])->group(function () {
    Route::post('/documents/{doc}/embed', ...);
    Route::post('/knowledge/reindex', ...);
});

Route::middleware(['throttle:auth'])->group(function () {
    Route::post('/login', ...);
    Route::post('/register', ...);
});
```

**Why it's secure:**
- Cost-based limits
- Expensive ops protected
- Light ops unrestricted
- Clear categorization

## Audit Command

```bash
# List all throttle configurations
grep -rn "RateLimiter::for" bootstrap/ app/ --include="*.php"

# Map routes to their limits
php artisan route:list --columns=uri,middleware | grep throttle

# Find routes without throttle
php artisan route:list | grep "api" | grep -v throttle
```

## Project-Specific Notes

**BotFacebook Endpoint Categories:**

| Category | Limit | Endpoints |
|----------|-------|-----------|
| light | 120/min | GET /user, GET /bots, GET /stats |
| standard | 60/min | POST/PUT /bots, GET /conversations |
| heavy | 20/min | POST /chat, POST /reply |
| expensive | 10/min | POST /embed, POST /reindex |
| auth | 5/min | POST /login, POST /register |
| webhook | 300/min | POST /webhook/* |

```php
// Per-subscription limits (premium gets more)
RateLimiter::for('chat', function (Request $request) {
    $user = $request->user();

    if ($user->subscription === 'premium') {
        return Limit::perMinute(100)->by($user->id);
    }

    if ($user->subscription === 'pro') {
        return Limit::perMinute(50)->by($user->id);
    }

    return Limit::perMinute(20)->by($user->id);
});
```
