---
id: alerts-002-performance-alerts
title: Performance Alert Configuration
impact: HIGH
impactDescription: "Undetected performance degradation hurts user experience"
category: alerts
tags: [alerts, performance, latency, thresholds]
relatedRules: [metrics-001-api-response-time, alerts-001-error-alerts]
---

## Symptom

- Slow APIs not detected
- Users complaining before you notice
- Performance degrades gradually
- No baseline for comparison

## Root Cause

1. No performance monitoring
2. Alerts not configured
3. Wrong thresholds
4. Missing baseline metrics
5. Not tracking the right endpoints

## Diagnosis

### Quick Check

```bash
# Check current performance
mcp__sentry__search_events(
  organizationSlug='your-org',
  naturalLanguageQuery='average transaction duration last hour'
)

# Check slow queries
mcp__neon__list_slow_queries(
  projectId='your-project-id',
  minExecutionTime=500
)
```

## Solution

### Configure Sentry Performance Alerts

```yaml
# Sentry Alert Examples

# P95 Latency Alert
Condition: Transaction duration p95 > 2s
Environment: production
Actions: Slack notification
Frequency: Every 30 minutes

# Apdex Alert
Condition: Apdex score < 0.7
Environment: production
Actions: Slack + Email
Frequency: Every hour
```

### Key Performance Metrics to Alert On

| Metric | Good | Alert Threshold |
|--------|------|-----------------|
| P50 latency | < 200ms | > 500ms |
| P95 latency | < 1s | > 3s |
| Apdex | > 0.9 | < 0.7 |
| Error rate | < 1% | > 5% |
| Throughput | Stable | -50% drop |

### Critical Endpoint Alerts

```yaml
# Webhook performance
Transaction: /api/webhook/*
P95 threshold: > 2s
Alert: P1 - Slack immediately

# Chat API
Transaction: /api/chat
P95 threshold: > 3s
Alert: P2 - Slack

# Search
Transaction: /api/search
P95 threshold: > 1s
Alert: P2 - Slack
```

### Database Performance Alerts

```php
// Custom alert for slow queries
DB::listen(function ($query) {
    if ($query->time > 500) {
        Log::alert('db.slow_query', [
            'sql' => Str::limit($query->sql, 200),
            'time_ms' => $query->time,
        ]);
        // This can trigger log-based alerts
    }
});
```

### Queue Performance Alerts

```php
// Alert on queue backlog
$queueSize = Queue::size('default');
if ($queueSize > 100) {
    Log::alert('queue.backlog', [
        'size' => $queueSize,
        'threshold' => 100,
    ]);
}

// Alert on job duration
class MonitoredJob implements ShouldQueue
{
    public function handle()
    {
        $start = microtime(true);
        // ... job logic
        $duration = (microtime(true) - $start) * 1000;

        if ($duration > 5000) {
            Log::alert('job.slow', [
                'job' => static::class,
                'duration_ms' => $duration,
            ]);
        }
    }
}
```

### Alert Response Playbook

| Alert | First Check | Action |
|-------|------------|--------|
| P95 spike | Recent deploy? | Consider rollback |
| Apdex drop | Check slow queries | Optimize or cache |
| Throughput drop | Check errors | Fix blocking issue |
| Queue backlog | Worker status | Scale workers |
| DB latency | Active queries | Kill long queries |

## Verification

```bash
# Verify alerts are working
# Check Sentry alert history

# Manual performance check
mcp__sentry__search_events(
  organizationSlug='your-org',
  naturalLanguageQuery='p50 p95 latency for /api/chat'
)
```

## Prevention

- Set up performance baselines
- Alert on gradual degradation
- Review performance weekly
- Load test before releases
- Optimize proactively

## Project-Specific Notes

**BotFacebook Context:**
- Critical: webhook P95 < 2s
- AI responses: P95 < 5s (acceptable)
- DB queries: > 500ms is alertable
- Queue: backlog > 100 needs attention
