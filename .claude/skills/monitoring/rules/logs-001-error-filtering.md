---
id: logs-001-error-filtering
title: Filtering Error Logs
impact: HIGH
impactDescription: "Unable to find relevant logs wastes time during incidents"
category: logs
tags: [logs, railway, filtering, errors]
relatedRules: [logs-002-deploy-logs, sentry-001-unresolved-errors]
---

## Symptom

- Can't find relevant logs in noise
- Scrolling through thousands of lines
- Missing critical error context
- Taking too long to diagnose issues

## Root Cause

1. Logs too verbose
2. Not using filters effectively
3. Missing structured logging
4. No log level separation
5. Not knowing filter syntax

## Diagnosis

### Quick Check

```bash
# Get error logs only
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='@level:error'
)
```

### Detailed Analysis

```bash
# Get more context with lines
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='@level:error',
  lines=200
)
```

## Solution

### Basic Error Filtering

```bash
# Errors only
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='@level:error',
  lines=100
)

# Errors and warnings
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='@level:error OR @level:warn'
)
```

### Filter by Text

```bash
# Specific error message
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='Connection refused'
)

# Multiple terms
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='timeout AND database'
)
```

### Filter Syntax Reference

| Filter | Purpose |
|--------|---------|
| `@level:error` | Error logs only |
| `@level:warn` | Warning logs |
| `@level:info` | Info logs |
| `text` | Contains text |
| `A AND B` | Both conditions |
| `A OR B` | Either condition |
| `@status:500` | HTTP 500 errors |

### Common Filter Patterns

```bash
# PHP Fatal errors
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='PHP Fatal error'
)

# Database errors
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='SQLSTATE'
)

# Memory issues
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='memory exhausted'
)

# Auth failures
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='Unauthenticated'
)

# Webhook errors
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='webhook AND error'
)
```

### Get JSON Format for Parsing

```bash
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='@level:error',
  json=true,
  lines=50
)
```

## Verification

```bash
# Verify filter returns expected results
# Should see only error-level logs

# Check specific timeframe
# Use lines parameter to control amount
```

## Prevention

- Use structured logging
- Set appropriate log levels
- Add context to log messages
- Document common filter patterns
- Create saved searches

## Project-Specific Notes

**BotFacebook Context:**
- Common errors: webhook, AI timeout, DB connection
- Log format: JSON structured
- Services: api, queue worker
- Key filters: `webhook`, `ProcessLINEWebhook`, `OpenRouter`
