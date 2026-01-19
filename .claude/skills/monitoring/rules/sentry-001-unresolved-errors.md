---
id: sentry-001-unresolved-errors
title: Finding Unresolved Errors
impact: CRITICAL
impactDescription: "Missing production errors leads to degraded user experience"
category: sentry
tags: [sentry, errors, production, search]
relatedRules: [sentry-002-issue-analysis, alerts-001-error-alerts]
---

## Symptom

- Users report issues but no alerts received
- Unknown errors in production
- Need to find recent issues quickly
- Backlog of unresolved errors growing

## Root Cause

1. Not checking Sentry regularly
2. Alerts not configured properly
3. Too many issues to triage
4. No clear error classification
5. Search queries too broad/narrow

## Diagnosis

### Quick Check

```bash
# Search for unresolved errors
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved errors in production'
)
```

### Detailed Analysis

```bash
# More specific searches
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved errors last 24 hours affecting more than 10 users'
)

# By endpoint
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='errors in /api/chat endpoint'
)
```

## Solution

### Common Search Queries

```bash
# Recent unresolved
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved errors first seen in last hour'
)

# High impact
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved errors with more than 100 events'
)

# By environment
mcp__sentry__search_issues(
  organizationSlug='your-org',
  projectSlug='bot-fb-api',
  naturalLanguageQuery='unresolved errors in production environment'
)

# By error type
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unhandled TypeError exceptions'
)
```

### Search Query Patterns

| Goal | Natural Language Query |
|------|----------------------|
| Recent errors | `unresolved errors first seen last hour` |
| High frequency | `errors with more than 100 events` |
| User impact | `errors affecting more than 50 users` |
| Specific endpoint | `errors in /api/webhook endpoint` |
| Error type | `unhandled exceptions TypeError` |
| Regression | `regressed issues in last 24 hours` |

### Triage Workflow

1. **Check critical first**
```bash
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved errors with high frequency last hour'
)
```

2. **Then recent issues**
```bash
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='new issues first seen today'
)
```

3. **Finally check backlog**
```bash
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved issues older than 7 days'
)
```

## Verification

```bash
# Verify issues found
# Check count and severity

# Mark resolved after fixing
mcp__sentry__update_issue(
  organizationSlug='your-org',
  issueId='ISSUE-123',
  status='resolved'
)
```

## Prevention

- Set up daily error review
- Configure proper alert rules
- Triage new issues immediately
- Assign owners to issues
- Regular backlog cleanup

## Project-Specific Notes

**BotFacebook Context:**
- Organization: Check your Sentry org slug
- Projects: bot-fb-api, bot-fb-frontend
- Environments: production, staging
- Focus on: webhook errors, AI response failures
