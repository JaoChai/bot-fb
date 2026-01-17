# Sentry Integration Guide

## MCP Tools Reference

### search_issues

Search for issues in Sentry.

```
mcp__sentry__search_issues(
  organizationSlug='your-org',
  naturalLanguageQuery='unresolved errors in production'
)
```

**Common Queries:**
- `unresolved errors last 24 hours`
- `errors affecting more than 100 users`
- `errors in production environment`
- `critical bugs last week`

### get_issue_details

Get detailed information about a specific issue.

```
mcp__sentry__get_issue_details(
  issueUrl='https://sentry.io/...'
)
```

**What it returns:**
- Stack trace
- Error message
- Affected users count
- First/last seen timestamps
- Tags and context

### analyze_issue_with_seer

AI-powered root cause analysis.

```
mcp__sentry__analyze_issue_with_seer(
  issueUrl='https://sentry.io/...'
)
```

**What it provides:**
- Root cause analysis
- Suggested fixes
- Related code snippets
- Step-by-step resolution

### search_events

Search for individual events or get counts.

```
mcp__sentry__search_events(
  organizationSlug='your-org',
  naturalLanguageQuery='count of database errors today'
)
```

## Laravel Sentry Setup

### Configuration

```php
// config/sentry.php
return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
    'release' => env('SENTRY_RELEASE'),
    'environment' => env('APP_ENV'),
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE', 0.1),
    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE', 0.1),
];
```

### Environment Variables

```env
SENTRY_LARAVEL_DSN=https://xxx@xxx.ingest.sentry.io/xxx
SENTRY_RELEASE=1.0.0
SENTRY_TRACES_SAMPLE_RATE=0.1
```

### Custom Context

```php
// Add user context
\Sentry\configureScope(function (\Sentry\State\Scope $scope): void {
    $scope->setUser([
        'id' => auth()->id(),
        'email' => auth()->user()?->email,
    ]);
});

// Add custom context
\Sentry\configureScope(function (\Sentry\State\Scope $scope): void {
    $scope->setContext('bot', [
        'id' => $bot->id,
        'platform' => $bot->platform,
    ]);
});
```

### Custom Breadcrumbs

```php
\Sentry\addBreadcrumb(new \Sentry\Breadcrumb(
    \Sentry\Breadcrumb::LEVEL_INFO,
    \Sentry\Breadcrumb::TYPE_DEFAULT,
    'bot.message',
    'Processing incoming message',
    ['message_id' => $message->id]
));
```

### Capture Exceptions

```php
try {
    $this->processMessage($message);
} catch (\Exception $e) {
    \Sentry\captureException($e);
    throw $e;
}
```

## Frontend (React) Setup

```typescript
// src/lib/sentry.ts
import * as Sentry from '@sentry/react';

Sentry.init({
  dsn: import.meta.env.VITE_SENTRY_DSN,
  environment: import.meta.env.VITE_APP_ENV,
  integrations: [
    Sentry.browserTracingIntegration(),
    Sentry.replayIntegration(),
  ],
  tracesSampleRate: 0.1,
  replaysSessionSampleRate: 0.1,
  replaysOnErrorSampleRate: 1.0,
});
```

## Best Practices

1. **Set release version** - Helps track which version has issues
2. **Add user context** - Know which users are affected
3. **Use breadcrumbs** - Trace actions leading to error
4. **Sample wisely** - 10% sampling for production
5. **Filter sensitive data** - Don't send passwords/tokens to Sentry
