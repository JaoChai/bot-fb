---
id: alerts-001-error-alerts
title: Error Alert Configuration
impact: CRITICAL
impactDescription: "Missing alerts means missing critical production issues"
category: alerts
tags: [alerts, sentry, errors, notifications]
relatedRules: [sentry-001-unresolved-errors, alerts-002-performance-alerts]
---

## Symptom

- Production errors not noticed
- Finding out about issues from users
- No notifications when things break
- Too many alerts (alert fatigue)

## Root Cause

1. Alerts not configured
2. Wrong notification channels
3. Alert thresholds too high/low
4. Alert rules too broad
5. Missing critical alert types

## Diagnosis

### Quick Check

```bash
# Search for unnoticed errors
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved errors with more than 50 events'
)
```

## Solution

### Essential Alert Types

**1. New Issue Alert**
- Triggers on first occurrence
- Good for catching new bugs
- Set up in: Sentry > Alerts > Create Alert

**2. Regression Alert**
- Triggers when resolved issue reappears
- Catches incomplete fixes
- Higher priority than new issues

**3. High Volume Alert**
- Triggers on error frequency spike
- Catches cascading failures
- Example: > 100 events in 1 hour

**4. Critical Error Alert**
- Specific error types (auth, payment, etc.)
- Always notify immediately
- Example: Authentication failures

### Sentry Alert Configuration

```yaml
# Alert Rule Examples (configure in Sentry UI)

# New Issue Alert
Conditions: A new issue is created
Actions: Send Slack notification
Frequency: Every occurrence

# High Volume Alert
Conditions: Number of events > 100 in 1 hour
Actions: Send Slack + Email
Frequency: Every 30 minutes

# Regression Alert
Conditions: An issue changes state from resolved to unresolved
Actions: Send Slack notification
Frequency: Every occurrence
```

### Alert Priority Matrix

| Priority | Trigger | Response Time | Channel |
|----------|---------|---------------|---------|
| P1 Critical | Auth/payment errors | < 15 min | Slack + Email + Phone |
| P2 High | New production error | < 1 hour | Slack + Email |
| P3 Medium | High volume (>100) | < 4 hours | Slack |
| P4 Low | New staging error | < 24 hours | Email |

### Notification Channels

```bash
# Recommended setup:
# - Slack: Real-time notifications
# - Email: Digest and summaries
# - PagerDuty: Critical only (optional)
```

### Reduce Alert Fatigue

**1. Filter noise:**
- Ignore browser extensions
- Ignore known third-party issues
- Filter staging/development

**2. Group similar issues:**
- Use fingerprinting
- Dedupe related errors

**3. Set appropriate thresholds:**
- Not too sensitive (every error)
- Not too loose (miss issues)

### BotFacebook Recommended Alerts

| Alert | Condition | Priority |
|-------|-----------|----------|
| Webhook failure | `webhook AND error` | P1 |
| AI timeout | `OpenRouter AND timeout` | P2 |
| Auth failure | `Unauthenticated` multiple | P2 |
| Queue failure | `Job failed` | P2 |
| DB connection | `SQLSTATE[HY000]` | P1 |
| Rate limit | `429` multiple | P3 |

## Verification

```bash
# Test alert is working
# Trigger test error and verify notification

# Check alert history in Sentry
# Sentry > Alerts > Alert Activity
```

## Prevention

- Review alert rules quarterly
- Test alerts work
- Document escalation process
- Rotate on-call if needed
- Tune thresholds based on history

## Project-Specific Notes

**BotFacebook Context:**
- Primary channel: Slack
- Critical alerts: Webhook, AI, DB
- On-call: Set up rotation if team grows
- Escalation: 15 min → 1 hour → notify manager
