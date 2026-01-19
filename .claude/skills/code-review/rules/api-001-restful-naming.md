---
id: api-001-restful-naming
title: RESTful Resource Naming
impact: HIGH
impactDescription: "Inconsistent API names make integration difficult and confusing"
category: api
tags: [api, rest, naming, conventions]
relatedRules: [api-002-http-verbs, api-004-response-format]
---

## Why This Matters

RESTful naming conventions make APIs predictable and self-documenting. Inconsistent naming confuses developers and leads to integration errors.

## Bad Example

```php
// Verb in URL (should be HTTP method)
Route::get('/getBots', [BotController::class, 'getBots']);
Route::post('/createBot', [BotController::class, 'createBot']);
Route::post('/deleteBot/{id}', [BotController::class, 'deleteBot']);

// Singular instead of plural
Route::get('/bot/{id}', [BotController::class, 'show']);

// Mixed naming
Route::get('/user-bots', [BotController::class, 'index']);
Route::get('/conversation_messages', [MessageController::class, 'index']);

// Deeply nested (>2 levels)
Route::get('/users/{user}/bots/{bot}/conversations/{conv}/messages', ...);
```

**Why it's wrong:**
- Verbs should be HTTP methods
- Inconsistent pluralization
- Mixed naming conventions
- Over-nested resources

## Good Example

```php
// RESTful resource routes
Route::apiResource('bots', BotController::class);
// Creates: GET /bots, GET /bots/{bot}, POST /bots, PUT /bots/{bot}, DELETE /bots/{bot}

// Nested resources (max 2 levels)
Route::apiResource('bots.conversations', ConversationController::class)
    ->shallow();
// Creates: GET /bots/{bot}/conversations, GET /conversations/{conversation}

// Custom actions use verbs as sub-resources
Route::post('/bots/{bot}/activate', [BotController::class, 'activate']);
Route::post('/bots/{bot}/test', [BotController::class, 'test']);

// Query params for filtering (not URL)
Route::get('/bots', [BotController::class, 'index']);
// Use: GET /bots?status=active&platform=line
```

**Why it's better:**
- Nouns in URLs, verbs in methods
- Consistent plural names
- Shallow nesting
- Query params for filters

## Review Checklist

- [ ] URLs use plural nouns (`/bots` not `/bot`)
- [ ] No verbs in URLs (except action sub-resources)
- [ ] HTTP methods indicate action
- [ ] Maximum 2 levels of nesting
- [ ] Use `apiResource()` when possible

## Detection

```bash
# Verbs in routes
grep -rn "Route::" routes/api.php | grep -i "get\|create\|delete\|update" | grep -v "->get\|->post"

# Singular resources
grep -rn "Route::" routes/api.php | grep -E "'/[a-z]+/{" | grep -v "s/{"

# Deep nesting
grep -rn "Route::" routes/api.php | grep -o "/{[^}]*}" | uniq -c | sort -rn
```

## Project-Specific Notes

**BotFacebook API Structure:**

```php
// routes/api.php
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // Resources
    Route::apiResource('bots', BotController::class);
    Route::apiResource('bots.conversations', ConversationController::class)->shallow();
    Route::apiResource('bots.knowledge', KnowledgeDocumentController::class)->shallow();

    // Custom actions
    Route::post('bots/{bot}/activate', [BotController::class, 'activate']);
    Route::post('bots/{bot}/test', [BotController::class, 'test']);
    Route::get('bots/{bot}/analytics', [BotAnalyticsController::class, 'show']);

    // User resources
    Route::get('user', [UserController::class, 'show']);
    Route::put('user', [UserController::class, 'update']);
});

// Webhooks (no auth)
Route::prefix('webhooks')->group(function () {
    Route::post('line/{bot}', [LineWebhookController::class, 'handle']);
    Route::post('telegram/{bot}', [TelegramWebhookController::class, 'handle']);
});
```
