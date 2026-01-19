---
id: logs-004-log-patterns
title: Common Log Patterns
impact: MEDIUM
impactDescription: "Knowing log patterns speeds up debugging"
category: logs
tags: [logs, patterns, debugging, laravel]
relatedRules: [logs-001-error-filtering, logs-002-deploy-logs]
---

## Symptom

- Not sure what to search for
- Missing important log messages
- Don't know error signatures
- Taking too long to find issues

## Root Cause

1. Unfamiliar with log format
2. Don't know common error patterns
3. Logs not structured properly
4. Missing context in logs

## Diagnosis

### Quick Reference Filters

```bash
# PHP errors
mcp__railway__get-logs(filter='PHP Fatal error')
mcp__railway__get-logs(filter='PHP Parse error')
mcp__railway__get-logs(filter='PHP Warning')

# Laravel errors
mcp__railway__get-logs(filter='Exception')
mcp__railway__get-logs(filter='SQLSTATE')
mcp__railway__get-logs(filter='Illuminate')

# Queue/Job errors
mcp__railway__get-logs(filter='Job failed')
mcp__railway__get-logs(filter='ProcessLINEWebhook')
```

## Solution

### PHP Error Patterns

| Pattern | Meaning |
|---------|---------|
| `PHP Fatal error` | Unrecoverable error |
| `PHP Parse error` | Syntax error |
| `PHP Warning` | Non-fatal warning |
| `PHP Notice` | Minor issue |
| `Allowed memory size exhausted` | OOM |
| `Maximum execution time` | Timeout |

### Laravel Error Patterns

| Pattern | Meaning |
|---------|---------|
| `SQLSTATE[42S02]` | Table not found |
| `SQLSTATE[23000]` | Constraint violation |
| `SQLSTATE[HY000]` | Connection error |
| `Unauthenticated` | Auth failed |
| `The given data was invalid` | Validation failed |
| `Route [xxx] not defined` | Missing route |

### Queue/Job Patterns

| Pattern | Meaning |
|---------|---------|
| `Job failed` | Job processing failed |
| `MaxAttemptsExceededException` | Too many retries |
| `Queue connection could not be established` | Redis/queue issue |
| `Processing:` | Job started |
| `Processed:` | Job completed |

### HTTP Patterns

| Pattern | Meaning |
|---------|---------|
| `@status:500` | Server error |
| `@status:404` | Not found |
| `@status:401` | Unauthorized |
| `@status:429` | Rate limited |
| `Connection refused` | Service down |

### BotFacebook Specific Patterns

```bash
# Webhook issues
mcp__railway__get-logs(filter='webhook signature')
mcp__railway__get-logs(filter='LINE webhook')
mcp__railway__get-logs(filter='Telegram')

# AI/RAG issues
mcp__railway__get-logs(filter='OpenRouter')
mcp__railway__get-logs(filter='embedding')
mcp__railway__get-logs(filter='semantic search')

# Database
mcp__railway__get-logs(filter='pg_query')
mcp__railway__get-logs(filter='Neon')
```

### Structured Log Fields

```php
// Good structured logging
Log::info('message.processed', [
    'bot_id' => $bot->id,
    'platform' => 'LINE',
    'user_id' => $userId,
    'response_time_ms' => $responseTime,
]);

// Search by field
// Use JSON structured search
```

### Log Search Examples

```bash
# Find slow API calls
mcp__railway__get-logs(
  filter='response_time AND @level:warn'
)

# Find specific bot errors
mcp__railway__get-logs(
  filter='bot_id:123 AND error'
)

# Find user issues
mcp__railway__get-logs(
  filter='user_id:abc123'
)
```

## Verification

```bash
# Test your filter returns expected results
# Check sample of results match pattern
```

## Prevention

- Use structured logging consistently
- Add context to all log messages
- Document custom log patterns
- Create log search templates
- Review logs regularly

## Project-Specific Notes

**BotFacebook Context:**
- Log format: JSON structured
- Key fields: bot_id, platform, user_id, conversation_id
- Critical patterns: webhook, AI timeout, queue failed
- Tools: Railway logs + Sentry errors
