---
id: rollback-001-procedure
title: Emergency Rollback Procedure
impact: CRITICAL
impactDescription: "Production down, need to restore previous version"
category: rollback
tags: [rollback, emergency, production, recovery]
relatedRules: [railway-001-deploy-failure, rollback-002-database-migration]
---

## Symptom

- Production is down or severely degraded
- New deployment caused critical issues
- Need to restore service immediately
- Users reporting widespread failures

## Root Cause

1. Bug in new deployment
2. Breaking configuration change
3. Incompatible dependency update
4. Database migration failure
5. External service integration broken

## Diagnosis

### Quick Check

```bash
# Check current status
curl -sf https://api.botjao.com/health && echo "UP" || echo "DOWN"

# Check recent deployments
railway deployments --limit 5

# Check for errors
railway logs --filter "error|exception|fatal" --lines 100
```

### Detailed Analysis

```bash
# Compare deployment timestamps with issue start
railway deployments --json --limit 10 | jq '.[] | {id, status, createdAt}'

# Check what changed
git log --oneline HEAD~5..HEAD

# Review specific deployment logs
railway logs --deployment {deployment-id} --lines 100
```

## Solution

### Emergency Rollback Steps

```bash
# STEP 1: Confirm current state is bad
curl -sf https://api.botjao.com/health || echo "CONFIRMED DOWN"

# STEP 2: List recent deployments
railway deployments --limit 5
# Identify last known good deployment ID

# STEP 3: Rollback immediately
railway rollback
# Or to specific deployment:
railway rollback --to {good-deployment-id}

# STEP 4: Verify recovery
curl -sf https://api.botjao.com/health && echo "RECOVERED" || echo "STILL DOWN"

# STEP 5: Check logs
railway logs --filter "error" --lines 20
```

### Post-Rollback Steps

```bash
# 1. Verify service is stable
for i in {1..5}; do
    curl -sf https://api.botjao.com/health
    sleep 10
done

# 2. Notify team
# - Send message to team channel
# - Create incident ticket

# 3. Investigate cause
railway logs --deployment {failed-deployment-id} --lines 200

# 4. Plan hotfix
# - Identify root cause
# - Create fix branch
# - Test thoroughly before redeploy
```

### Runbook: Complete Rollback Procedure

```bash
#!/bin/bash
# Emergency Rollback Runbook

echo "=== Emergency Rollback Procedure ==="
echo "Time: $(date)"

# Step 1: Check current status
echo "1. Checking current health..."
if curl -sf https://api.botjao.com/health > /dev/null; then
    echo "   Service is UP - Are you sure you need to rollback? (Ctrl+C to cancel)"
    sleep 5
fi

# Step 2: Get deployment list
echo "2. Recent deployments:"
railway deployments --limit 5

# Step 3: Rollback
echo "3. Initiating rollback..."
railway rollback

# Step 4: Wait for deployment
echo "4. Waiting for rollback deployment..."
sleep 30

# Step 5: Verify
echo "5. Verifying health..."
for i in {1..3}; do
    if curl -sf https://api.botjao.com/health > /dev/null; then
        echo "   Health check $i: OK"
    else
        echo "   Health check $i: FAILED"
    fi
    sleep 5
done

# Step 6: Check for errors
echo "6. Checking for errors..."
railway logs --filter "error" --lines 10

echo "=== Rollback Complete ==="
echo "Next steps:"
echo "1. Notify team of rollback"
echo "2. Create incident ticket"
echo "3. Investigate root cause"
echo "4. Plan and test hotfix"
```

## Verification

```bash
# Verify service recovered
curl -s https://api.botjao.com/health | jq .
# Should return status: ok

# Verify no new errors
railway logs --filter "error" --lines 50 --since "5 minutes ago"
# Should be minimal/no errors

# Verify key functionality
curl -s https://api.botjao.com/api/test-endpoint
# Should work correctly

# Monitor for 15 minutes
watch -n 30 'curl -s https://api.botjao.com/health | jq .status'
```

## Prevention

- Test deployments in staging first
- Use feature flags for risky changes
- Monitor closely after each deploy
- Maintain rollback procedure documentation
- Practice rollback periodically

## Project-Specific Notes

**BotFacebook Context:**
- Rollback: `railway rollback` to previous
- Recovery time: ~1-2 minutes
- Post-rollback: Clear caches if needed
- Notification: Team Slack channel
