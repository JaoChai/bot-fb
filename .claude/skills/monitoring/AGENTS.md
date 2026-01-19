# Monitoring Rules Reference

> Auto-generated from rule files. Do not edit directly.
> Generated: 2026-01-19 13:19

## Table of Contents

**Total Rules: 17**

- [Sentry Error Tracking](#sentry) - 5 rules (1 CRITICAL)
- [Log Analysis](#logs) - 4 rules (3 HIGH)
- [Metrics & Performance](#metrics) - 3 rules (2 HIGH)
- [Alerting](#alerts) - 3 rules (1 CRITICAL)
- [Health Checks](#health) - 2 rules (2 HIGH)

## Impact Levels

| Level | Description |
|-------|-------------|
| **CRITICAL** | Runtime failures, data loss, security issues |
| **HIGH** | UX degradation, performance issues |
| **MEDIUM** | Code quality, maintainability |
| **LOW** | Nice-to-have, minor improvements |

## Sentry Error Tracking
<a name="sentry"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [sentry-001-unresolved-errors](rules/sentry-001-unresolved-errors.md) | **CRITICAL** | Finding Unresolved Errors |
| [sentry-002-issue-analysis](rules/sentry-002-issue-analysis.md) | **HIGH** | Analyzing Sentry Issues |
| [sentry-003-performance-monitoring](rules/sentry-003-performance-monitoring.md) | MEDIUM | Sentry Performance Monitoring |
| [sentry-004-release-tracking](rules/sentry-004-release-tracking.md) | MEDIUM | Release Tracking in Sentry |
| [sentry-005-issue-management](rules/sentry-005-issue-management.md) | MEDIUM | Managing Sentry Issues |

## Log Analysis
<a name="logs"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [logs-001-error-filtering](rules/logs-001-error-filtering.md) | **HIGH** | Filtering Error Logs |
| [logs-002-deploy-logs](rules/logs-002-deploy-logs.md) | **HIGH** | Analyzing Deployment Logs |
| [logs-003-build-logs](rules/logs-003-build-logs.md) | **HIGH** | Analyzing Build Logs |
| [logs-004-log-patterns](rules/logs-004-log-patterns.md) | MEDIUM | Common Log Patterns |

## Metrics & Performance
<a name="metrics"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [metrics-001-api-response-time](rules/metrics-001-api-response-time.md) | **HIGH** | API Response Time Monitoring |
| [metrics-002-slow-queries](rules/metrics-002-slow-queries.md) | **HIGH** | Slow Database Query Monitoring |
| [metrics-003-resource-usage](rules/metrics-003-resource-usage.md) | MEDIUM | Resource Usage Monitoring |

## Alerting
<a name="alerts"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [alerts-001-error-alerts](rules/alerts-001-error-alerts.md) | **CRITICAL** | Error Alert Configuration |
| [alerts-002-performance-alerts](rules/alerts-002-performance-alerts.md) | **HIGH** | Performance Alert Configuration |
| [alerts-003-notification-config](rules/alerts-003-notification-config.md) | MEDIUM | Alert Notification Configuration |

## Health Checks
<a name="health"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [health-001-endpoint-setup](rules/health-001-endpoint-setup.md) | **HIGH** | Health Check Endpoint Setup |
| [health-002-service-checks](rules/health-002-service-checks.md) | **HIGH** | Service Dependency Checks |

## Quick Reference by Tag

- **alerts**: alerts-001-error-alerts, alerts-002-performance-alerts, alerts-003-notification-config
- **analysis**: sentry-002-issue-analysis
- **api**: metrics-001-api-response-time
- **apm**: sentry-003-performance-monitoring
- **build**: logs-003-build-logs
- **ci**: logs-003-build-logs
- **cpu**: metrics-003-resource-usage
- **database**: metrics-002-slow-queries, health-002-service-checks
- **debug**: logs-002-deploy-logs
- **debugging**: sentry-002-issue-analysis, logs-004-log-patterns
- **dependencies**: health-002-service-checks
- **deployment**: sentry-004-release-tracking, logs-002-deploy-logs
- **email**: alerts-003-notification-config
- **endpoint**: health-001-endpoint-setup
- **errors**: sentry-001-unresolved-errors, alerts-001-error-alerts, logs-001-error-filtering
- **filtering**: logs-001-error-filtering
- **health**: health-001-endpoint-setup, health-002-service-checks
- **issues**: sentry-005-issue-management
- **laravel**: logs-004-log-patterns
- **latency**: metrics-001-api-response-time, alerts-002-performance-alerts
- **logs**: logs-001-error-filtering, logs-002-deploy-logs, logs-003-build-logs, logs-004-log-patterns
- **memory**: metrics-003-resource-usage
- **metrics**: metrics-001-api-response-time, metrics-002-slow-queries, metrics-003-resource-usage
- **monitoring**: health-001-endpoint-setup
- **neon**: metrics-002-slow-queries
- **notifications**: alerts-001-error-alerts, alerts-003-notification-config
- **patterns**: logs-004-log-patterns
- **performance**: sentry-003-performance-monitoring, metrics-001-api-response-time, metrics-002-slow-queries, alerts-002-performance-alerts
- **production**: sentry-001-unresolved-errors
- **railway**: logs-001-error-filtering, logs-002-deploy-logs, logs-003-build-logs
- **redis**: health-002-service-checks
- **releases**: sentry-004-release-tracking
- **resources**: metrics-003-resource-usage
- **root-cause**: sentry-002-issue-analysis
- **search**: sentry-001-unresolved-errors
- **sentry**: sentry-001-unresolved-errors, sentry-002-issue-analysis, sentry-003-performance-monitoring, sentry-004-release-tracking, sentry-005-issue-management, alerts-001-error-alerts
- **slack**: alerts-003-notification-config
- **thresholds**: alerts-002-performance-alerts
- **tracing**: sentry-003-performance-monitoring
- **triage**: sentry-005-issue-management
- **uptime**: health-001-endpoint-setup
- **version**: sentry-004-release-tracking
- **workflow**: sentry-005-issue-management
