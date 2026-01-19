---
id: logs-002-deploy-logs
title: Analyzing Deployment Logs
impact: HIGH
impactDescription: "Can't diagnose deployment failures without proper log analysis"
category: logs
tags: [logs, railway, deployment, debug]
relatedRules: [logs-003-build-logs, sentry-004-release-tracking]
---

## Symptom

- Deployment succeeded but app not working
- Errors after new deploy
- Need to correlate deploy with issues
- App behaving differently than expected

## Root Cause

1. Configuration issue in deploy
2. Environment variable missing
3. Migration failed
4. Service not starting properly
5. Health check failing

## Diagnosis

### Quick Check

```bash
# Get recent deploy logs
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  lines=100
)
```

### Detailed Analysis

```bash
# Check for errors
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='@level:error',
  lines=200
)

# Check startup messages
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='Starting'
)
```

## Solution

### Check Deployment Status

```bash
# List recent deployments
mcp__railway__list-deployments(
  workspacePath='/path/to/project',
  json=true
)
```

### Get Deploy Logs

```bash
# All deploy logs
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  lines=200
)

# Specific deployment ID
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  deploymentId='deployment-id'
)
```

### Common Deploy Issues

**Configuration Errors:**
```bash
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='config AND error'
)
```

**Environment Variables:**
```bash
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='undefined OR missing'
)
```

**Migration Issues:**
```bash
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='migration'
)
```

**Health Check:**
```bash
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='health'
)
```

### Deploy Log Patterns

| Pattern | Issue |
|---------|-------|
| `SIGTERM` | Container restart |
| `SIGKILL` | OOM or forced stop |
| `Connection refused` | Service not ready |
| `ECONNREFUSED` | Database not reachable |
| `Migration failed` | Database migration issue |
| `Artisan` | Laravel command issue |

### Compare Deployments

```bash
# Get previous deployment
mcp__railway__list-deployments(
  workspacePath='/path/to/project',
  limit=5,
  json=true
)

# Compare logs between deployments
# Use deploymentId parameter to get specific deployment logs
```

## Verification

```bash
# Verify app is healthy
curl https://api.botjao.com/health

# Check logs show normal operation
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='@level:info',
  lines=50
)
```

## Prevention

- Review deploy logs after each deployment
- Set up deploy notifications
- Use health checks
- Test locally before deploy
- Implement gradual rollout

## Project-Specific Notes

**BotFacebook Context:**
- Deploy command: `php artisan migrate --force`
- Health check: `/health` endpoint
- Critical logs: migration, queue worker
- Rollback: via Railway dashboard
