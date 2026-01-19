---
id: railway-001-deploy-failure
title: Railway Deployment Failure
impact: CRITICAL
impactDescription: "Production deployment fails, service unavailable"
category: railway
tags: [railway, deploy, failure, production]
relatedRules: [railway-002-build-error, troubleshoot-001-500-errors]
---

## Symptom

- Deployment fails with error message
- Service becomes unavailable after deploy
- Health check fails post-deployment
- Railway shows "Failed" status

## Root Cause

1. Build phase failure (dependencies, syntax)
2. Health check timeout
3. Port binding issues
4. Process crash on startup
5. Memory/resource exhaustion

## Diagnosis

### Quick Check

```bash
# Check deployment status
railway status

# View deploy logs
railway logs --type deploy --lines 100

# Check for errors
railway logs --filter "error|failed|exception" --lines 50
```

### Detailed Analysis

```bash
# Full deployment history
railway deployments --json --limit 5

# Compare with last successful
railway logs --deployment {last-good-id} --lines 50
railway logs --deployment {failed-id} --lines 50

# Check resource usage
railway metrics
```

## Solution

### Fix Steps

1. **Identify failure phase**
```bash
# Build failure?
railway logs --type build --lines 100

# Deploy failure?
railway logs --type deploy --lines 100
```

2. **Common fixes**
```bash
# Build dependency issue
rm -rf vendor node_modules
composer install
npm install
railway up

# Health check timeout
# Increase timeout in Railway dashboard or fix /health endpoint

# Port issue - ensure app listens on $PORT
# In Laravel, already handled by default
```

3. **Emergency rollback**
```bash
# If production is down, rollback first
railway rollback

# Then investigate
railway logs --deployment {failed-id}
```

### Runbook

```bash
# 1. Check if critical (production down?)
curl -s https://api.botjao.com/health || echo "DOWN - ROLLBACK NOW"

# 2. If down, immediate rollback
railway rollback

# 3. Identify failure point
railway logs --type build --filter "error" --lines 50
railway logs --type deploy --filter "error" --lines 50

# 4. Fix locally
git status  # Check changes
php artisan test  # Run tests
npm run build  # Test frontend build

# 5. Redeploy with fix
git commit -m "fix: deployment issue"
railway up --ci

# 6. Verify
curl -s https://api.botjao.com/health | jq .
railway logs --filter "error" --lines 20
```

## Verification

```bash
# Health check
curl -sf https://api.botjao.com/health && echo "OK" || echo "FAILED"

# Full health response
curl -s https://api.botjao.com/health | jq .

# Check logs for errors
railway logs --filter "error" --lines 20 | grep -c "error" || echo "No errors"

# Verify deployment status
railway status
```

## Prevention

- Always run tests before deploying
- Use CI/CD pipeline with checks
- Set up deploy notifications
- Maintain rollback procedure
- Test health endpoint locally

## Project-Specific Notes

**BotFacebook Context:**
- Health endpoint: `/health` and `/api/health`
- Backend: Laravel 12 on Railway
- Frontend: Separate Vite build
- Rollback: `railway rollback` to previous deployment
