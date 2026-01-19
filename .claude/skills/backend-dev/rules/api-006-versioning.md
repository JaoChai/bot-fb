---
id: api-006-versioning
title: API Versioning
impact: MEDIUM
impactDescription: "Enables backward-compatible API evolution without breaking existing clients"
category: api
tags: [api, versioning, compatibility]
relatedRules: [api-002-restful-naming]
---

## Why This Matters

API versioning allows evolving your API without breaking existing clients. When you need to make breaking changes, versioned APIs let old clients continue working while new clients use updated endpoints.

## Bad Example

```php
// Problem: No versioning - breaking changes break all clients
Route::get('/bots', [BotController::class, 'index']);
// Later: Changed response format = all clients break!
```

**Why it's wrong:**
- No way to make breaking changes
- All clients forced to update simultaneously
- Mobile apps can't be updated instantly
- Third-party integrations break

## Good Example

```php
// URL versioning (recommended)
Route::prefix('v1')->group(function () {
    Route::apiResource('bots', App\Http\Controllers\V1\BotController::class);
});

Route::prefix('v2')->group(function () {
    Route::apiResource('bots', App\Http\Controllers\V2\BotController::class);
});

// Controller organization
// app/Http/Controllers/
// ├── V1/
// │   └── BotController.php
// └── V2/
//     └── BotController.php

// V1 controller - original response format
namespace App\Http\Controllers\V1;

class BotController extends Controller
{
    public function index()
    {
        return BotResource::collection(Bot::paginate(20));
    }
}

// V2 controller - new response format
namespace App\Http\Controllers\V2;

class BotController extends Controller
{
    public function index()
    {
        return BotResourceV2::collection(Bot::paginate(20));
    }
}
```

**Why it's better:**
- Breaking changes contained to new version
- Old clients continue working
- Gradual migration path
- Clear deprecation timeline

## Project-Specific Notes

**BotFacebook API Versioning:**
```php
// routes/api.php
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // Current stable version
    Route::apiResource('bots', BotController::class);
});

// Webhook routes (no versioning needed - internal)
Route::prefix('webhooks')->group(function () {
    Route::post('line', [LineWebhookController::class, 'handle']);
});
```

**When to Version:**
- Response format changes
- Removing fields
- Changing field types
- Behavioral changes

**When NOT to Version:**
- Adding optional fields
- Adding new endpoints
- Bug fixes
- Internal APIs

## References

- [API Versioning Best Practices](https://www.freecodecamp.org/news/how-to-version-a-rest-api/)
