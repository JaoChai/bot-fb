---
id: sentry-002-issue-analysis
title: Analyzing Sentry Issues
impact: HIGH
impactDescription: "Unable to understand root cause delays fixing production issues"
category: sentry
tags: [sentry, analysis, debugging, root-cause]
relatedRules: [sentry-001-unresolved-errors, sentry-003-performance-monitoring]
---

## Symptom

- Have error but don't understand cause
- Stacktrace doesn't reveal root issue
- Need to understand error context
- Error seems intermittent

## Root Cause

1. Stacktrace alone not enough
2. Missing context/breadcrumbs
3. Error in third-party code
4. Async error losing context
5. Multiple potential causes

## Diagnosis

### Quick Check

```bash
# Get issue details
mcp__sentry__get_issue_details(
  issueUrl='https://sentry.io/organizations/.../issues/...'
)

# Or by ID
mcp__sentry__get_issue_details(
  organizationSlug='your-org',
  issueId='PROJECT-123'
)
```

### Detailed Analysis

```bash
# Use AI analysis for root cause
mcp__sentry__analyze_issue_with_seer(
  issueUrl='https://sentry.io/organizations/.../issues/...'
)
```

## Solution

### Step 1: Get Issue Details

```bash
mcp__sentry__get_issue_details(
  issueUrl='https://sentry.io/...'
)
```

**What to look for:**
- Error message and type
- Stacktrace frames
- Request data
- User context
- Breadcrumbs (recent actions)
- Tags (environment, release)

### Step 2: Analyze with Seer

```bash
mcp__sentry__analyze_issue_with_seer(
  issueUrl='https://sentry.io/...'
)
```

**Seer provides:**
- Root cause analysis
- Code fix suggestions
- Related issues
- Impact assessment

### Step 3: Check Event History

```bash
# Search for events with specific conditions
mcp__sentry__search_issue_events(
  issueId='PROJECT-123',
  organizationSlug='your-org',
  naturalLanguageQuery='events from last hour in production'
)
```

### Step 4: Check Attachments

```bash
# If screenshots or files attached
mcp__sentry__get_event_attachment(
  organizationSlug='your-org',
  projectSlug='your-project',
  eventId='event-id'
)
```

### Analysis Checklist

| Check | Purpose |
|-------|---------|
| Error message | Understand what failed |
| Stacktrace | Where it failed |
| Request data | What triggered it |
| Breadcrumbs | What happened before |
| User context | Who experienced it |
| Tags | Environment/release info |
| Seer analysis | AI root cause |

### Common Error Patterns

**Null Reference:**
- Check if data exists before access
- Add null checks to code

**Timeout:**
- Check external API calls
- Review database queries

**Auth Error:**
- Check token expiration
- Verify permissions

**Rate Limit:**
- Check API quotas
- Add retry logic

## Verification

```bash
# After fixing, verify no new occurrences
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='issue PROJECT-123 events in last hour'
)

# Mark as resolved
mcp__sentry__update_issue(
  organizationSlug='your-org',
  issueId='PROJECT-123',
  status='resolved'
)
```

## Prevention

- Add more context to errors
- Include breadcrumbs in critical flows
- Tag releases properly
- Set up source maps
- Enable session replay if available

## Project-Specific Notes

**BotFacebook Context:**
- Common errors: Webhook signature, AI timeout, DB connection
- Always check: platform (LINE/Telegram), bot_id, user_id
- Source maps: Enabled for frontend
- Breadcrumbs: Webhook flow, AI processing
