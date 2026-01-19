---
id: api-002-http-verbs
title: Correct HTTP Methods
impact: MEDIUM
impactDescription: "Wrong HTTP methods break caching, cause unexpected side effects"
category: api
tags: [api, rest, http, methods]
relatedRules: [api-001-restful-naming, api-003-validation]
---

## Why This Matters

HTTP methods have specific semantics. Using them correctly enables caching, makes APIs predictable, and prevents unexpected side effects.

## Bad Example

```php
// GET with side effects
Route::get('/bots/{bot}/visit', function (Bot $bot) {
    $bot->increment('visits'); // Modifying data with GET!
    return $bot;
});

// POST for retrieval
Route::post('/bots/search', function (Request $request) {
    return Bot::where('name', 'like', $request->q)->get();
    // Should be GET with query params
});

// DELETE that returns data
Route::delete('/bots/{bot}', function (Bot $bot) {
    $data = $bot->toArray();
    $bot->delete();
    return response()->json($data); // Non-standard
});
```

**Why it's wrong:**
- GET should be safe (no side effects)
- POST for retrieval breaks caching
- Non-standard responses confuse clients

## Good Example

```php
// GET: Read only, cacheable
Route::get('/bots', [BotController::class, 'index']);
Route::get('/bots/{bot}', [BotController::class, 'show']);
Route::get('/bots/search', function (Request $request) {
    return Bot::where('name', 'like', "%{$request->q}%")->get();
});

// POST: Create resource
Route::post('/bots', [BotController::class, 'store']);

// PUT: Full update (idempotent)
Route::put('/bots/{bot}', [BotController::class, 'update']);

// PATCH: Partial update
Route::patch('/bots/{bot}', [BotController::class, 'partialUpdate']);

// DELETE: Remove resource
Route::delete('/bots/{bot}', [BotController::class, 'destroy']);
// Returns 204 No Content
```

**Why it's better:**
- Correct HTTP semantics
- Cacheable reads
- Idempotent updates
- Standard responses

## Review Checklist

- [ ] GET has no side effects
- [ ] POST creates new resources
- [ ] PUT for full replacement (idempotent)
- [ ] PATCH for partial updates
- [ ] DELETE returns 204 No Content

## Detection

```bash
# GET with modifications
grep -A 10 "Route::get" routes/api.php | grep "save()\|update(\|delete(\|create("

# POST for reads
grep -A 5 "Route::post" routes/api.php | grep -i "search\|list\|get"
```

## Project-Specific Notes

**BotFacebook HTTP Method Reference:**

| Method | Purpose | Idempotent | Safe | Example |
|--------|---------|------------|------|---------|
| GET | Read | Yes | Yes | `GET /bots` |
| POST | Create | No | No | `POST /bots` |
| PUT | Replace | Yes | No | `PUT /bots/1` |
| PATCH | Partial update | No | No | `PATCH /bots/1` |
| DELETE | Remove | Yes | No | `DELETE /bots/1` |

```php
// Controller implementation
class BotController extends Controller
{
    public function index(): JsonResponse
    {
        return BotResource::collection(auth()->user()->bots);
    }

    public function store(StoreBotRequest $request): JsonResponse
    {
        $bot = $this->botService->create(auth()->user(), $request->validated());
        return (new BotResource($bot))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateBotRequest $request, Bot $bot): BotResource
    {
        $this->authorize('update', $bot);
        $bot->update($request->validated());
        return new BotResource($bot);
    }

    public function destroy(Bot $bot): JsonResponse
    {
        $this->authorize('delete', $bot);
        $bot->delete();
        return response()->json(null, 204);
    }
}
```
