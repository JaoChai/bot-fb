# Sentry Alert Rules — Realtime Chat Monitoring

**Org:** adsvance (https://adsvance.sentry.io)
**Date:** 2026-05-04
**Source:** Phase 5 Task 3 of native chat plan

These alert rules cannot be configured via Sentry MCP (no API support).
Configure manually via web UI at: https://adsvance.sentry.io/alerts/rules/

## Alert 1: Queue Backlog (Sustained)

| Field | Value |
|-------|-------|
| Alert type | Metric Alert |
| Metric | `queue.depth` (custom) **or** poll `/api/health/realtime` |
| Threshold | depth > 100 for 5 minutes |
| Action | Email / Slack to ops |
| Why | Indicates queue worker is falling behind — broadcasts will be delayed |

**Implementation note:** Sentry doesn't natively poll HTTP endpoints. Two paths:
- (a) Use Sentry's Cron Monitor + send heartbeat from worker every minute
- (b) Use external uptime monitor (UptimeRobot, BetterStack) hitting `/api/health/realtime` and forward `degraded` status to Sentry as an event

Recommended: **(b)** — simpler, no app changes.

## Alert 2: Job Failure Rate

| Field | Value |
|-------|-------|
| Alert type | Issue Alert |
| Condition | An event triggers an issue → `level:error` AND `tags[type]:queue_job_failed` |
| Frequency | More than 1% of total events in 5 minutes |
| Action | Email / Slack to ops |
| Why | Jobs failing means messages aren't being broadcast |

**Implementation note:** Requires `failed` jobs to report to Sentry. Verify in `app/Exceptions/Handler.php` — failed job exceptions should bubble to Sentry. Already handled by `sentry/sentry-laravel` package by default.

## Alert 3: Broadcast Exception Spike

| Field | Value |
|-------|-------|
| Alert type | Issue Alert |
| Condition | New issue with `culprit:*Broadcasting*` OR `culprit:*Reverb*` |
| Threshold | > 10 events in 1 minute |
| Action | Email / Slack to ops |
| Why | Reverb/broadcasting failure = chat goes silent for users |

## Verification After Setup

1. Trigger a test failure (run a job that throws an exception)
2. Confirm alert fires within 5 min
3. Confirm Slack/email channel receives the notification

## TODO

- [ ] User to configure alerts 1-3 in Sentry UI manually
- [ ] Set up external uptime monitor pointing at `https://api.botjao.com/api/health/realtime`
- [ ] Verify alerts fire end-to-end after setup
