---
id: health-002-component-checks
title: Component Health Checks
impact: HIGH
impactDescription: "Service running but components failing silently"
category: health
tags: [health, database, cache, queue, components]
relatedRules: [health-001-endpoint-config, troubleshoot-003-connection-issues]
---

## Symptom

- Health endpoint returns OK but features broken
- Database queries failing
- Cache not working
- Queue jobs not processing
- WebSocket connections dropping

## Root Cause

1. Database connection lost
2. Redis/cache unreachable
3. Queue worker crashed
4. WebSocket server down
5. External service unavailable

## Diagnosis

### Quick Check

```bash
# Full health check
curl -s https://api.botjao.com/api/health | jq .

# Check individual components via logs
railway logs --filter "database|cache|queue|redis" --lines 50
```

### Detailed Analysis

```bash
# Database check
railway exec "php artisan tinker --execute=\"DB::connection()->getPdo(); echo 'OK';\""

# Cache check
railway exec "php artisan tinker --execute=\"Cache::put('test', 'value', 60); echo Cache::get('test');\""

# Queue check
railway exec "php artisan queue:work --once --tries=1"

# WebSocket check
wscat -c wss://reverb.botjao.com/app/xxx
```

## Solution

### Fix Steps

1. **Implement component checks**
```php
// app/Services/HealthCheckService.php
class HealthCheckService
{
    public function check(): array
    {
        return [
            'status' => $this->overallStatus(),
            'components' => [
                'database' => $this->checkDatabase(),
                'cache' => $this->checkCache(),
                'queue' => $this->checkQueue(),
                'storage' => $this->checkStorage(),
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latency = (microtime(true) - $start) * 1000;

            return [
                'status' => 'healthy',
                'latency_ms' => round($latency, 2),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health-check-' . Str::random(8);
            Cache::put($key, 'test', 10);
            $value = Cache::get($key);
            Cache::forget($key);

            return [
                'status' => $value === 'test' ? 'healthy' : 'unhealthy',
                'driver' => config('cache.default'),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkQueue(): array
    {
        try {
            $connection = config('queue.default');
            $size = Queue::size();

            return [
                'status' => 'healthy',
                'connection' => $connection,
                'pending_jobs' => $size,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkStorage(): array
    {
        try {
            $disk = Storage::disk('local');
            $disk->put('health-check.txt', 'test');
            $disk->delete('health-check.txt');

            return ['status' => 'healthy'];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function overallStatus(): string
    {
        $components = [
            $this->checkDatabase(),
            $this->checkCache(),
            $this->checkQueue(),
        ];

        $unhealthy = collect($components)
            ->filter(fn($c) => $c['status'] === 'unhealthy')
            ->count();

        if ($unhealthy === 0) return 'healthy';
        if ($unhealthy < count($components)) return 'degraded';
        return 'unhealthy';
    }
}
```

2. **Add detailed health endpoint**
```php
// routes/api.php
Route::get('/health/detailed', function (HealthCheckService $service) {
    $health = $service->check();
    $statusCode = match($health['status']) {
        'healthy' => 200,
        'degraded' => 200,  // Still operational
        'unhealthy' => 503,
    };

    return response()->json($health, $statusCode);
});
```

3. **Fix specific components**
```bash
# Database connection issue
railway variables | grep DATABASE_URL
# Verify connection string is correct

# Cache issue
railway variables set CACHE_DRIVER=database
# Or fix Redis connection

# Queue issue
railway exec "php artisan queue:restart"
```

## Verification

```bash
# Check all components
curl -s https://api.botjao.com/api/health/detailed | jq .

# Verify each component healthy
curl -s https://api.botjao.com/api/health/detailed | jq '.components | to_entries[] | select(.value.status != "healthy")'
# Should return empty

# Monitor over time
watch -n 10 'curl -s https://api.botjao.com/api/health/detailed | jq .status'
```

## Prevention

- Implement comprehensive health checks
- Monitor all critical components
- Set up alerts for degraded status
- Regular health check testing
- Document component dependencies

## Project-Specific Notes

**BotFacebook Context:**
- Critical components: Database, Cache, Queue
- Database: Neon PostgreSQL
- Cache: Database driver (or Redis)
- Queue: Database driver
- Detailed health: `/api/health/detailed`
