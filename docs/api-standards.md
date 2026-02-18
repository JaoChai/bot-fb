# API Standards

มาตรฐาน API Design สำหรับโปรเจกต์ BotFacebook

## Response Format

### ทุก API Response ต้องมี Format นี้

```json
{
  "data": {
    // Actual response data
  },
  "meta": {
    "timestamp": "2026-01-08T12:00:00+07:00",
    "version": "v1"
  },
  "errors": []
}
```

### Success Response
```json
{
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  },
  "meta": {
    "timestamp": "2026-01-08T12:00:00+07:00"
  },
  "errors": []
}
```

### Error Response
```json
{
  "data": null,
  "meta": {
    "timestamp": "2026-01-08T12:00:00+07:00"
  },
  "errors": [
    {
      "code": "VALIDATION_ERROR",
      "message": "Invalid email format",
      "field": "email"
    }
  ]
}
```

### Pagination Response
```json
{
  "data": [
    // Array of items
  ],
  "meta": {
    "timestamp": "2026-01-08T12:00:00+07:00",
    "pagination": {
      "total": 100,
      "per_page": 15,
      "current_page": 1,
      "last_page": 7
    }
  },
  "errors": []
}
```

---

## HTTP Status Codes

| Code | ใช้เมื่อ | ตัวอย่าง |
|------|---------|----------|
| 200 | Success (GET, PUT, DELETE) | ดึงข้อมูลสำเร็จ |
| 201 | Created (POST) | สร้างข้อมูลใหม่สำเร็จ |
| 204 | No Content | ลบสำเร็จ (ไม่ return data) |
| 400 | Bad Request | Request format ผิด |
| 401 | Unauthorized | ไม่ได้ login หรือ token หมดอายุ |
| 403 | Forbidden | Login แล้วแต่ไม่มีสิทธิ์ |
| 404 | Not Found | ไม่เจอ resource |
| 422 | Validation Error | Data validation ไม่ผ่าน |
| 429 | Too Many Requests | เกิน rate limit |
| 500 | Server Error | Server error ทั่วไป |
| 503 | Service Unavailable | Server maintenance |

---

## RESTful URL Patterns

### Resource Naming
```
✅ Good
/api/v1/bots
/api/v1/conversations
/api/v1/users

❌ Bad
/api/v1/getBots
/api/v1/conversationList
/api/v1/user-data
```

### CRUD Operations
```
GET    /api/v1/bots           # List all bots
POST   /api/v1/bots           # Create new bot
GET    /api/v1/bots/{id}      # Get specific bot
PUT    /api/v1/bots/{id}      # Update bot (full)
PATCH  /api/v1/bots/{id}      # Update bot (partial)
DELETE /api/v1/bots/{id}      # Delete bot
```

### Nested Resources
```
GET    /api/v1/bots/{id}/conversations
POST   /api/v1/bots/{id}/conversations
GET    /api/v1/conversations/{id}/messages
```

### Actions (Non-CRUD)
```
POST   /api/v1/bots/{id}/activate
POST   /api/v1/bots/{id}/deactivate
POST   /api/v1/conversations/{id}/close
```

---

## Versioning

### URL Versioning
```
https://api.botjao.com/api/v1/bots
https://api.botjao.com/api/v2/bots
```

### When to Version
- Breaking changes (เปลี่ยน response structure)
- เปลี่ยน authentication method
- เปลี่ยน business logic ที่ affect client

### Backward Compatibility
- v1 ต้องทำงานได้อย่างน้อย 6 เดือนหลัง v2 launch
- Deprecation warning ใน response headers:
  ```
  X-API-Warning: This endpoint is deprecated. Please use /api/v2/bots
  ```

---

## Query Parameters

### Filtering
```
GET /api/v1/bots?status=active
GET /api/v1/bots?status=active&channel_type=line
```

### Sorting
```
GET /api/v1/bots?sort=created_at
GET /api/v1/bots?sort=-created_at  # desc
```

### Pagination
```
GET /api/v1/bots?page=2&per_page=15
```

### Search
```
GET /api/v1/bots?search=keyword
```

### Field Selection (Partial Response)
```
GET /api/v1/bots?fields=id,name,status
```

---

## Request Headers

### Required
```
Content-Type: application/json
Accept: application/json
Authorization: Bearer {token}
```

### Optional
```
X-Request-ID: unique-request-id  # For tracing
Accept-Language: th-TH           # For i18n
```

---

## Authentication

### JWT Bearer Token
```bash
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

### Token Expiration
- Access Token: 1 hour
- Refresh Token: 30 days

### Refresh Flow
```
POST /api/v1/auth/refresh
{
  "refresh_token": "..."
}

Response:
{
  "data": {
    "access_token": "...",
    "refresh_token": "...",
    "expires_in": 3600
  }
}
```

---

## Rate Limiting

### Limits
```
API General: 60 requests/minute
Login: 5 attempts/minute
Webhook: 100 requests/minute
```

### Response Headers
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1704700800
```

### Rate Limit Exceeded
```json
{
  "data": null,
  "meta": {
    "timestamp": "2026-01-08T12:00:00+07:00"
  },
  "errors": [
    {
      "code": "RATE_LIMIT_EXCEEDED",
      "message": "Too many requests. Please try again later.",
      "retry_after": 60
    }
  ]
}
```

---

## Error Codes

### Application-Level Error Codes
```
VALIDATION_ERROR        # 422
UNAUTHORIZED            # 401
FORBIDDEN               # 403
NOT_FOUND               # 404
RESOURCE_CONFLICT       # 409
RATE_LIMIT_EXCEEDED     # 429
INTERNAL_SERVER_ERROR   # 500
SERVICE_UNAVAILABLE     # 503
```

### Domain-Specific Error Codes
```
BOT_NOT_FOUND
CONVERSATION_CLOSED
MESSAGE_TOO_LONG
WEBHOOK_DELIVERY_FAILED
```

---

## Validation Rules

### Request Validation
```php
// Laravel FormRequest
public function rules()
{
    return [
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users',
        'channel_type' => 'required|in:line,telegram',
    ];
}
```

### Validation Error Response
```json
{
  "data": null,
  "meta": {
    "timestamp": "2026-01-08T12:00:00+07:00"
  },
  "errors": [
    {
      "code": "VALIDATION_ERROR",
      "message": "The email has already been taken.",
      "field": "email"
    },
    {
      "code": "VALIDATION_ERROR",
      "message": "The name field is required.",
      "field": "name"
    }
  ]
}
```

---

## Best Practices

### 1. ใช้ Plural Nouns
```
✅ /api/v1/bots
❌ /api/v1/bot
```

### 2. Lowercase URLs
```
✅ /api/v1/conversations
❌ /api/v1/Conversations
```

### 3. Use Hyphens (not underscores)
```
✅ /api/v1/channel-types
❌ /api/v1/channel_types
```

### 4. Don't Use File Extensions
```
✅ /api/v1/bots
❌ /api/v1/bots.json
```

### 5. Filter & Sort in Query, Not URL
```
✅ /api/v1/bots?status=active
❌ /api/v1/active-bots
```

### 6. Version in URL, Not Header
```
✅ /api/v1/bots
❌ /api/bots (with Accept: application/vnd.api.v1+json)
```

---

## Testing Checklist

เมื่อสร้าง API ใหม่ ต้องตรวจสอบ:

- [ ] Response format ถูกต้อง (data, meta, errors)
- [ ] HTTP status codes เหมาะสม
- [ ] Validation rules ครบถ้วน
- [ ] Error messages ชัดเจน
- [ ] Authentication/Authorization ทำงาน
- [ ] Rate limiting configured
- [ ] Documentation updated
- [ ] Tests written

---

## Example: Complete CRUD API

```php
// routes/api.php
Route::prefix('v1')->middleware('auth:api')->group(function () {
    Route::apiResource('bots', BotController::class);
});

// app/Http/Controllers/BotController.php
public function index()
{
    $bots = Bot::paginate(15);

    return response()->json([
        'data' => $bots->items(),
        'meta' => [
            'timestamp' => now()->toIso8601String(),
            'pagination' => [
                'total' => $bots->total(),
                'per_page' => $bots->perPage(),
                'current_page' => $bots->currentPage(),
                'last_page' => $bots->lastPage(),
            ],
        ],
        'errors' => [],
    ]);
}

public function store(StoreBotRequest $request)
{
    $bot = Bot::create($request->validated());

    return response()->json([
        'data' => $bot,
        'meta' => ['timestamp' => now()->toIso8601String()],
        'errors' => [],
    ], 201);
}

public function show(Bot $bot)
{
    return response()->json([
        'data' => $bot,
        'meta' => ['timestamp' => now()->toIso8601String()],
        'errors' => [],
    ]);
}

public function update(UpdateBotRequest $request, Bot $bot)
{
    $bot->update($request->validated());

    return response()->json([
        'data' => $bot,
        'meta' => ['timestamp' => now()->toIso8601String()],
        'errors' => [],
    ]);
}

public function destroy(Bot $bot)
{
    $bot->delete();

    return response()->json(null, 204);
}
```
