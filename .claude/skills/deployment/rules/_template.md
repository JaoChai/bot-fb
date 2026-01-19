---
id: {prefix}-{number}-{slug}
title: {Title}
impact: CRITICAL|HIGH|MEDIUM|LOW
impactDescription: "{Brief impact statement}"
category: {category}
tags: [{tag1}, {tag2}, {tag3}]
relatedRules: [{related-rule-1}, {related-rule-2}]
---

## Symptom

- Observable problem 1
- Observable problem 2
- Observable problem 3

## Root Cause

1. Primary cause
2. Secondary cause
3. Other causes

## Diagnosis

### Quick Check

```bash
# Command to quickly identify the issue
railway logs --lines 50
```

### Detailed Analysis

```bash
# More thorough investigation
railway logs --filter "error" --lines 200
```

## Solution

### Fix Steps

1. **Step one**
```bash
# Command or action
```

2. **Step two**
```bash
# Command or action
```

### Runbook

```bash
# Complete runbook for this issue
# Step 1: Identify
# Step 2: Fix
# Step 3: Verify
```

## Verification

```bash
# Verify the fix worked
curl https://api.botjao.com/health
```

## Prevention

- Prevention measure 1
- Prevention measure 2
- Prevention measure 3

## Project-Specific Notes

**BotFacebook Context:**
- Specific configuration or commands
- Railway project specifics
- Environment details
