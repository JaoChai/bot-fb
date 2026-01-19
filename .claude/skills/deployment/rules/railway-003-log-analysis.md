---
id: railway-003-log-analysis
title: Log Analysis and Filtering
impact: HIGH
impactDescription: "Cannot find errors in logs, slow debugging"
category: railway
tags: [railway, logs, debugging, analysis]
relatedRules: [troubleshoot-001-500-errors, troubleshoot-002-slow-response]
---

## Symptom

- Can't find relevant errors in logs
- Too much noise in log output
- Missing log entries
- Log timestamps confusing

## Root Cause

1. Wrong log level configured
2. Not using filters effectively
3. Looking at wrong log type
4. Logs rotated/truncated
5. Timezone mismatch

## Diagnosis

### Quick Check

```bash
# Recent errors
railway logs --filter "error" --lines 50

# Recent warnings
railway logs --filter "warning" --lines 50

# Check log level
railway variables | grep LOG
```

### Detailed Analysis

```bash
# Build vs Deploy logs
railway logs --type build --lines 50
railway logs --type deploy --lines 50

# Time-based filtering
railway logs --since "1 hour ago" --lines 100

# JSON format for parsing
railway logs --json --lines 50 | jq '.message'
```

## Solution

### Fix Steps

1. **Filter effectively**
```bash
# Multiple patterns
railway logs --filter "error|exception|failed" --lines 100

# Specific module
railway logs --filter "database|queue|redis" --lines 50

# Exclude noise
railway logs --filter "error" --lines 100 | grep -v "expected"
```

2. **Set appropriate log level**
```bash
# For debugging (temporary)
railway variables set LOG_LEVEL=debug

# For production
railway variables set LOG_LEVEL=warning
```

3. **Parse JSON logs**
```bash
# Extract specific fields
railway logs --json --lines 50 | jq -r '.level + ": " + .message'

# Filter by level
railway logs --json --lines 100 | jq 'select(.level == "error")'
```

### MCP Tool Usage

```
# Using Railway MCP tool
Use get-logs with:
- logType: "build" or "deploy"
- filter: "error|warning"
- lines: 100
- json: true (for parsing)
```

### Common Patterns

```bash
# Find all PHP errors
railway logs --filter "PHP Error|Fatal|Exception" --lines 100

# Find database issues
railway logs --filter "SQLSTATE|database|connection" --lines 100

# Find queue issues
railway logs --filter "queue|job|failed" --lines 100

# Find memory issues
railway logs --filter "memory|exhausted|limit" --lines 100

# Find timeout issues
railway logs --filter "timeout|timed out|deadline" --lines 100
```

## Verification

```bash
# Verify log level is appropriate
railway variables | grep LOG_LEVEL
# Should be "warning" or "error" in production

# Verify errors are being captured
# Trigger known error and check logs
curl https://api.botjao.com/test-error
railway logs --filter "test-error" --lines 10
```

## Prevention

- Set LOG_LEVEL=warning in production
- Use structured logging (JSON)
- Include request IDs for tracing
- Archive important logs
- Set up Sentry for error tracking

## Project-Specific Notes

**BotFacebook Context:**
- Log level: `LOG_LEVEL` in Railway vars
- JSON logging: Enabled by default
- Sentry: Captures errors automatically
- Log channels: `stack`, `single`, `daily`
