---
id: api-001-response-format
title: Standard API Response Format
impact: CRITICAL
impactDescription: "Ensures consistent API responses for frontend consumption and error handling"
category: api
tags: [api, response, json, format]
relatedRules: [api-003-http-status, laravel-005-api-resource]
---

## Why This Matters

A consistent API response format makes frontend integration predictable and error handling uniform. Without standardization, every endpoint requires custom handling, leading to bugs and maintenance nightmares.

## Bad Example

```php
// Problem: Inconsistent response formats
public function index()
{
    return Bot::all(); // Raw array
}

public function show($id)
{
    return ['bot' => Bot::find($id)]; // Different wrapper
}

public function store(Request $request)
{
    $bot = Bot::create($request->all());
    return response()->json(['success' => true, 'data' => $bot]); // Another format
}
```

**Why it's wrong:**
- Frontend can't predict response structure
- No consistent metadata
- Error handling varies
- No pagination info

## Good Example

```php
// Standard format:
// {
//   "data": { ... } | [ ... ],
//   "meta": { "timestamp": "...", "pagination": { ... } },
//   "errors": []
// }

// Solution: API Resources with consistent format
class BotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'platform' => $this->platform,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}

// Controller using resources
class BotController extends Controller
{
    public function index()
    {
        $bots = Bot::paginate(20);

        return BotResource::collection($bots)
            ->additional(['meta' => ['timestamp' => now()->toISOString()]]);
    }

    public function show(Bot $bot)
    {
        return new BotResource($bot);
    }

    public function store(StoreBotRequest $request)
    {
        $bot = $this->service->create($request->validated());

        return (new BotResource($bot))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Bot $bot)
    {
        $this->service->delete($bot);

        return response()->noContent(); // 204
    }
}
```

**Why it's better:**
- Consistent `data` wrapper
- Automatic pagination metadata
- Predictable error format
- Proper status codes

## Project-Specific Notes

**BotFacebook API Response Format:**

```json
// Success (single resource)
{
  "data": {
    "id": 1,
    "name": "My Bot",
    "platform": "line"
  },
  "meta": {
    "timestamp": "2026-01-19T08:00:00.000Z"
  }
}

// Success (collection with pagination)
{
  "data": [
    { "id": 1, "name": "Bot 1" },
    { "id": 2, "name": "Bot 2" }
  ],
  "meta": {
    "timestamp": "2026-01-19T08:00:00.000Z",
    "pagination": {
      "total": 100,
      "per_page": 20,
      "current_page": 1,
      "last_page": 5
    }
  },
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  }
}

// Error (422 validation)
{
  "data": null,
  "meta": { "timestamp": "..." },
  "errors": [
    { "field": "name", "message": "The name field is required." }
  ]
}
```

**Location:** `app/Http/Resources/`

## References

- [Laravel API Resources](https://laravel.com/docs/eloquent-resources)
- Related rule: api-003-http-status
