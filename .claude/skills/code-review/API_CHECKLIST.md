# API Design Checklist

## RESTful Conventions

### URL Structure

| Check | Example |
|-------|---------|
| ✅ Plural nouns | `/bots`, `/users` |
| ✅ Lowercase | `/api/v1/bots` |
| ✅ Kebab-case | `/bot-settings` |
| ❌ Verbs in URL | `/getBot`, `/createUser` |
| ❌ Trailing slash | `/bots/` |

### HTTP Methods

| Method | Purpose | Idempotent | Safe |
|--------|---------|------------|------|
| GET | Read | ✅ | ✅ |
| POST | Create | ❌ | ❌ |
| PUT | Full update | ✅ | ❌ |
| PATCH | Partial update | ✅ | ❌ |
| DELETE | Remove | ✅ | ❌ |

### Status Codes

| Range | Purpose | Examples |
|-------|---------|----------|
| 2xx | Success | 200 OK, 201 Created, 204 No Content |
| 4xx | Client error | 400 Bad Request, 401 Unauthorized, 404 Not Found |
| 5xx | Server error | 500 Internal Server Error |

## Request Validation

### Required Fields

```php
public function rules(): array
{
    return [
        'name' => ['required', 'string', 'max:255'],
        'platform' => ['required', Rule::in(['line', 'telegram'])],
    ];
}
```

### Conditional Validation

```php
public function rules(): array
{
    return [
        'type' => ['required', Rule::in(['text', 'image'])],
        'content' => ['required_if:type,text', 'string'],
        'image_url' => ['required_if:type,image', 'url'],
    ];
}
```

### Sanitization

```php
protected function prepareForValidation(): void
{
    $this->merge([
        'email' => strtolower(trim($this->email)),
        'name' => trim($this->name),
    ]);
}
```

## Response Format

### Standard Structure

```json
{
  "data": {},
  "meta": {
    "timestamp": "2026-01-14T08:00:00.000Z"
  }
}
```

### Pagination

```json
{
  "data": [],
  "meta": {
    "timestamp": "...",
    "pagination": {
      "total": 100,
      "per_page": 20,
      "current_page": 1,
      "last_page": 5
    }
  },
  "links": {
    "first": "?page=1",
    "last": "?page=5",
    "prev": null,
    "next": "?page=2"
  }
}
```

### Errors

```json
{
  "data": null,
  "meta": { "timestamp": "..." },
  "errors": [
    { "field": "name", "message": "The name field is required." }
  ]
}
```

## Consistency Checks

### Naming

| Check | ✅ Correct | ❌ Incorrect |
|-------|-----------|-------------|
| camelCase fields | `createdAt` | `created_at` |
| Consistent booleans | `isActive` | `active`, `is_active` |
| Plural collections | `items` | `item` |
| Singular instance | `item` | `items` |

### Field Types

| Field | Type | Notes |
|-------|------|-------|
| ID | integer/string | Consistent across API |
| Dates | ISO 8601 | `2026-01-14T08:00:00.000Z` |
| Booleans | true/false | Not 0/1 or "true"/"false" |
| Enums | string | lowercase: `active`, `pending` |

### Null Handling

```json
// ❌ Inconsistent
{ "name": "Bot", "description": "" }
{ "name": "Bot" }  // missing field

// ✅ Consistent
{ "name": "Bot", "description": null }
```

## Authentication & Authorization

### Token Usage

- [ ] Bearer token in Authorization header
- [ ] Consistent 401 for auth failures
- [ ] Consistent 403 for permission failures

### Response Codes

| Scenario | Code |
|----------|------|
| No token | 401 |
| Invalid token | 401 |
| Expired token | 401 |
| Valid token, no permission | 403 |

## Rate Limiting

### Headers

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1705234567
```

### Response (429)

```json
{
  "data": null,
  "meta": {
    "timestamp": "...",
    "retry_after": 60
  },
  "errors": [
    { "message": "Too many requests. Retry after 60 seconds." }
  ]
}
```

## Versioning

### URL Versioning

```
/api/v1/bots
/api/v2/bots
```

### Header Versioning

```http
Accept: application/vnd.api+json; version=1
```

## Documentation

### Required Documentation

- [ ] Endpoint description
- [ ] Request parameters
- [ ] Request body schema
- [ ] Response schema
- [ ] Error responses
- [ ] Authentication requirements
- [ ] Rate limits

### Example Documentation

```yaml
/api/v1/bots:
  post:
    summary: Create a new bot
    security:
      - bearerAuth: []
    requestBody:
      required: true
      content:
        application/json:
          schema:
            type: object
            required: [name, platform]
            properties:
              name:
                type: string
                maxLength: 255
              platform:
                type: string
                enum: [line, telegram]
    responses:
      201:
        description: Bot created
      401:
        description: Unauthorized
      422:
        description: Validation error
```

## Quick Review Checklist

### URL Design
- [ ] RESTful resource naming
- [ ] Proper HTTP methods
- [ ] Consistent versioning

### Requests
- [ ] Validation rules defined
- [ ] Proper error messages (Thai if needed)
- [ ] Input sanitization

### Responses
- [ ] Standard format used
- [ ] Proper status codes
- [ ] camelCase field names
- [ ] ISO 8601 dates
- [ ] Null values handled consistently

### Security
- [ ] Authentication required where needed
- [ ] Authorization checked
- [ ] Rate limiting applied
- [ ] Sensitive data filtered

### Documentation
- [ ] All endpoints documented
- [ ] Request/response schemas defined
- [ ] Examples provided
