---
id: {prefix}-{number}-{slug}
title: {Title}
impact: CRITICAL | HIGH | MEDIUM | LOW
impactDescription: "{One-line impact description}"
category: sentry | logs | metrics | alerts | health
tags: [{tag1}, {tag2}, {tag3}]
relatedRules: [{related-rule-id}]
---

## Symptom

- Observable symptom 1
- Observable symptom 2
- What users/developers notice

## Root Cause

1. Cause 1
2. Cause 2
3. Why this happens

## Diagnosis

### Quick Check

```bash
# MCP tool or command for quick diagnosis
mcp__sentry__search_issues(...)

# Or
curl https://api.botjao.com/health
```

### Detailed Analysis

```bash
# Deep dive commands
mcp__sentry__get_issue_details(...)

# Or SQL query
SELECT * FROM pg_stat_activity;
```

## Solution

### Fix Steps

1. **Step one**
```php
// Code or configuration
```

2. **Step two**
```php
// More code
```

### MCP Tool Commands

```bash
# Relevant MCP commands
mcp__sentry__analyze_issue_with_seer(...)
```

## Verification

```bash
# Verify fix worked
curl https://api.botjao.com/health

# Check metrics
```

## Prevention

- Preventive measure 1
- Preventive measure 2
- How to avoid in future

## Project-Specific Notes

**BotFacebook Context:**
- Sentry: Organization and project details
- Railway: Service configuration
- Neon: Database project
