---
id: health-003-monitoring-setup
title: Health Monitoring and Alerting Setup
impact: MEDIUM
impactDescription: "Issues not detected until users report them"
category: health
tags: [monitoring, alerting, uptime, sentry]
relatedRules: [health-001-endpoint-config, health-002-component-checks]
---

## Symptom

- Issues discovered by users first
- No alerts when service goes down
- No visibility into service health
- Don't know when issues started

## Root Cause

1. No monitoring configured
2. Alerts not set up
3. Wrong notification channels
4. Alert fatigue (too many alerts)
5. Monitoring gaps

## Diagnosis

### Quick Check

```bash
# Check Sentry configuration
railway variables | grep SENTRY

# Verify errors are being captured
# Generate test error and check Sentry dashboard

# Check Railway metrics
railway metrics
```

### Detailed Analysis

```bash
# Check Sentry releases
# Via Sentry MCP tool
Use find_releases with organizationSlug

# Check error rates
Use search_issues with naturalLanguageQuery: "errors last hour"

# Check uptime via external monitor
# Configure UptimeRobot, Better Uptime, etc.
```

## Solution

### Fix Steps

1. **Configure Sentry**
```bash
# Set Sentry DSN
railway variables set SENTRY_DSN=https://xxx@sentry.io/xxx
railway variables set SENTRY_TRACES_SAMPLE_RATE=0.1

# In Laravel
composer require sentry/sentry-laravel
php artisan sentry:publish
```

2. **Configure Sentry in code**
```php
// config/sentry.php
return [
    'dsn' => env('SENTRY_DSN'),
    'release' => env('APP_VERSION', 'unknown'),
    'environment' => env('APP_ENV', 'production'),
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.1),
    'profiles_sample_rate' => 0.1,
];
```

3. **Set up uptime monitoring**
```bash
# Configure external uptime monitor to check:
# - https://api.botjao.com/health
# - https://www.botjao.com
# - wss://reverb.botjao.com (WebSocket)

# Recommended services:
# - UptimeRobot (free tier available)
# - Better Uptime
# - Pingdom
```

4. **Configure alert notifications**
```bash
# In Sentry:
# - Set up Slack/Discord integration
# - Configure email alerts
# - Set alert rules for:
#   - Error rate spike
#   - New error types
#   - Performance degradation
```

5. **Create Sentry release on deploy**
```bash
# In deploy script
VERSION=$(git rev-parse --short HEAD)
sentry-cli releases new "$VERSION"
sentry-cli releases set-commits "$VERSION" --auto
sentry-cli releases finalize "$VERSION"
```

### Monitoring Dashboard

```php
// app/Http/Controllers/Admin/MonitoringController.php
class MonitoringController extends Controller
{
    public function dashboard(): JsonResponse
    {
        return response()->json([
            'health' => app(HealthCheckService::class)->check(),
            'metrics' => [
                'active_users' => $this->getActiveUsers(),
                'requests_per_minute' => $this->getRequestRate(),
                'error_rate' => $this->getErrorRate(),
                'queue_size' => Queue::size(),
            ],
            'recent_errors' => $this->getRecentErrors(),
        ]);
    }
}
```

### Alert Rules

```yaml
# Recommended alert rules

# High priority - immediate notification
- name: "Service Down"
  condition: "health check fails for 2 minutes"
  notify: "slack, sms"

- name: "Error Rate Spike"
  condition: "error rate > 10% for 5 minutes"
  notify: "slack"

# Medium priority - email/slack
- name: "High Error Rate"
  condition: "error rate > 5% for 15 minutes"
  notify: "slack"

- name: "Slow Response Time"
  condition: "p95 latency > 2s for 10 minutes"
  notify: "slack"

# Low priority - daily digest
- name: "New Error Type"
  condition: "new error not seen before"
  notify: "email"
```

## Verification

```bash
# Test Sentry integration
railway exec "php artisan tinker --execute=\"\\Sentry\\captureMessage('Test message');\""

# Check Sentry for message
# Via Sentry dashboard or MCP tool

# Test health monitoring
curl -sf https://api.botjao.com/health || echo "Would trigger alert"

# Verify notifications
# Check configured channels for test alerts
```

## Prevention

- Set up monitoring before launch
- Test alert notifications regularly
- Review and tune alert thresholds
- Document incident response procedures
- Regular monitoring audits

## Project-Specific Notes

**BotFacebook Context:**
- Error tracking: Sentry
- Uptime: External monitor recommended
- Alerts: Slack integration
- Metrics: Railway dashboard + custom
