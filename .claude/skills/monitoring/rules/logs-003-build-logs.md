---
id: logs-003-build-logs
title: Analyzing Build Logs
impact: HIGH
impactDescription: "Build failures block deployments and releases"
category: logs
tags: [logs, railway, build, ci]
relatedRules: [logs-002-deploy-logs, alerts-001-error-alerts]
---

## Symptom

- Deployment fails at build stage
- "Build failed" error
- Dependency installation errors
- Compilation errors

## Root Cause

1. Dependency version conflict
2. Missing build dependencies
3. Compilation error in code
4. Out of memory during build
5. Invalid configuration

## Diagnosis

### Quick Check

```bash
# Get build logs
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='build'
)
```

### Detailed Analysis

```bash
# Check for errors in build
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='build',
  filter='error'
)
```

## Solution

### Get Full Build Logs

```bash
# All build output
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='build',
  lines=500
)

# Specific failed deployment
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='build',
  deploymentId='failed-deployment-id'
)
```

### Common Build Errors

**Dependency Errors:**
```bash
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='build',
  filter='npm ERR OR composer'
)
```

**TypeScript/Compilation:**
```bash
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='build',
  filter='error TS OR Type error'
)
```

**Memory Issues:**
```bash
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='build',
  filter='heap OR memory'
)
```

### Build Error Patterns

| Error | Cause | Solution |
|-------|-------|----------|
| `npm ERR! ERESOLVE` | Dependency conflict | Update/fix package versions |
| `Type error` | TypeScript error | Fix type issues |
| `FATAL ERROR: heap` | Out of memory | Increase build memory |
| `composer require failed` | PHP dependency issue | Check composer.json |
| `Nixpacks error` | Build config issue | Check nixpacks config |

### Check Build Configuration

```json
// railway.json
{
  "build": {
    "builder": "NIXPACKS",
    "buildCommand": "npm run build"
  },
  "deploy": {
    "startCommand": "npm start"
  }
}
```

### PHP/Laravel Build Issues

```bash
# Composer issues
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='build',
  filter='composer'
)

# Laravel specific
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='build',
  filter='artisan OR Laravel'
)
```

### Node.js/Frontend Build Issues

```bash
# npm/yarn issues
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='build',
  filter='npm OR yarn'
)

# Vite build
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='build',
  filter='vite OR rollup'
)
```

## Verification

```bash
# Verify build succeeds
# Check deployments list
mcp__railway__list-deployments(
  workspacePath='/path/to/project',
  limit=1,
  json=true
)

# Should show status: SUCCESS
```

## Prevention

- Test build locally before push
- Lock dependency versions
- Monitor build times
- Set up build notifications
- Use CI to catch errors early

## Project-Specific Notes

**BotFacebook Context:**
- Backend build: PHP/Composer + Nixpacks
- Frontend build: Node.js + Vite
- Common issues: Composer memory, Vite build
- Build memory: May need increase for large builds
