---
id: sentry-005-issue-management
title: Managing Sentry Issues
impact: MEDIUM
impactDescription: "Unmanaged issues lead to alert fatigue and missed critical errors"
category: sentry
tags: [sentry, issues, triage, workflow]
relatedRules: [sentry-001-unresolved-errors, alerts-001-error-alerts]
---

## Symptom

- Too many issues to manage
- Can't prioritize what to fix
- Issues keep piling up
- Team ignoring alerts
- Duplicate issues

## Root Cause

1. No triage process
2. No issue ownership
3. Not resolving fixed issues
4. Not ignoring noise
5. Missing fingerprinting

## Diagnosis

### Quick Check

```bash
# Check issue backlog
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved issues count'
)

# Check high priority
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved issues with more than 50 events'
)
```

## Solution

### Triage Workflow

1. **Review new issues daily**
```bash
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='new issues first seen today'
)
```

2. **Prioritize by impact**
```bash
# High frequency
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='issues with more than 100 events'
)

# Affecting many users
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='issues affecting more than 20 users'
)
```

3. **Assign ownership**
```bash
mcp__sentry__update_issue(
  organizationSlug='your-org',
  issueId='PROJECT-123',
  assignedTo='user:12345'  # or 'team:backend'
)
```

### Update Issue Status

```bash
# Mark as resolved
mcp__sentry__update_issue(
  organizationSlug='your-org',
  issueId='PROJECT-123',
  status='resolved'
)

# Resolve in next release
mcp__sentry__update_issue(
  organizationSlug='your-org',
  issueId='PROJECT-123',
  status='resolvedInNextRelease'
)

# Ignore noise
mcp__sentry__update_issue(
  organizationSlug='your-org',
  issueId='PROJECT-123',
  status='ignored'
)
```

### Issue Management Best Practices

| Action | When to Use |
|--------|-------------|
| Resolve | Issue is fixed and deployed |
| Resolve in Next Release | Fix merged, awaiting deploy |
| Ignore | Known issue, won't fix, or noise |
| Assign | Issue needs investigation |
| Link to PR | Associate fix with issue |

### Reduce Noise

**Configure Ignore Rules:**
- Browser extensions causing errors
- Bots/crawlers
- Known third-party issues
- Development/staging noise

**Fingerprinting:**
```php
// Custom fingerprint in exception handler
\Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($exception): void {
    if ($exception instanceof CustomException) {
        $scope->setFingerprint(['custom-exception', $exception->getCode()]);
    }
});
```

### Weekly Cleanup

```bash
# Find old unresolved
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved issues older than 30 days'
)

# Review and resolve/ignore old issues
```

## Verification

```bash
# Check backlog is manageable
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved issues'
)

# Should be < 50 unresolved at any time
```

## Prevention

- Triage daily
- Resolve issues promptly
- Configure fingerprinting
- Ignore known noise
- Assign owners to issues
- Review metrics weekly

## Project-Specific Notes

**BotFacebook Context:**
- Triage: Daily review of new issues
- Critical: Webhook/AI errors
- Ignore: Browser extension errors, bot crawlers
- Teams: backend, frontend
- SLA: Critical issues < 4 hours
