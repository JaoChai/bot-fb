---
name: monitoring
description: |
  Observability and monitoring for Sentry, Railway logs, Neon metrics.
  Triggers: 'error tracking', 'logs', 'monitoring', 'alerts', 'health check', 'production issue'.
  Use when: debugging production issues, setting up alerts, analyzing application health.
allowed-tools:
  - Bash(curl*)
  - Read
  - Grep
context:
  - path: config/sentry.php
  - path: config/logging.php
  - path: app/Exceptions/Handler.php
---

# Monitoring & Observability

Production monitoring for BotFacebook.

## Quick Start

1. **Error occurred?** → Check Sentry first
2. **Deployment issue?** → Check Railway logs
3. **Performance problem?** → Check metrics/slow queries
4. **Bot not responding?** → Use `/webhook-debug` instead

## MCP Tools Available

| Tool | Commands | Use For |
|------|----------|---------|
| **sentry** | `search_issues`, `get_issue_details`, `analyze_issue_with_seer` | Error analysis |
| **railway** | `get-logs`, `list-deployments` | Deployment logs |
| **neon** | `list_slow_queries`, `run_sql` | Database metrics |

## Error Tracking (Sentry)

### Search for Issues

```python
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved errors in production last 24 hours'
)
```

### Common Sentry Queries

| What to Find | Query |
|--------------|-------|
| Unresolved errors | `is:unresolved` |
| Last 24 hours | `firstSeen:-24h` |
| Specific user | `user.email:xxx@xxx.com` |
| High frequency | `times_seen:>100` |
| Production only | `environment:production` |

## Log Analysis (Railway)

### Get Logs

```python
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

```python
mcp__neon__list_slow_queries(
  projectId='your-project-id',
  minExecutionTime=1000  # ms
)
```

## Key Metrics

| Metric | Target | Alert |
|--------|--------|-------|
| API Response | < 500ms | > 2s |
| Error Rate | < 1% | > 5% |
| Queue Size | < 100 | > 500 |
| DB Query | < 100ms | > 500ms |

## Key Files

| File | Purpose |
|------|---------|
| `config/sentry.php` | Sentry configuration |
| `config/logging.php` | Log channels |
| `app/Exceptions/Handler.php` | Exception handling |

## Detailed Guides

- **Debug Workflows**: See [DEBUG_WORKFLOW.md](DEBUG_WORKFLOW.md)
- **SQL Queries**: See [SQL_QUERIES.md](SQL_QUERIES.md)
- **Metrics Reference**: See [METRICS.md](METRICS.md)
- **Sentry Setup**: See [SENTRY_GUIDE.md](SENTRY_GUIDE.md)

## Troubleshooting

```bash
# Check health endpoint
curl https://api.botjao.com/health

# Use MCP tools for detailed analysis
# sentry: search_issues → get_issue_details → analyze_issue_with_seer
# railway: get-logs with filter='error'
# neon: list_slow_queries
```
