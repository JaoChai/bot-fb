---
id: sentry-004-release-tracking
title: Release Tracking in Sentry
impact: MEDIUM
impactDescription: "Can't correlate errors with deployments without release tracking"
category: sentry
tags: [sentry, releases, deployment, version]
relatedRules: [sentry-001-unresolved-errors, alerts-001-error-alerts]
---

## Symptom

- Can't tell if error started with new deploy
- No visibility into release health
- Don't know which commit introduced bug
- Missing deployment correlation

## Root Cause

1. Release not configured in Sentry
2. Commits not associated with release
3. Deploy tracking not set up
4. Source maps not uploaded
5. Missing release metadata

## Diagnosis

### Quick Check

```bash
# Find recent releases
mcp__sentry__find_releases(
  organizationSlug='your-org'
)

# Check specific release
mcp__sentry__find_releases(
  organizationSlug='your-org',
  query='v1.2.3'
)
```

### Detailed Analysis

```bash
# Check issues in release
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='issues in release v1.2.3'
)

# Check for regressions
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='regressed issues in last release'
)
```

## Solution

### Configure Release in Laravel

```php
// config/sentry.php
return [
    'release' => env('SENTRY_RELEASE', trim(exec('git rev-parse HEAD'))),
];

// Or in .env
SENTRY_RELEASE=v1.2.3
```

### Set Release on Deploy (Railway)

```bash
# railway.json or via env
{
  "build": {
    "builder": "NIXPACKS"
  },
  "deploy": {
    "envVars": {
      "SENTRY_RELEASE": "$RAILWAY_GIT_COMMIT_SHA"
    }
  }
}
```

### Track Deployment

```php
// In deploy script or artisan command
\Sentry\init([
    'dsn' => env('SENTRY_LARAVEL_DSN'),
]);

$release = env('SENTRY_RELEASE');
$client = \Sentry\SentrySdk::getCurrentHub()->getClient();

// Mark release as deployed
// Sentry auto-detects from release option
```

### Search by Release

```bash
# Issues in specific release
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved issues in release v1.2.3'
)

# New issues after release
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='new issues since release v1.2.3'
)

# Regressions
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='regressed issues in production'
)
```

### Release Health Monitoring

```bash
# Check release adoption
mcp__sentry__find_releases(
  organizationSlug='your-org',
  projectSlug='bot-fb-api'
)

# Compare releases
# Look for crash rate, error rate changes
```

## Verification

```bash
# Verify release is tracked
mcp__sentry__find_releases(
  organizationSlug='your-org',
  query='latest'
)

# Check release has correct metadata
# Should show commit, deploy time, etc.
```

## Prevention

- Configure release in CI/CD
- Use git SHA or semantic version
- Associate commits with releases
- Upload source maps per release
- Monitor release health dashboard

## Project-Specific Notes

**BotFacebook Context:**
- Release format: Git commit SHA (Railway)
- Deploy tracking: Via RAILWAY_GIT_COMMIT_SHA
- Source maps: Uploaded in build step
- Monitor: New issues after each deploy
