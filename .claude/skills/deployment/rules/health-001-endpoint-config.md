---
id: health-001-endpoint-config
title: Health Endpoint Configuration
impact: HIGH
impactDescription: "Health checks fail, Railway thinks service is down"
category: health
tags: [health, endpoint, railway, monitoring]
relatedRules: [railway-001-deploy-failure, health-002-component-checks]
---

## Symptom

- Deployment fails health check
- Service marked unhealthy in Railway
- Automatic restarts triggered
- No response from /health endpoint

## Root Cause

1. Health route not defined
2. Endpoint returns wrong status code
3. Response takes too long
4. Endpoint throws exception
5. Wrong path configured in Railway

## Diagnosis

### Quick Check

```bash
# Test health endpoint locally
curl -v http://localhost:8000/health
curl -v http://localhost:8000/api/health

# Test production
curl -v https://api.botjao.com/health

# Check response code (should be 200)
curl -s -o /dev/null -w "%{http_code}" https://api.botjao.com/health
```

### Detailed Analysis

```bash
# Check response time
curl -w "Time: %{time_total}s\n" -o /dev/null -s https://api.botjao.com/health

# Check response body
curl -s https://api.botjao.com/health | jq .

# Check Railway logs for health check failures
railway logs --filter "health" --lines 50
```

## Solution

### Fix Steps

1. **Create health route**
```php
// routes/web.php or routes/api.php
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});
```

2. **Add comprehensive health check**
```php
// routes/api.php
Route::get('/health', [HealthController::class, 'check']);

// app/Http/Controllers/HealthController.php
class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $health = [
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'checks' => [],
        ];

        // Database check
        try {
            DB::connection()->getPdo();
            $health['checks']['database'] = 'connected';
        } catch (\Exception $e) {
            $health['status'] = 'degraded';
            $health['checks']['database'] = 'disconnected';
        }

        // Cache check
        try {
            Cache::put('health-check', true, 10);
            $health['checks']['cache'] = Cache::get('health-check') ? 'working' : 'failed';
        } catch (\Exception $e) {
            $health['status'] = 'degraded';
            $health['checks']['cache'] = 'failed';
        }

        $statusCode = $health['status'] === 'ok' ? 200 : 503;

        return response()->json($health, $statusCode);
    }
}
```

3. **Configure Railway health check**
```toml
# railway.toml
[deploy]
healthcheckPath = "/health"
healthcheckTimeout = 30
```

4. **Ensure fast response**
```php
// Health check should be fast - no heavy operations
Route::get('/health', function () {
    // Don't do: DB queries, external API calls, file operations
    // Do: Simple status response
    return response()->json(['status' => 'ok']);
});
```

### Health Response Format

```json
{
    "status": "ok",
    "timestamp": "2024-01-15T10:30:00Z",
    "checks": {
        "database": "connected",
        "cache": "working",
        "queue": "connected"
    },
    "version": "1.0.0"
}
```

## Verification

```bash
# Test endpoint exists
curl -sf https://api.botjao.com/health && echo "OK" || echo "FAILED"

# Test response time (should be < 1s)
time curl -s https://api.botjao.com/health > /dev/null

# Test response format
curl -s https://api.botjao.com/health | jq '.status'
# Should return "ok"

# Test with Railway
railway status
# Should show healthy
```

## Prevention

- Keep health endpoint simple and fast
- Return 200 for healthy, 503 for unhealthy
- Don't do heavy operations in health check
- Set appropriate timeout in Railway
- Monitor health check failures

## Project-Specific Notes

**BotFacebook Context:**
- Health endpoints: `/health`, `/api/health`
- Expected response time: < 500ms
- Includes: database, cache status
- Railway healthcheck timeout: 30s
