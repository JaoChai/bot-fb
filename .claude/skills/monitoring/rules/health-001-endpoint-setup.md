---
id: health-001-endpoint-setup
title: Health Check Endpoint Setup
impact: HIGH
impactDescription: "Missing health checks prevent detecting service failures"
category: health
tags: [health, monitoring, endpoint, uptime]
relatedRules: [health-002-service-checks, alerts-001-error-alerts]
---

## Symptom

- Can't tell if service is healthy
- No automated uptime monitoring
- Deploy verification manual
- Load balancer can't route properly

## Root Cause

1. No health endpoint
2. Health check too simple
3. Doesn't check dependencies
4. Returns wrong status codes
5. No caching/rate limiting

## Diagnosis

### Quick Check

```bash
# Check if health endpoint exists
curl -i https://api.botjao.com/health

# Check response time
curl -w "Time: %{time_total}s\n" -o /dev/null -s https://api.botjao.com/health
```

## Solution

### Basic Health Endpoint

```php
// routes/api.php
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});
```

### Comprehensive Health Check

```php
// routes/api.php
Route::get('/health', function () {
    $checks = [];
    $status = 'ok';

    // Database check
    try {
        DB::connection()->getPdo();
        $checks['database'] = 'ok';
    } catch (\Exception $e) {
        $checks['database'] = 'error';
        $status = 'error';
    }

    // Cache check
    try {
        Cache::put('health_check', true, 10);
        $checks['cache'] = Cache::get('health_check') ? 'ok' : 'error';
    } catch (\Exception $e) {
        $checks['cache'] = 'error';
        $status = 'degraded';
    }

    // Queue check
    try {
        $queueSize = Queue::size('default');
        $checks['queue'] = [
            'status' => $queueSize < 1000 ? 'ok' : 'warning',
            'size' => $queueSize,
        ];
    } catch (\Exception $e) {
        $checks['queue'] = 'error';
        $status = 'degraded';
    }

    $httpStatus = $status === 'ok' ? 200 : ($status === 'degraded' ? 200 : 503);

    return response()->json([
        'status' => $status,
        'timestamp' => now()->toIso8601String(),
        'checks' => $checks,
        'version' => config('app.version', env('RAILWAY_GIT_COMMIT_SHA', 'unknown')),
    ], $httpStatus);
});
```

### Health Check Controller

```php
// app/Http/Controllers/HealthController.php
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $health = new HealthChecker();

        return response()->json([
            'status' => $health->getStatus(),
            'timestamp' => now()->toIso8601String(),
            'checks' => $health->getChecks(),
            'version' => $health->getVersion(),
        ], $health->getHttpStatus());
    }
}

class HealthChecker
{
    private array $checks = [];
    private string $status = 'ok';

    public function __construct()
    {
        $this->checkDatabase();
        $this->checkCache();
        $this->checkQueue();
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
            $this->checks['database'] = 'ok';
        } catch (\Exception $e) {
            $this->checks['database'] = 'error';
            $this->status = 'error';
        }
    }

    private function checkCache(): void
    {
        try {
            Cache::store()->get('health');
            $this->checks['cache'] = 'ok';
        } catch (\Exception $e) {
            $this->checks['cache'] = 'error';
            $this->status = $this->status === 'error' ? 'error' : 'degraded';
        }
    }

    private function checkQueue(): void
    {
        try {
            $size = Queue::size('default');
            $this->checks['queue'] = [
                'status' => $size < 500 ? 'ok' : 'warning',
                'size' => $size,
            ];
            if ($size >= 500) {
                $this->status = $this->status === 'error' ? 'error' : 'degraded';
            }
        } catch (\Exception $e) {
            $this->checks['queue'] = 'error';
        }
    }

    public function getStatus(): string { return $this->status; }
    public function getChecks(): array { return $this->checks; }
    public function getVersion(): string { return env('RAILWAY_GIT_COMMIT_SHA', 'unknown'); }
    public function getHttpStatus(): int
    {
        return $this->status === 'ok' ? 200 : ($this->status === 'degraded' ? 200 : 503);
    }
}
```

### Response Format

```json
{
  "status": "ok",
  "timestamp": "2026-01-17T10:30:00Z",
  "checks": {
    "database": "ok",
    "cache": "ok",
    "queue": {
      "status": "ok",
      "size": 5
    }
  },
  "version": "abc123def"
}
```

### Status Codes

| Status | HTTP | Meaning |
|--------|------|---------|
| ok | 200 | All systems operational |
| degraded | 200 | Non-critical issue |
| error | 503 | Critical service down |

## Verification

```bash
# Test health endpoint
curl -i https://api.botjao.com/health

# Should return:
# HTTP/2 200
# {"status":"ok",...}

# Test response time (should be < 200ms)
curl -w "%{time_total}\n" -o /dev/null -s https://api.botjao.com/health
```

## Prevention

- Keep health checks fast
- Don't check non-critical services
- Cache external dependency checks
- Monitor health endpoint uptime
- Test health check in CI

## Project-Specific Notes

**BotFacebook Context:**
- Endpoint: GET /health
- Checks: database, cache, queue
- Response time target: < 100ms
- Railway: Uses for deploy readiness
