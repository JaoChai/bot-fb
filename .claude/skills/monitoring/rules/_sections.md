# Decision Trees & Quick Reference

## Decision Tree: Error Investigation

```
Error reported
│
├─ Have Sentry URL? ─── Yes ──> get_issue_details
│         │
│        No
│         │
├─ Know error type? ─── Yes ──> search_issues with query
│         │
│        No
│         │
└─ Start with: search_issues "unresolved errors last hour"
         │
         ▼
Got issue details?
│
├─ Yes ──> Need root cause? ──> analyze_issue_with_seer
│                │
│               No
│                │
│         └──> Check stacktrace ──> Fix code
│
└─ No ──> Check Railway logs with filter='error'
```

## Decision Tree: Log Analysis

```
Issue type?
│
├─ Deployment failure ──> get-logs type='build'
│
├─ Runtime error ──> get-logs type='deploy' filter='error'
│
├─ Performance issue ──> list_slow_queries (Neon)
│
├─ Auth/webhook issue ──> Use /webhook-debug skill
│
└─ Unknown ──> get-logs lines=200 (recent logs)
```

## Decision Tree: Alert Response

```
Alert received
│
├─ Error alert (Sentry) ──> get_issue_details ──> analyze_issue_with_seer
│
├─ Deploy alert (Railway) ──> list-deployments ──> get-logs type='build'
│
├─ Performance alert ──> list_slow_queries ──> EXPLAIN ANALYZE
│
└─ Health check fail ──> curl /health ──> Check services
```

## Quick Reference: Sentry Queries

| Goal | Query |
|------|-------|
| Unresolved issues | `is:unresolved` |
| Last 24 hours | `firstSeen:-24h` |
| Specific user | `user.email:xxx@xxx.com` |
| High frequency | `times_seen:>100` |
| Production only | `environment:production` |
| Specific endpoint | `transaction:/api/chat` |
| Error type | `error.type:TypeError` |

## Quick Reference: Railway Log Filters

| Goal | Filter |
|------|--------|
| Errors only | `@level:error` |
| Warnings | `@level:warn` |
| Specific text | `your search text` |
| Combined | `@level:error AND timeout` |
| Status code | `@status:500` |

## Quick Reference: Key Metrics

### Application Metrics

| Metric | Target | Alert |
|--------|--------|-------|
| API Response | < 500ms | > 2s |
| Error Rate | < 1% | > 5% |
| Queue Size | < 100 | > 500 |
| Memory | < 256MB | > 400MB |

### Database Metrics

| Metric | Target | Alert |
|--------|--------|-------|
| Query Time | < 100ms | > 500ms |
| Connections | < 50 | > 80 |
| Table Growth | Stable | >10%/day |

## Runbook: Production Error Response

```
1. Acknowledge alert
2. Check Sentry for error details
3. Assess severity (CRITICAL/HIGH/MEDIUM/LOW)
4. If CRITICAL:
   - Notify team immediately
   - Consider rollback if recent deploy
5. Analyze root cause with Seer
6. Implement fix
7. Deploy and verify
8. Update post-mortem if needed
```

## Runbook: Health Check Failure

```
1. Check which service is failing
2. For database:
   - Check Neon dashboard
   - Verify connection string
   - Check connection pool
3. For cache:
   - Check Redis connection
   - Verify cache config
4. For queue:
   - Check queue size
   - Check worker status
5. Restart if needed
6. Monitor recovery
```

## Common MCP Commands

### Sentry Commands

```bash
# Search issues
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved errors in production last 24 hours'
)

# Get issue details
mcp__sentry__get_issue_details(
  issueUrl='https://sentry.io/organizations/.../issues/...'
)

# Analyze with AI
mcp__sentry__analyze_issue_with_seer(
  issueUrl='https://sentry.io/organizations/.../issues/...'
)
```

### Railway Commands

```bash
# Get deployment logs
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='@level:error',
  lines=100
)

# List deployments
mcp__railway__list-deployments(
  workspacePath='/path/to/project',
  json=true
)
```

### Neon Commands

```bash
# List slow queries
mcp__neon__list_slow_queries(
  projectId='your-project-id',
  minExecutionTime=1000
)

# Run diagnostic query
mcp__neon__run_sql(
  projectId='your-project-id',
  sql='SELECT count(*) FROM pg_stat_activity'
)
```
