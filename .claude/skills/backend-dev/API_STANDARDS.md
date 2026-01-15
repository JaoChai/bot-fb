# API Standards

## Response Format

### Success Response

```json
{
  "data": {
    "id": 1,
    "name": "My Bot",
    "platform": "line"
  },
  "meta": {
    "timestamp": "2026-01-14T08:00:00.000Z"
  }
}
```

### Collection Response

```json
{
  "data": [
    { "id": 1, "name": "Bot 1" },
    { "id": 2, "name": "Bot 2" }
  ],
  "meta": {
    "timestamp": "2026-01-14T08:00:00.000Z",
    "pagination": {
      "total": 100,
      "per_page": 20,
      "current_page": 1,
      "last_page": 5
    }
  }
}
```

### Error Response

```json
{
  "data": null,
  "meta": {
    "timestamp": "2026-01-14T08:00:00.000Z"
  },
  "errors": [
    {
      "field": "name",
      "message": "The name field is required."
    }
  ]
}
```

## HTTP Status Codes

| Code | Status | Use Case |
|------|--------|----------|
| 200 | OK | Successful GET, PUT, PATCH |
| 201 | Created | Successful POST (resource created) |
| 204 | No Content | Successful DELETE |
| 400 | Bad Request | Malformed request syntax |
| 401 | Unauthorized | Missing or invalid auth token |
| 403 | Forbidden | Valid auth but no permission |
| 404 | Not Found | Resource doesn't exist |
| 422 | Unprocessable | Validation errors |
| 429 | Too Many Requests | Rate limit exceeded |
| 500 | Server Error | Unexpected server error |

## RESTful Routes

### Standard Resource Routes

```php
// routes/api.php
Route::apiResource('bots', BotController::class);

// Generates:
// GET    /api/v1/bots           → index()
// POST   /api/v1/bots           → store()
// GET    /api/v1/bots/{bot}     → show()
// PUT    /api/v1/bots/{bot}     → update()
// DELETE /api/v1/bots/{bot}     → destroy()
```

### Nested Resources

```php
Route::apiResource('bots.flows', BotFlowController::class);

// Generates:
// GET    /api/v1/bots/{bot}/flows
// POST   /api/v1/bots/{bot}/flows
// GET    /api/v1/bots/{bot}/flows/{flow}
// PUT    /api/v1/bots/{bot}/flows/{flow}
// DELETE /api/v1/bots/{bot}/flows/{flow}
```

### Custom Actions

```php
// Non-CRUD actions
Route::post('bots/{bot}/activate', [BotController::class, 'activate']);
Route::post('bots/{bot}/deactivate', [BotController::class, 'deactivate']);
Route::get('bots/{bot}/analytics', [BotAnalyticsController::class, 'show']);
```

## Naming Conventions

### URL Patterns

| Pattern | Example | Description |
|---------|---------|-------------|
| Collection | `/bots` | List of resources |
| Instance | `/bots/{id}` | Single resource |
| Nested | `/bots/{id}/flows` | Related collection |
| Action | `/bots/{id}/activate` | Custom action |

### Naming Rules

- Use **plural nouns** for collections: `/bots`, `/users`, `/flows`
- Use **kebab-case** for multi-word: `/bot-settings`, `/api-keys`
- Use **camelCase** for JSON fields: `createdAt`, `botId`
- Use **query params** for filtering: `/bots?platform=line&active=true`

## Versioning

```php
// routes/api.php
Route::prefix('v1')->group(function () {
    Route::apiResource('bots', BotController::class);
});

// URL: /api/v1/bots
```

## Authentication

### Bearer Token

```http
GET /api/v1/bots
Authorization: Bearer eyJhbGciOiJIUzI1NiIs...
```

### Response for Unauthenticated

```json
{
  "data": null,
  "meta": { "timestamp": "..." },
  "errors": [{ "message": "Unauthenticated." }]
}
```

## Pagination

### Request

```http
GET /api/v1/bots?page=2&per_page=20
```

### Response

```json
{
  "data": [...],
  "meta": {
    "timestamp": "...",
    "pagination": {
      "total": 100,
      "per_page": 20,
      "current_page": 2,
      "last_page": 5,
      "from": 21,
      "to": 40
    }
  },
  "links": {
    "first": "/api/v1/bots?page=1",
    "last": "/api/v1/bots?page=5",
    "prev": "/api/v1/bots?page=1",
    "next": "/api/v1/bots?page=3"
  }
}
```

## Filtering & Sorting

### Query Parameters

```http
GET /api/v1/bots?platform=line&active=true&sort=-created_at
```

| Parameter | Example | Description |
|-----------|---------|-------------|
| Filter | `?platform=line` | Exact match |
| Multiple | `?platform=line,telegram` | IN clause |
| Search | `?search=keyword` | LIKE search |
| Sort | `?sort=name` | Ascending |
| Sort desc | `?sort=-name` | Descending |
| Multi-sort | `?sort=-created_at,name` | Multiple fields |

### Controller Implementation

```php
public function index(Request $request)
{
    $query = Bot::query();

    // Filter
    if ($platform = $request->query('platform')) {
        $query->whereIn('platform', explode(',', $platform));
    }

    // Search
    if ($search = $request->query('search')) {
        $query->where('name', 'ilike', "%{$search}%");
    }

    // Sort
    $sort = $request->query('sort', '-created_at');
    foreach (explode(',', $sort) as $field) {
        $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
        $field = ltrim($field, '-');
        $query->orderBy($field, $direction);
    }

    return BotResource::collection($query->paginate());
}
```

## Rate Limiting

### Configuration

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->throttleApi('60,1'); // 60 requests per minute
})
```

### Response Headers

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1705234567
```

### Rate Limited Response (429)

```json
{
  "data": null,
  "meta": { "timestamp": "..." },
  "errors": [{ "message": "Too Many Requests" }]
}
```

## Validation Errors

### Request

```http
POST /api/v1/bots
Content-Type: application/json

{ "name": "", "platform": "invalid" }
```

### Response (422)

```json
{
  "data": null,
  "meta": { "timestamp": "..." },
  "errors": [
    { "field": "name", "message": "The name field is required." },
    { "field": "platform", "message": "The selected platform is invalid." }
  ]
}
```

## Best Practices

### DO

- Use consistent response format
- Version your API
- Use proper HTTP status codes
- Implement pagination for lists
- Return created/updated resources
- Use HTTPS in production

### DON'T

- Don't use verbs in URLs (`/getBot`, `/createBot`)
- Don't return sensitive data (passwords, tokens)
- Don't ignore rate limiting
- Don't return inconsistent field names
- Don't use GET for mutations
