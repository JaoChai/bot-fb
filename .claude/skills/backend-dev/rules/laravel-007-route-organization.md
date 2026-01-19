---
id: laravel-007-route-organization
title: Route Organization
impact: MEDIUM
impactDescription: "Ensures maintainable API routes with proper grouping and middleware"
category: laravel
tags: [routes, middleware, organization]
relatedRules: [api-002-restful-naming]
---

## Why This Matters

Well-organized routes make APIs discoverable and maintainable. Route groups with shared middleware prevent security gaps and reduce duplication.

## Bad Example

```php
// Problem: Flat routes, inconsistent middleware
Route::get('/bots', [BotController::class, 'index']);
Route::post('/bots', [BotController::class, 'store'])->middleware('auth:sanctum');
Route::get('/bots/{id}', [BotController::class, 'show']); // Missing auth!
Route::put('/bot/{id}', [BotController::class, 'update'])->middleware('auth:sanctum'); // Inconsistent path
Route::get('/users/me', [UserController::class, 'me']);
Route::get('/v2/bots', [BotController::class, 'indexV2']); // Version mixed in
```

**Why it's wrong:**
- Missing middleware on some routes
- Inconsistent path naming
- No versioning strategy
- Hard to audit security

## Good Example

```php
// routes/api.php
use Illuminate\Support\Facades\Route;

// API v1 - Authenticated routes
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // User routes
    Route::get('user', [UserController::class, 'me']);

    // Bot management
    Route::apiResource('bots', BotController::class);
    Route::post('bots/{bot}/activate', [BotController::class, 'activate']);
    Route::post('bots/{bot}/test', [BotController::class, 'test']);

    // Nested resources
    Route::apiResource('bots.flows', BotFlowController::class);
    Route::apiResource('knowledge-bases', KnowledgeBaseController::class);
    Route::apiResource('knowledge-bases.documents', KnowledgeDocumentController::class);

    // Analytics (read-only)
    Route::prefix('analytics')->group(function () {
        Route::get('bots/{bot}', [BotAnalyticsController::class, 'show']);
        Route::get('conversations/{conversation}', [ConversationAnalyticsController::class, 'show']);
    });
});

// Webhook routes (no auth, signature verification in controller)
Route::prefix('webhooks')->group(function () {
    Route::post('line', [LineWebhookController::class, 'handle']);
    Route::post('telegram', [TelegramWebhookController::class, 'handle']);
});
```

**Why it's better:**
- All authenticated routes in one group
- Consistent versioning
- Easy to audit middleware
- Logical grouping

## Project-Specific Notes

**BotFacebook Route Commands:**
```bash
# List all routes
php artisan route:list

# List with middleware
php artisan route:list --columns=uri,middleware

# Filter by path
php artisan route:list --path=bots
```

**Route Caching:**
```bash
# Cache routes (production)
php artisan route:cache

# Clear cache
php artisan route:clear
```

## References

- [Laravel Routing](https://laravel.com/docs/routing)
- [Route Groups](https://laravel.com/docs/routing#route-groups)
