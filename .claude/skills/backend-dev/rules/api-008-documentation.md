---
id: api-008-documentation
title: API Documentation
impact: LOW
impactDescription: "Enables developers to integrate without reading source code"
category: api
tags: [api, documentation, openapi]
relatedRules: [api-001-response-format]
---

## Why This Matters

Good API documentation makes integration easy and reduces support burden. Without it, developers must reverse-engineer your API or constantly ask questions.

## Bad Example

```php
// No documentation - developers guess
// "What fields are required?"
// "What's the response format?"
// "What errors can occur?"
```

**Why it's wrong:**
- Integration friction
- Support overhead
- Inconsistent usage
- Developer frustration

## Good Example

```php
// Use PHPDoc for IDE support and potential doc generation

/**
 * Create a new bot.
 *
 * @bodyParam name string required The bot name. Example: My Bot
 * @bodyParam platform string required The platform. Example: line
 * @bodyParam description string optional Bot description. Example: A helpful bot
 *
 * @response 201 {
 *   "data": {
 *     "id": 1,
 *     "name": "My Bot",
 *     "platform": "line"
 *   }
 * }
 *
 * @response 422 {
 *   "errors": [{"field": "name", "message": "The name field is required."}]
 * }
 */
public function store(StoreBotRequest $request): BotResource
{
    $bot = $this->service->create(auth()->user(), $request->validated());
    return new BotResource($bot);
}
```

**Why it's better:**
- Self-documenting code
- IDE autocomplete
- Can generate OpenAPI spec
- Examples included

## Project-Specific Notes

**BotFacebook Documentation:**

```bash
# Generate OpenAPI spec (if using Scribe)
php artisan scribe:generate
```

**Minimum Documentation:**
1. Request format (required/optional fields)
2. Response format (success/error)
3. Authentication requirements
4. Rate limits
5. Error codes

**Quick Reference Format:**
```markdown
## POST /api/v1/bots

Create a new bot.

**Headers:**
- Authorization: Bearer {token}

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | string | yes | Bot name (max 255) |
| platform | string | yes | line, telegram, or messenger |

**Response:** 201 Created
```

## References

- [OpenAPI Specification](https://swagger.io/specification/)
- [Laravel Scribe](https://scribe.knuckles.wtf/)
