---
id: api-003-http-status
title: HTTP Status Codes
impact: HIGH
impactDescription: "Enables proper error handling and client-side logic based on response status"
category: api
tags: [api, http, status, response]
relatedRules: [api-001-response-format, api-002-restful-naming]
---

## Why This Matters

HTTP status codes communicate request outcome. Clients use these codes for error handling, retry logic, and UI updates. Using wrong codes (like 200 for errors) breaks client-side logic and makes debugging difficult.

## Bad Example

```php
// Problem: Always returning 200, even for errors
public function store(Request $request)
{
    if (!$request->has('name')) {
        return response()->json(['error' => 'Name required']); // 200 OK!
    }

    try {
        $bot = Bot::create($request->all());
        return response()->json(['success' => true, 'data' => $bot]); // 200 for create
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()]); // 200 for error!
    }
}
```

**Why it's wrong:**
- Client can't detect errors from status
- Retry logic doesn't work
- Error tracking tools miss issues
- Inconsistent behavior

## Good Example

```php
// Solution: Use appropriate HTTP status codes
class BotController extends Controller
{
    public function index()
    {
        $bots = Bot::paginate(20);
        return BotResource::collection($bots); // 200 OK (automatic)
    }

    public function store(StoreBotRequest $request)
    {
        $bot = $this->service->create($request->validated());

        return (new BotResource($bot))
            ->response()
            ->setStatusCode(201); // 201 Created
    }

    public function show(Bot $bot)
    {
        // 404 automatic if not found (route model binding)
        return new BotResource($bot); // 200 OK
    }

    public function update(UpdateBotRequest $request, Bot $bot)
    {
        $this->authorize('update', $bot); // 403 if forbidden

        $bot = $this->service->update($bot, $request->validated());

        return new BotResource($bot); // 200 OK
    }

    public function destroy(Bot $bot)
    {
        $this->authorize('delete', $bot);

        $this->service->delete($bot);

        return response()->noContent(); // 204 No Content
    }
}
```

**Why it's better:**
- Status codes convey meaning
- Clients can handle errors properly
- Automatic retry for 5xx errors
- Cache invalidation on 2xx
- Monitoring tools work correctly

## Project-Specific Notes

**BotFacebook Status Code Usage:**

| Code | Status | Use Case |
|------|--------|----------|
| 200 | OK | Successful GET, PUT, PATCH |
| 201 | Created | Successful POST (resource created) |
| 204 | No Content | Successful DELETE |
| 400 | Bad Request | Malformed request |
| 401 | Unauthorized | Missing/invalid auth token |
| 403 | Forbidden | Valid auth but no permission |
| 404 | Not Found | Resource doesn't exist |
| 422 | Unprocessable | Validation errors |
| 429 | Too Many Requests | Rate limit exceeded |
| 500 | Server Error | Unexpected error |

**Laravel Automatic Codes:**
- Route model binding: 404 if not found
- FormRequest validation: 422 with errors
- `$this->authorize()`: 403 if denied
- Unhandled exception: 500

**Custom Status Codes:**
```php
return response()->json($data, 201); // Created
return response()->noContent(); // 204
abort(403, 'Access denied'); // 403
abort(404); // 404
```

## References

- [HTTP Status Codes](https://developer.mozilla.org/en-US/docs/Web/HTTP/Status)
- [REST API Status Codes](https://restfulapi.net/http-status-codes/)
