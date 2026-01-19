---
id: api-004-response-format
title: Consistent Response Format
impact: HIGH
impactDescription: "Inconsistent responses make API integration difficult"
category: api
tags: [api, response, json, standards]
relatedRules: [backend-004-api-resource, api-005-error-handling]
---

## Why This Matters

Consistent response formats make APIs predictable and easier to integrate. Mixed formats require special handling for each endpoint.

## Bad Example

```php
// Inconsistent structures
public function index()
{
    return Bot::all(); // Raw array

}

public function show(Bot $bot)
{
    return response()->json([
        'status' => 'success',
        'bot' => $bot,
    ]); // Wrapped differently
}

public function store(Request $request)
{
    $bot = Bot::create($request->all());
    return ['data' => $bot, 'message' => 'Created']; // Different wrapper
}

// Inconsistent date formats
return [
    'created_at' => $bot->created_at, // "2024-01-15 12:30:00"
    'updated_at' => $bot->updated_at->format('Y-m-d'), // "2024-01-15"
];
```

**Why it's wrong:**
- Different wrapper structures
- Inconsistent field names
- Mixed date formats
- Hard to parse client-side

## Good Example

```php
// API Resource for consistent formatting
class BotResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

// Controllers return Resources
public function index(): AnonymousResourceCollection
{
    return BotResource::collection(auth()->user()->bots);
}

public function show(Bot $bot): BotResource
{
    return new BotResource($bot);
}

public function store(StoreBotRequest $request): JsonResponse
{
    $bot = $this->botService->create(auth()->user(), $request->validated());

    return (new BotResource($bot))
        ->response()
        ->setStatusCode(201);
}

// Consistent collection response
{
    "data": [
        { "id": 1, "name": "Bot 1", ... },
        { "id": 2, "name": "Bot 2", ... }
    ],
    "links": { "first": "...", "last": "...", ... },
    "meta": { "current_page": 1, "total": 50, ... }
}
```

**Why it's better:**
- Always `data` wrapper
- Consistent field names
- ISO8601 dates
- Pagination included

## Review Checklist

- [ ] All responses use API Resources
- [ ] Collection endpoints include pagination
- [ ] Dates in ISO8601 format
- [ ] Consistent `data` wrapper
- [ ] HTTP status codes match action

## Detection

```bash
# Raw model returns
grep -rn "return \$" --include="*.php" app/Http/Controllers/Api/ | grep -v "Resource\|response("

# Inconsistent date formatting
grep -rn "->format(" --include="*.php" app/Http/Resources/
```

## Project-Specific Notes

**BotFacebook Response Standards:**

```php
// Standard response structure
{
    "data": {
        "id": 1,
        "name": "My Bot",
        "slug": "my-bot",
        "is_active": true,
        "created_at": "2024-01-15T12:30:00Z",
        "updated_at": "2024-01-15T14:00:00Z"
    }
}

// Collection with pagination
{
    "data": [...],
    "links": {
        "first": "https://api.botjao.com/v1/bots?page=1",
        "last": "https://api.botjao.com/v1/bots?page=5",
        "prev": null,
        "next": "https://api.botjao.com/v1/bots?page=2"
    },
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 5,
        "per_page": 15,
        "to": 15,
        "total": 67
    }
}

// Status codes
201: Created (POST success)
200: OK (GET, PUT, PATCH success)
204: No Content (DELETE success)
400: Bad Request (validation error)
401: Unauthorized
403: Forbidden
404: Not Found
422: Unprocessable Entity (business logic error)
500: Server Error
```
