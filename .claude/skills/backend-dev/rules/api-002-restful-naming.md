---
id: api-002-restful-naming
title: RESTful Naming Conventions
impact: HIGH
impactDescription: "Ensures consistent, predictable API design that's easy to use"
category: api
tags: [api, rest, naming, routes]
relatedRules: [api-001-response-format, api-003-http-status]
---

## Why This Matters

RESTful naming conventions make APIs predictable and self-documenting. Developers can guess endpoints without documentation. Inconsistent naming confuses API consumers and makes the codebase harder to maintain.

## Bad Example

```php
// Problem: Non-RESTful, verb-based routes
Route::get('/getBot/{id}', [BotController::class, 'getBot']);
Route::post('/createBot', [BotController::class, 'createBot']);
Route::post('/updateBot/{id}', [BotController::class, 'updateBot']); // POST for update!
Route::get('/deleteBotById/{id}', [BotController::class, 'deleteBot']); // GET for delete!
Route::get('/bot-list', [BotController::class, 'list']);
Route::get('/Bot/{id}/getFlows', [BotController::class, 'getFlows']); // Inconsistent case
```

**Why it's wrong:**
- Verbs in URL (REST uses HTTP methods)
- Wrong HTTP methods for actions
- Inconsistent casing
- Unpredictable structure
- Not resource-oriented

## Good Example

```php
// Solution: RESTful resource routes
Route::apiResource('bots', BotController::class);

// Generates:
// GET    /api/v1/bots           → index()
// POST   /api/v1/bots           → store()
// GET    /api/v1/bots/{bot}     → show()
// PUT    /api/v1/bots/{bot}     → update()
// DELETE /api/v1/bots/{bot}     → destroy()

// Nested resources
Route::apiResource('bots.flows', BotFlowController::class);

// Custom actions (when CRUD doesn't fit)
Route::post('bots/{bot}/activate', [BotController::class, 'activate']);
Route::post('bots/{bot}/duplicate', [BotController::class, 'duplicate']);
Route::get('bots/{bot}/analytics', [BotAnalyticsController::class, 'show']);
```

**Why it's better:**
- HTTP method indicates action
- Nouns in URL (resources)
- Consistent kebab-case
- Predictable structure
- Self-documenting

## Project-Specific Notes

**BotFacebook API Structure:**
```php
// routes/api.php
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // Main resources
    Route::apiResource('bots', BotController::class);
    Route::apiResource('bots.flows', BotFlowController::class);
    Route::apiResource('knowledge-bases', KnowledgeBaseController::class);

    // Custom actions
    Route::post('bots/{bot}/activate', [BotController::class, 'activate']);
    Route::post('bots/{bot}/test-message', [BotController::class, 'testMessage']);

    // Analytics (read-only)
    Route::get('bots/{bot}/analytics', [BotAnalyticsController::class, 'show']);
    Route::get('conversations/{conversation}/analytics', [ConversationAnalyticsController::class, 'show']);
});
```

**Naming Rules:**
- Collections: plural nouns (`/bots`, `/users`, `/flows`)
- Multi-word: kebab-case (`/knowledge-bases`, `/api-keys`)
- JSON fields: camelCase (`createdAt`, `botId`)
- Query params: snake_case or camelCase (be consistent)

## References

- [REST API Design Best Practices](https://restfulapi.net/)
- [Laravel API Resources](https://laravel.com/docs/controllers#resource-controllers)
