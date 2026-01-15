# Production Troubleshooting Guide

## Quick Diagnosis

### 1. Check Health Status

```bash
# Backend health
curl https://api.botjao.com/health

# Expected response
{"status":"ok","database":"connected","timestamp":"..."}
```

### 2. Check Recent Errors (Sentry)

```bash
# Use Sentry MCP
mcp__sentry__search_issues --organizationSlug=botjao --naturalLanguageQuery="errors in last hour"
```

### 3. Check Logs

```bash
# Railway logs
railway logs --filter "error" --lines 100
```

## Common Issues

### 500 Internal Server Error

**Symptoms:** API returns 500, white screen

**Diagnosis:**
```bash
# Check Laravel logs
railway logs --filter "Exception\|Error" --lines 200
```

**Common Causes:**

| Cause | Fix |
|-------|-----|
| Missing env var | `railway variables` to verify |
| Database connection | Check DATABASE_URL |
| Cache issues | `railway run php artisan optimize:clear` |
| Code error | Check Sentry for stack trace |

### 502 Bad Gateway

**Symptoms:** Nginx returns 502

**Diagnosis:**
```bash
railway logs --type deploy --lines 100
```

**Common Causes:**

| Cause | Fix |
|-------|-----|
| App not starting | Check Procfile |
| Port mismatch | Use $PORT variable |
| Memory exceeded | Check memory usage |
| Slow startup | Increase health check timeout |

### 503 Service Unavailable

**Symptoms:** Service unreachable

**Diagnosis:**
```bash
railway status
```

**Common Causes:**

| Cause | Fix |
|-------|-----|
| Deployment in progress | Wait for completion |
| Health check failing | Check /health endpoint |
| Service crashed | Check logs, restart |

### Database Connection Failed

**Symptoms:** `SQLSTATE[HY000] Connection refused`

**Diagnosis:**
```bash
# Check if DATABASE_URL is set
railway variables | grep DATABASE

# Test connection
railway run php artisan db:monitor
```

**Fixes:**
1. Verify DATABASE_URL format
2. Check Neon dashboard for status
3. Verify IP whitelist (if applicable)
4. Check SSL mode (`?sslmode=require`)

### Queue Not Processing

**Symptoms:** Jobs stuck in queue

**Diagnosis:**
```bash
# Check failed jobs
railway run php artisan queue:failed

# Check queue size
railway run php artisan queue:monitor
```

**Fixes:**
1. Restart queue worker: `railway run php artisan queue:restart`
2. Retry failed jobs: `railway run php artisan queue:retry all`
3. Check QUEUE_CONNECTION env var
4. Verify Redis/database connection

### WebSocket Not Connecting

**Symptoms:** Real-time features not working

**Diagnosis:**
```javascript
// Browser console
Echo.connector.pusher.connection.state
```

**Fixes:**
1. Check REVERB_* env vars
2. Verify SSL certificate
3. Check CORS settings
4. Verify Reverb service is running

### Slow API Response

**Symptoms:** Requests taking >1s

**Diagnosis:**
```bash
# Check slow queries
railway logs --filter "slow query" --lines 50

# Check Sentry performance
mcp__sentry__search_events --naturalLanguageQuery="slow API calls"
```

**Fixes:**
1. Add database indexes
2. Enable query caching
3. Optimize N+1 queries
4. Check external API calls

## Emergency Procedures

### Rollback Deployment

```bash
# Quick rollback
railway rollback

# Specific version
railway rollback --deployment-id <id>
```

### Clear All Caches

```bash
railway run php artisan optimize:clear
railway run php artisan config:clear
railway run php artisan cache:clear
railway run php artisan view:clear
railway run php artisan route:clear
```

### Database Emergency

```bash
# Check connection
railway run php artisan db:monitor

# Rollback migration
railway run php artisan migrate:rollback --step=1

# Run specific migration
railway run php artisan migrate --path=/database/migrations/xxx
```

### Restart Services

```bash
# Redeploy triggers restart
railway up --detach

# Or via dashboard: Restart button
```

## Monitoring Checklist

### Daily

- [ ] Check Sentry for new errors
- [ ] Monitor API response times
- [ ] Check queue health

### Weekly

- [ ] Review error trends
- [ ] Check database performance
- [ ] Verify backups

### After Deploy

- [ ] Monitor logs for 15 minutes
- [ ] Check Sentry for new issues
- [ ] Verify critical flows work

## Debug Commands

```bash
# Laravel
railway run php artisan tinker
railway run php artisan route:list
railway run php artisan config:show database
railway run php artisan about

# Database
railway run php artisan db:show
railway run php artisan migrate:status

# Queue
railway run php artisan queue:work --once
railway run php artisan queue:failed
railway run php artisan queue:flush

# Cache
railway run php artisan cache:clear
railway run php artisan config:cache
```

## Log Patterns to Watch

```bash
# Critical errors
railway logs --filter "CRITICAL\|EMERGENCY"

# Authentication issues
railway logs --filter "Unauthorized\|401\|403"

# Database issues
railway logs --filter "SQLSTATE\|Connection refused"

# Memory issues
railway logs --filter "Allowed memory size"

# Queue issues
railway logs --filter "Job failed\|MaxAttemptsExceededException"
```

## Contact Points

| Issue Type | First Contact |
|------------|--------------|
| Code bugs | Sentry issue details |
| Infrastructure | Railway status page |
| Database | Neon dashboard |
| DNS/Domain | Cloudflare dashboard |
| External APIs | Provider status pages |
