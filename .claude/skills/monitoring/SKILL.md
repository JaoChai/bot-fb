---
name: monitoring
description: Observability and monitoring specialist for Sentry, Railway logs, and application metrics. Handles error tracking, log analysis, alerting, health checks, and performance monitoring. Use when debugging production issues, setting up alerts, or analyzing application health.
---

# Monitoring & Observability Skill

Production monitoring for BotFacebook.

## Quick Start

1. **Error occurred?** → Check Sentry first
2. **Deployment issue?** → Check Railway logs
3. **Performance problem?** → Check metrics/slow queries
4. **Bot not responding?** → Use `/webhook-debug` instead

## MCP Tools Available

- **sentry**: `search_issues`, `get_issue_details`, `analyze_issue_with_seer` - Error analysis
- **railway**: `get-logs`, `list-deployments` - Deployment logs
- **neon**: `list_slow_queries`, `run_sql` - Database metrics

## Monitoring Stack

```
┌─────────────────────────────────────────────────────────────┐
│                    Monitoring Stack                          │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  Error Tracking          Logs              Database          │
│  ┌──────────┐       ┌──────────┐       ┌──────────┐        │
│  │  Sentry  │       │ Railway  │       │   Neon   │        │
│  │          │       │  Logs    │       │ Metrics  │        │
│  └────┬─────┘       └────┬─────┘       └────┬─────┘        │
│       │                  │                  │               │
│       ▼                  ▼                  ▼               │
│  ┌──────────────────────────────────────────────┐          │
│  │              Analysis Dashboard               │          │
│  └──────────────────────────────────────────────┘          │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

## Error Tracking (Sentry)

### Search for Issues

```bash
# Using MCP Sentry tools
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved errors in production last 24 hours'
)
```

### Get Issue Details

```bash
mcp__sentry__get_issue_details(
  issueUrl='https://sentry.io/organizations/.../issues/...'
)
```

### Analyze with AI

```bash
mcp__sentry__analyze_issue_with_seer(
  issueUrl='https://sentry.io/organizations/.../issues/...'
)
```

### Common Sentry Queries

| What to Find | Query |
|--------------|-------|
| Unresolved errors | `is:unresolved` |
| Last 24 hours | `firstSeen:-24h` |
| Specific user | `user.email:xxx@xxx.com` |
| High frequency | `times_seen:>100` |
| Specific tag | `environment:production` |

## Log Analysis (Railway)

### Get Deployment Logs

```bash
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy'
)
```

### Get Build Logs

```bash
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='build'
)
```

### Filter Logs

```bash
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='@level:error',
  lines=100
)
```

### Common Log Patterns

| Issue | Log Pattern |
|-------|-------------|
| PHP Error | `PHP Fatal error` |
| Memory issue | `Allowed memory size exhausted` |
| Timeout | `Maximum execution time exceeded` |
| Queue failure | `Job failed` |
| Auth failure | `Unauthenticated` |

## Database Metrics (Neon)

### Slow Queries

```bash
mcp__neon__list_slow_queries(
  projectId='your-project-id',
  minExecutionTime=1000  # ms
)
```

### Connection Pool

```sql
-- Check active connections
SELECT count(*) FROM pg_stat_activity;

-- Check connection by state
SELECT state, count(*)
FROM pg_stat_activity
GROUP BY state;
```

### Table Stats

```sql
-- Table sizes
SELECT relname, pg_size_pretty(pg_total_relation_size(relid))
FROM pg_catalog.pg_statio_user_tables
ORDER BY pg_total_relation_size(relid) DESC
LIMIT 10;

-- Row counts
SELECT relname, n_live_tup
FROM pg_stat_user_tables
ORDER BY n_live_tup DESC;
```

## Health Check Endpoints

### Laravel Health Check

```php
// routes/api.php
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'services' => [
            'database' => DB::connection()->getPdo() ? 'ok' : 'error',
            'cache' => Cache::store()->get('health') !== null ? 'ok' : 'error',
            'queue' => Queue::size() < 1000 ? 'ok' : 'warning',
        ],
    ]);
});
```

### Health Check Response

```json
{
  "status": "ok",
  "timestamp": "2026-01-17T10:30:00Z",
  "services": {
    "database": "ok",
    "cache": "ok",
    "queue": "ok"
  }
}
```

## Alerting Patterns

### Sentry Alerts

| Alert Type | Trigger | Action |
|------------|---------|--------|
| New Issue | First occurrence | Slack notification |
| Regression | Issue reappears | Email + Slack |
| High Volume | >100 events/hour | PagerDuty |

### Custom Metrics

```php
// Log custom metric
Log::info('metric.api_response_time', [
    'endpoint' => $request->path(),
    'duration_ms' => $duration,
    'status' => $response->status(),
]);

// Log business metric
Log::info('metric.message_processed', [
    'bot_id' => $bot->id,
    'platform' => $bot->platform,
    'response_time_ms' => $responseTime,
]);
```

## Debug Workflow

### 1. Error Reported

```
User reports: "Bot not responding"
    │
    ▼
┌─────────────────┐
│ Check Sentry    │ ← Start here
│ for errors      │
└────────┬────────┘
         │
    Error found?
    ├── Yes → Analyze with Seer → Fix
    │
    └── No ↓
         │
┌─────────────────┐
│ Check Railway   │
│ deploy logs     │
└────────┬────────┘
         │
    Issue found?
    ├── Yes → Check deploy status → Redeploy
    │
    └── No ↓
         │
┌─────────────────┐
│ Check DB        │
│ slow queries    │
└────────┬────────┘
         │
    Slow query?
    ├── Yes → Optimize query/add index
    │
    └── No → Use /webhook-debug skill
```

### 2. Performance Issue

```
Slow API response
    │
    ▼
┌─────────────────┐
│ Check Sentry    │
│ performance     │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Check slow      │
│ queries (Neon)  │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Use /performance│
│ skill           │
└─────────────────┘
```

## Key Metrics to Track

### Application Metrics

| Metric | Target | Alert Threshold |
|--------|--------|-----------------|
| API Response Time | < 500ms | > 2s |
| Error Rate | < 1% | > 5% |
| Queue Size | < 100 | > 500 |
| Memory Usage | < 256MB | > 400MB |

### Business Metrics

| Metric | Track |
|--------|-------|
| Messages Processed | Count per bot per hour |
| AI Response Time | Average and p95 |
| User Sessions | Active users |
| Bot Conversations | New vs returning |

### Database Metrics

| Metric | Target | Alert Threshold |
|--------|--------|-----------------|
| Query Time | < 100ms | > 500ms |
| Connections | < 50 | > 80 |
| Table Size Growth | Stable | >10%/day |

## Detailed Guides

- **Sentry Setup**: See [SENTRY_GUIDE.md](SENTRY_GUIDE.md)
- **Log Patterns**: See [LOG_PATTERNS.md](LOG_PATTERNS.md)
- **Alerting Setup**: See [ALERTING_GUIDE.md](ALERTING_GUIDE.md)

## Common Tasks

### Investigate Production Error

1. Get Sentry issue URL from alert
2. Use `get_issue_details` to see stacktrace
3. Use `analyze_issue_with_seer` for root cause
4. Check related logs in Railway
5. Fix and deploy

### Set Up New Alert

1. Go to Sentry → Alerts
2. Create new alert rule
3. Set conditions (error type, frequency)
4. Configure notification (Slack, email)
5. Test alert

### Daily Health Check

1. Check Sentry dashboard for new issues
2. Review Railway deployment status
3. Check slow queries in Neon
4. Verify health endpoint responds

## Troubleshooting Commands

```bash
# Check recent errors via Sentry MCP
# search_issues with 'unresolved errors last hour'

# Check deployment via Railway MCP
# get-logs with filter='error'

# Check DB health via Neon MCP
# list_slow_queries

# Manual health check
curl https://api.botjao.com/health
```
