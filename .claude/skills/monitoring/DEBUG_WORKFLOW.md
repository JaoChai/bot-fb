# Debug Workflows

## 1. Error Reported

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

## 2. Performance Issue

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
