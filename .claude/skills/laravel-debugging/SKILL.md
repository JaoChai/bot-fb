---
name: laravel-debugging
description: Debug Laravel errors by getting actual exception messages, checking service instantiation, and validating config. Use when seeing 500 errors, HTTP errors, service failures, "something went wrong" responses, or when troubleshooting backend issues.
---

# Laravel Debugging

## STOP! ก่อนแก้ต้องทำ 3 ขั้นตอนนี้

### 1. หา ACTUAL Error Message
```bash
# Option A: Check health first
curl https://backend-production-b216.up.railway.app/api/health

# Option B: If logs timeout, create debug endpoint
# Add to routes/api.php temporarily:
Route::get('/debug-test', function() {
    try {
        $service = app(App\Services\YourService::class);
        return response()->json(['status' => 'ok']);
    } catch (\Throwable $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ], 500);
    }
});
```

### 2. Identify Error Location

| Error Type | Check |
|-----------|-------|
| DI/Service instantiation | `app(ServiceClass::class)` ใน debug endpoint |
| Controller logic | Stack trace จาก exception |
| Config issues | `config('key')` returns null? |
| Database | Connection refused / query error |

### 3. Test Hypothesis Before Fix

```bash
# Don't guess! Verify with curl or tinker
php artisan tinker
>>> config('services.openrouter.api_key')  # Check if null
>>> app(App\Services\OpenRouterService::class)  # Test instantiation
```

---

## Common Patterns

### Pattern: Config returns null
```php
// BAD - config() returns null when key missing, default '' ignored
$key = config('services.api_key', '');

// GOOD - null coalescing handles actual null
$key = config('services.api_key') ?? '';
```

### Pattern: Service instantiation fails
```php
// Check constructor dependencies
public function __construct()
{
    // Each line here can fail - test individually
    $this->apiKey = config('services.key') ?? '';
    $this->client = new Client(['base_uri' => $this->baseUrl]);
}
```

### Pattern: 500 error without message
```php
// Add try-catch in controller to expose error
try {
    return $this->service->process($request);
} catch (\Throwable $e) {
    Log::error('Controller error', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    return response()->json(['error' => $e->getMessage()], 500);
}
```

---

## Quick Diagnostics

```bash
# 1. Health check
curl -s https://backend-production-b216.up.railway.app/api/health | jq

# 2. Test specific endpoint
curl -s -X POST https://backend-production-b216.up.railway.app/api/stream \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"message":"test"}' | head -100

# 3. Check for HTML error page (means exception)
curl -s URL | grep -i "exception\|error\|trace"
```

---

## Debugging Flow

```
Error → Health Check → Get ACTUAL message → Identify location → ONE fix → Test → Repeat
                ↑                                                           |
                └────────────── ถ้าแก้ 2 ครั้งไม่สำเร็จ STOP ─────────────────┘
```

**Rule: ถ้าแก้ 2 ครั้งไม่สำเร็จ → STOP → Step back → วิเคราะห์ใหม่**

---

## Production URLs

- Backend: `https://backend-production-b216.up.railway.app`
- Health: `https://backend-production-b216.up.railway.app/api/health`
