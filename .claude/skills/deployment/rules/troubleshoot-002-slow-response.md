---
id: troubleshoot-002-slow-response
title: Debugging Slow Response Times
impact: HIGH
impactDescription: "API responses taking too long, poor user experience"
category: troubleshoot
tags: [performance, slow, latency, debugging]
relatedRules: [railway-006-scaling, troubleshoot-003-connection-issues]
---

## Symptom

- API responses taking > 2 seconds
- Timeouts from clients
- Users complaining about slowness
- Performance degradation alerts

## Root Cause

1. Slow database queries (N+1)
2. External API calls blocking
3. Memory issues causing swapping
4. Queue backlog
5. Unoptimized code paths

## Diagnosis

### Quick Check

```bash
# Measure response time
curl -w "Time: %{time_total}s\n" -o /dev/null -s https://api.botjao.com/api/slow-endpoint

# Check for slow query logs
railway logs --filter "slow\|query\|seconds" --lines 50

# Check resource usage
railway metrics
```

### Detailed Analysis

```bash
# Profile specific endpoint
ab -n 10 -c 2 https://api.botjao.com/api/endpoint

# Check database query time
railway exec "php artisan tinker --execute=\"
DB::listen(function(\$query) {
    if (\$query->time > 100) {
        Log::warning('Slow query', ['sql' => \$query->sql, 'time' => \$query->time]);
    }
});
\""

# Check queue processing time
railway logs --filter "job\|processed\|seconds" --lines 50
```

## Solution

### Fix Steps

1. **Enable query logging**
```php
// Temporary: Add to AppServiceProvider boot()
if (config('app.debug')) {
    DB::listen(function ($query) {
        if ($query->time > 100) { // > 100ms
            Log::warning('Slow query', [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time' => $query->time,
            ]);
        }
    });
}
```

2. **Fix N+1 queries**
```php
// Before (N+1)
$posts = Post::all();
foreach ($posts as $post) {
    echo $post->author->name;  // Query for each post
}

// After (eager loading)
$posts = Post::with('author')->get();
foreach ($posts as $post) {
    echo $post->author->name;  // No additional queries
}
```

3. **Optimize external API calls**
```php
// Before (blocking)
$result1 = Http::get('api1');
$result2 = Http::get('api2');

// After (parallel)
$responses = Http::pool(fn ($pool) => [
    $pool->get('api1'),
    $pool->get('api2'),
]);
```

4. **Add caching**
```php
// Cache expensive operations
$result = Cache::remember('expensive-key', 3600, function () {
    return $this->expensiveOperation();
});
```

5. **Queue slow operations**
```php
// Before (synchronous)
$this->sendNotification($user);
$this->updateAnalytics();

// After (queued)
SendNotification::dispatch($user);
UpdateAnalytics::dispatch();
```

### Performance Monitoring Code

```php
// Add timing middleware
class MeasureResponseTime
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        $duration = (microtime(true) - $start) * 1000;

        if ($duration > 1000) { // > 1 second
            Log::warning('Slow response', [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'duration_ms' => round($duration, 2),
            ]);
        }

        $response->headers->set('X-Response-Time', round($duration, 2) . 'ms');

        return $response;
    }
}
```

### Common Slow Patterns

| Pattern | Detection | Fix |
|---------|-----------|-----|
| N+1 queries | Multiple similar queries | Eager load with `with()` |
| Missing index | EXPLAIN shows seq scan | Add database index |
| External API | Long wait in logs | Cache or queue |
| Large payload | Response size > 1MB | Paginate or limit |
| Memory issues | Swap usage high | Optimize memory or scale |

## Verification

```bash
# Measure improvement
curl -w "Time: %{time_total}s\n" -o /dev/null -s https://api.botjao.com/api/endpoint
# Should be < 500ms

# Check no slow query warnings
railway logs --filter "slow query" --lines 20 --since "10 minutes ago"
# Should be empty

# Load test
ab -n 100 -c 10 https://api.botjao.com/api/endpoint
# Check average and p95 times
```

## Prevention

- Use query profiling in development
- Set up latency monitoring alerts
- Regular performance testing
- Review N+1 queries in code review
- Cache expensive operations

## Project-Specific Notes

**BotFacebook Context:**
- Target response time: < 500ms
- AI operations may be slower (acceptable)
- Use caching for repeated queries
- Queue heavy operations
