---
id: api-005-error-handling
title: API Error Handling
impact: MEDIUM
impactDescription: "Poor error responses make debugging and client handling difficult"
category: api
tags: [api, errors, exceptions, standards]
relatedRules: [api-004-response-format, security-006-data-exposure]
---

## Why This Matters

Good error responses help clients handle failures gracefully and developers debug issues quickly. Generic or inconsistent errors waste everyone's time.

## Bad Example

```php
// Generic error
public function show($id)
{
    $bot = Bot::find($id);
    if (!$bot) {
        return response()->json(['error' => 'Error'], 500);
    }
}

// Exposing internal details
public function store(Request $request)
{
    try {
        return Bot::create($request->all());
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString() // Security risk!
        ], 500);
    }
}

// Inconsistent error format
// Sometimes: { "error": "message" }
// Sometimes: { "message": "error" }
// Sometimes: { "errors": [...] }
```

**Why it's wrong:**
- Generic messages unhelpful
- Internal details exposed
- Inconsistent structure
- Wrong status codes

## Good Example

```php
// Custom exception with proper response
class BotNotFoundException extends Exception
{
    public function render($request)
    {
        return response()->json([
            'error' => [
                'code' => 'BOT_NOT_FOUND',
                'message' => 'The requested bot does not exist',
            ]
        ], 404);
    }
}

// Handler for consistent errors
// app/Exceptions/Handler.php
public function render($request, Throwable $e)
{
    if ($request->expectsJson()) {
        return $this->handleApiException($request, $e);
    }

    return parent::render($request, $e);
}

private function handleApiException($request, Throwable $e): JsonResponse
{
    $status = $this->getStatusCode($e);

    return response()->json([
        'error' => [
            'code' => $this->getErrorCode($e),
            'message' => $this->getMessage($e),
            'errors' => $e instanceof ValidationException
                ? $e->errors()
                : null,
        ]
    ], $status);
}

// Usage in controller
public function show(Bot $bot) // Uses route model binding (auto 404)
{
    $this->authorize('view', $bot);
    return new BotResource($bot);
}
```

**Why it's better:**
- Consistent error structure
- Machine-readable codes
- Human-readable messages
- No internal details exposed

## Review Checklist

- [ ] Consistent error response structure
- [ ] Machine-readable error codes
- [ ] Human-readable messages
- [ ] No stack traces in production
- [ ] Correct HTTP status codes

## Detection

```bash
# Exposing traces
grep -rn "getTraceAsString\|->trace" --include="*.php" app/

# Generic error messages
grep -rn "'error'\|\"error\"" --include="*.php" app/Http/Controllers/

# Inconsistent error formats
grep -rn "response()->json.*error" --include="*.php" app/
```

## Project-Specific Notes

**BotFacebook Error Format:**

```json
// Validation error (422)
{
    "error": {
        "code": "VALIDATION_ERROR",
        "message": "The given data was invalid.",
        "errors": {
            "name": ["The name field is required."],
            "model": ["The selected model is invalid."]
        }
    }
}

// Not found (404)
{
    "error": {
        "code": "BOT_NOT_FOUND",
        "message": "The requested bot does not exist."
    }
}

// Authorization (403)
{
    "error": {
        "code": "FORBIDDEN",
        "message": "You do not have permission to access this bot."
    }
}

// Rate limit (429)
{
    "error": {
        "code": "RATE_LIMIT_EXCEEDED",
        "message": "Too many requests. Please try again in 60 seconds.",
        "retry_after": 60
    }
}

// Server error (500)
{
    "error": {
        "code": "SERVER_ERROR",
        "message": "An unexpected error occurred. Please try again later."
    }
}
```

**Error Codes Used:**
- `VALIDATION_ERROR` - Input validation failed
- `BOT_NOT_FOUND` - Resource not found
- `FORBIDDEN` - Authorization failed
- `RATE_LIMIT_EXCEEDED` - Too many requests
- `SUBSCRIPTION_REQUIRED` - Feature requires subscription
- `QUOTA_EXCEEDED` - Usage limit reached
- `SERVER_ERROR` - Internal error
