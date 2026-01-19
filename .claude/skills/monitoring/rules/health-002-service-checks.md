---
id: health-002-service-checks
title: Service Dependency Checks
impact: HIGH
impactDescription: "Not checking dependencies misses cascading failures"
category: health
tags: [health, dependencies, database, redis]
relatedRules: [health-001-endpoint-setup, metrics-003-resource-usage]
---

## Symptom

- App "healthy" but not working
- External service down unnoticed
- Database issues not detected
- Cache failures hidden

## Root Cause

1. Not checking all dependencies
2. Checks too slow/expensive
3. Not handling timeouts
4. Masking partial failures
5. No degraded state handling

## Diagnosis

### Quick Check

```bash
# Verify each service
curl https://api.botjao.com/health

# Check specific services
mcp__neon__run_sql(
  projectId='your-project-id',
  sql='SELECT 1'
)
```

## Solution

### Database Check

```php
private function checkDatabase(): array
{
    try {
        $start = microtime(true);
        DB::connection()->getPdo();
        $latency = (microtime(true) - $start) * 1000;

        return [
            'status' => $latency < 100 ? 'ok' : 'slow',
            'latency_ms' => round($latency, 2),
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'error' => 'Connection failed',
        ];
    }
}
```

### Cache Check

```php
private function checkCache(): array
{
    try {
        $start = microtime(true);
        $key = 'health_check_' . time();
        Cache::put($key, true, 10);
        $result = Cache::get($key);
        Cache::forget($key);
        $latency = (microtime(true) - $start) * 1000;

        return [
            'status' => $result ? 'ok' : 'error',
            'latency_ms' => round($latency, 2),
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'error' => 'Cache unavailable',
        ];
    }
}
```

### Queue Check

```php
private function checkQueue(): array
{
    try {
        $size = Queue::size('default');
        $status = match (true) {
            $size >= 1000 => 'critical',
            $size >= 500 => 'warning',
            $size >= 100 => 'elevated',
            default => 'ok',
        };

        return [
            'status' => $status,
            'size' => $size,
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'error' => 'Queue unavailable',
        ];
    }
}
```

### External API Check

```php
private function checkExternalAPI(): array
{
    try {
        $start = microtime(true);
        $response = Http::timeout(5)->get('https://api.external.com/health');
        $latency = (microtime(true) - $start) * 1000;

        return [
            'status' => $response->ok() ? 'ok' : 'error',
            'latency_ms' => round($latency, 2),
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'error' => 'Timeout or unreachable',
        ];
    }
}
```

### Comprehensive Health Checker

```php
class ServiceHealthChecker
{
    private array $results = [];

    public function check(): array
    {
        // Run checks in parallel where possible
        $this->results['database'] = $this->checkDatabase();
        $this->results['cache'] = $this->checkCache();
        $this->results['queue'] = $this->checkQueue();

        // Optional: Check less critical services
        // $this->results['openrouter'] = $this->checkOpenRouter();

        return [
            'status' => $this->overallStatus(),
            'checks' => $this->results,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function overallStatus(): string
    {
        $hasError = false;
        $hasWarning = false;

        foreach ($this->results as $check) {
            $status = is_array($check) ? $check['status'] : $check;
            if ($status === 'error' || $status === 'critical') {
                $hasError = true;
            }
            if ($status === 'warning' || $status === 'slow') {
                $hasWarning = true;
            }
        }

        if ($hasError) return 'error';
        if ($hasWarning) return 'degraded';
        return 'ok';
    }
}
```

### Dependency Check Matrix

| Service | Check Method | Timeout | Required |
|---------|--------------|---------|----------|
| Database | SELECT 1 | 5s | Yes |
| Cache | Get/Set | 2s | No |
| Queue | Size check | 1s | No |
| External API | Health endpoint | 5s | No |

### Timeout Configuration

```php
// Set appropriate timeouts
private function checkWithTimeout(callable $check, int $timeout = 5): array
{
    try {
        $result = null;
        $start = microtime(true);

        // Wrap in timeout
        $result = rescue(function () use ($check, $timeout) {
            return $check();
        }, [
            'status' => 'timeout',
            'error' => "Check exceeded {$timeout}s",
        ], false);

        $elapsed = microtime(true) - $start;
        if ($elapsed > $timeout) {
            return [
                'status' => 'timeout',
                'error' => "Check exceeded {$timeout}s",
            ];
        }

        return $result;
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'error' => $e->getMessage(),
        ];
    }
}
```

## Verification

```bash
# Full health check
curl https://api.botjao.com/health | jq

# Should show all services
{
  "status": "ok",
  "checks": {
    "database": {"status": "ok", "latency_ms": 5.2},
    "cache": {"status": "ok", "latency_ms": 1.1},
    "queue": {"status": "ok", "size": 3}
  }
}
```

## Prevention

- Check all critical dependencies
- Set appropriate timeouts
- Use degraded states appropriately
- Log health check failures
- Alert on degraded status

## Project-Specific Notes

**BotFacebook Context:**
- Required: Database (Neon), Cache (Redis)
- Optional: OpenRouter API check
- Timeouts: 5s max per check
- Response target: < 100ms total
