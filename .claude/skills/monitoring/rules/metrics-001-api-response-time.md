---
id: metrics-001-api-response-time
title: API Response Time Monitoring
impact: HIGH
impactDescription: "Slow APIs cause poor user experience and bot timeouts"
category: metrics
tags: [metrics, performance, api, latency]
relatedRules: [metrics-002-slow-queries, sentry-003-performance-monitoring]
---

## Symptom

- API responses feel slow
- Users complaining about delays
- Bot responses timing out
- P95 latency increasing

## Root Cause

1. No response time tracking
2. Slow database queries
3. External API delays
4. Inefficient code
5. Missing caching

## Diagnosis

### Quick Check

```php
// Add timing to requests
// In middleware
$start = microtime(true);
$response = $next($request);
$duration = (microtime(true) - $start) * 1000;
Log::info('api.response_time', [
    'endpoint' => $request->path(),
    'method' => $request->method(),
    'duration_ms' => $duration,
]);
```

### Detailed Analysis

```bash
# Check Sentry performance
mcp__sentry__search_events(
  organizationSlug='your-org',
  naturalLanguageQuery='average response time for API endpoints'
)

# Check slow endpoints
mcp__sentry__search_events(
  organizationSlug='your-org',
  naturalLanguageQuery='transactions slower than 1 second'
)
```

## Solution

### Track Response Times in Laravel

```php
// app/Http/Middleware/LogRequestTime.php
class LogRequestTime
{
    public function handle($request, Closure $next)
    {
        $start = microtime(true);
        $response = $next($request);
        $duration = (microtime(true) - $start) * 1000;

        Log::info('api.response', [
            'path' => $request->path(),
            'method' => $request->method(),
            'status' => $response->status(),
            'duration_ms' => round($duration, 2),
        ]);

        // Warn on slow requests
        if ($duration > 1000) {
            Log::warning('api.slow_response', [
                'path' => $request->path(),
                'duration_ms' => round($duration, 2),
            ]);
        }

        return $response;
    }
}
```

### Use Sentry Performance

```php
// Automatic transaction tracking
// config/sentry.php
return [
    'traces_sample_rate' => 0.2, // 20% of requests
];

// Custom spans for detailed tracking
$span = \Sentry\startSpan([
    'op' => 'http.request',
    'description' => 'External API call',
]);
// ... operation
$span->finish();
```

### Query Performance Data

```bash
# Average response time
mcp__sentry__search_events(
  organizationSlug='your-org',
  naturalLanguageQuery='average transaction duration last hour'
)

# P95 latency
mcp__sentry__search_events(
  organizationSlug='your-org',
  naturalLanguageQuery='p95 response time for /api/chat'
)

# Slowest endpoints
mcp__sentry__search_events(
  organizationSlug='your-org',
  naturalLanguageQuery='transactions sorted by duration'
)
```

### Response Time Targets

| Endpoint Type | Target | Alert |
|---------------|--------|-------|
| Simple API | < 100ms | > 500ms |
| Database query | < 50ms | > 200ms |
| AI response | < 2s | > 5s |
| Webhook | < 1s | > 3s |
| Health check | < 50ms | > 200ms |

### Log Slow Requests

```bash
# Search Railway logs for slow requests
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='slow_response'
)
```

## Verification

```bash
# Check current response times
curl -w "Time: %{time_total}s\n" -o /dev/null -s https://api.botjao.com/health

# Check Sentry metrics
mcp__sentry__search_events(
  organizationSlug='your-org',
  naturalLanguageQuery='transaction p50 p95 response time'
)
```

## Prevention

- Set response time budgets
- Alert on P95 degradation
- Profile slow endpoints
- Optimize database queries
- Cache frequently accessed data

## Project-Specific Notes

**BotFacebook Context:**
- Target: API < 500ms, AI < 2s
- Critical endpoints: /api/webhook, /api/chat
- Sentry: 20% sample rate
- Log slow requests > 1s
