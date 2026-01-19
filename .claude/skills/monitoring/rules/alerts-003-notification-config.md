---
id: alerts-003-notification-config
title: Alert Notification Configuration
impact: MEDIUM
impactDescription: "Wrong notification setup means missed or ignored alerts"
category: alerts
tags: [alerts, notifications, slack, email]
relatedRules: [alerts-001-error-alerts, alerts-002-performance-alerts]
---

## Symptom

- Alerts not reaching right people
- Too many alerts (noise)
- Missing critical notifications
- Alert fatigue
- Delayed response to incidents

## Root Cause

1. Wrong notification channels
2. Everyone receiving everything
3. No escalation path
4. Notifications not actionable
5. Missing context in alerts

## Diagnosis

### Quick Check

```bash
# Review recent alerts in Sentry
# Check Slack/Email for alert history
# Verify team is receiving notifications
```

## Solution

### Channel Configuration

**Slack Integration:**
1. Go to Sentry > Settings > Integrations > Slack
2. Connect workspace
3. Select channels for different alert types

**Email Setup:**
1. Configure team email addresses
2. Set digest frequency
3. Filter by environment/priority

### Notification Routing

| Alert Type | Channel | Frequency |
|------------|---------|-----------|
| P1 Critical | #alerts-critical + @oncall | Immediate |
| P2 High | #alerts-production | Immediate |
| P3 Medium | #alerts-all | Batched (30min) |
| P4 Low | Email digest | Daily |

### Slack Channel Setup

```bash
# Recommended channels:
#alerts-critical   - P1 only, small group
#alerts-production - All production alerts
#alerts-staging    - Staging alerts (optional)
#alerts-digest     - Daily summaries
```

### Alert Message Format

Good alert message includes:
- Error type and message
- Affected endpoint/feature
- Number of occurrences
- Link to Sentry issue
- Recent changes (deploy link)

```
🔴 New Error in Production
Error: TypeError: Cannot read property 'id' of undefined
Endpoint: /api/webhook/line
Occurrences: 45 in last hour
Environment: production
→ View in Sentry: [link]
→ Recent deploy: [link]
```

### Escalation Configuration

```yaml
# Escalation path
Level 1 (0-15 min):
  - Slack notification
  - Assigned developer

Level 2 (15-60 min):
  - Email to team
  - Secondary developer

Level 3 (60+ min):
  - Manager notification
  - Consider incident call
```

### Reduce Noise

**1. Smart grouping:**
- Group similar errors
- Dedupe repeated notifications
- Use issue fingerprinting

**2. Environment filtering:**
- Separate staging/production
- Different thresholds per env

**3. Rate limiting:**
- Max notifications per hour
- Digest mode for low priority

**4. Actionable alerts only:**
- Include context for action
- Link to runbooks
- Clear ownership

### On-Call Configuration

```yaml
# If using on-call rotation
Schedule:
  - Primary: Rotates weekly
  - Secondary: Backup coverage

Responsibilities:
  - Acknowledge P1 in 15 min
  - Acknowledge P2 in 1 hour
  - Triage new issues daily
```

## Verification

```bash
# Test alert flow
# 1. Trigger test error
# 2. Verify Slack notification received
# 3. Verify correct channel
# 4. Check alert contains context
```

## Prevention

- Review notification setup quarterly
- Test alerts work after changes
- Gather feedback on noise level
- Document escalation procedures
- Rotate on-call fairly

## Project-Specific Notes

**BotFacebook Context:**
- Primary: Slack #alerts-production
- Critical: Direct DM to developer
- Email: Weekly digest
- On-call: Not needed for small team
