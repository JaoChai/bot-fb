---
id: sentry-003-performance-monitoring
title: Sentry Performance Monitoring
impact: MEDIUM
impactDescription: "Missing performance insights leads to undetected slowdowns"
category: sentry
tags: [sentry, performance, tracing, apm]
relatedRules: [metrics-001-api-response-time, metrics-002-slow-queries]
---

## Symptom

- Users report slow responses
- Don't know which endpoints are slow
- Can't trace requests end-to-end
- Missing performance baseline

## Root Cause

1. Performance monitoring not enabled
2. No transaction tracing
3. Sample rate too low
4. Missing spans for critical operations
5. Not tracking custom metrics

## Diagnosis

### Quick Check

```bash
# Search for performance issues
mcp__sentry__search_events(
  organizationSlug='your-org',
  naturalLanguageQuery='transactions slower than 2 seconds'
)
```

### Detailed Analysis

```bash
# Check specific transaction
mcp__sentry__get_trace_details(
  organizationSlug='your-org',
  traceId='your-trace-id'
)
```

## Solution

### Enable Performance Monitoring

```php
// config/sentry.php
return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE', 0.2),
    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE', 0.1),
];
```

### Search Performance Issues

```bash
# Slow transactions
mcp__sentry__search_events(
  organizationSlug='your-org',
  naturalLanguageQuery='API transactions taking more than 1 second'
)

# Specific endpoint
mcp__sentry__search_events(
  organizationSlug='your-org',
  naturalLanguageQuery='transactions for /api/chat endpoint'
)

# Database queries
mcp__sentry__search_events(
  organizationSlug='your-org',
  naturalLanguageQuery='slow database queries in spans'
)
```

### Add Custom Spans

```php
// Add span for critical operation
$span = \Sentry\startSpan([
    'op' => 'ai.embedding',
    'description' => 'Generate embedding for query',
]);

// Do operation
$embedding = $this->embeddingService->generate($query);

$span->finish();
```

### Track Custom Metrics

```php
// Log performance metric
\Sentry\Metrics::gauge(
    'api.response_time',
    $responseTime,
    MeasurementUnit::millisecond(),
    ['endpoint' => $request->path()]
);
```

### Performance Queries

| Goal | Query |
|------|-------|
| Slow transactions | `transactions slower than 2 seconds` |
| Specific endpoint | `transactions for /api/chat` |
| Error transactions | `transactions with errors` |
| Database spans | `spans with db operations` |
| AI operations | `spans with ai.* operations` |

## Verification

```bash
# Check performance improved
mcp__sentry__search_events(
  organizationSlug='your-org',
  naturalLanguageQuery='average response time for /api/chat last hour'
)
```

## Prevention

- Set up performance baselines
- Alert on p95 latency spikes
- Review slow transactions weekly
- Optimize hot paths
- Add spans to critical operations

## Project-Specific Notes

**BotFacebook Context:**
- Critical transactions: /api/webhook, /api/chat
- Spans to track: AI calls, embedding, search
- Sample rate: 20% for production
- P95 target: < 1.5s for AI responses
