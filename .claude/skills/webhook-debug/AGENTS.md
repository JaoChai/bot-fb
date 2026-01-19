# Webhook Debug Rules Reference

> Auto-generated from rule files. Do not edit directly.
> Generated: 2026-01-19 12:45

## Table of Contents

**Total Rules: 23**

- [LINE Debug](#line) - 5 rules (1 CRITICAL)
- [Telegram Debug](#telegram) - 4 rules (1 CRITICAL)
- [WebSocket/Reverb](#ws) - 5 rules (2 HIGH)
- [Job Queue](#queue) - 5 rules (1 CRITICAL)
- [Message Flow](#flow) - 4 rules (2 HIGH)

## Impact Levels

| Level | Description |
|-------|-------------|
| **CRITICAL** | Runtime failures, data loss, security issues |
| **HIGH** | UX degradation, performance issues |
| **MEDIUM** | Code quality, maintainability |
| **LOW** | Nice-to-have, minor improvements |

## LINE Debug
<a name="line"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [line-001-signature-validation](rules/line-001-signature-validation.md) | **CRITICAL** | LINE Webhook Signature Validation Fails |
| [line-002-reply-token-expiry](rules/line-002-reply-token-expiry.md) | **HIGH** | LINE Reply Token Expired |
| [line-003-flex-message-errors](rules/line-003-flex-message-errors.md) | **HIGH** | LINE Flex Message Format Errors |
| [line-004-profile-fetch-failure](rules/line-004-profile-fetch-failure.md) | MEDIUM | LINE User Profile Fetch Fails |
| [line-005-rich-menu-issues](rules/line-005-rich-menu-issues.md) | MEDIUM | LINE Rich Menu Not Showing |

## Telegram Debug
<a name="telegram"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [telegram-001-webhook-setup](rules/telegram-001-webhook-setup.md) | **CRITICAL** | Telegram Webhook Not Receiving Updates |
| [telegram-002-bot-token-invalid](rules/telegram-002-bot-token-invalid.md) | **HIGH** | Telegram Bot Token Invalid |
| [telegram-003-message-format-errors](rules/telegram-003-message-format-errors.md) | MEDIUM | Telegram Message Format Errors |
| [telegram-004-inline-keyboard](rules/telegram-004-inline-keyboard.md) | MEDIUM | Telegram Inline Keyboard Issues |

## WebSocket/Reverb
<a name="ws"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [ws-001-connection-drop](rules/ws-001-connection-drop.md) | **HIGH** | WebSocket Connection Drops |
| [ws-002-auth-failure](rules/ws-002-auth-failure.md) | **HIGH** | WebSocket Authentication Fails |
| [ws-003-channel-subscription](rules/ws-003-channel-subscription.md) | MEDIUM | WebSocket Channel Subscription Fails |
| [ws-004-broadcast-events](rules/ws-004-broadcast-events.md) | MEDIUM | Events Not Broadcasting |
| [ws-005-ping-pong-timeout](rules/ws-005-ping-pong-timeout.md) | LOW | WebSocket Ping/Pong Timeout |

## Job Queue
<a name="queue"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [queue-001-failed-jobs](rules/queue-001-failed-jobs.md) | **CRITICAL** | Jobs Failing in Queue |
| [queue-002-job-timeout](rules/queue-002-job-timeout.md) | **HIGH** | Job Timeout Errors |
| [queue-003-worker-config](rules/queue-003-worker-config.md) | **HIGH** | Queue Worker Configuration Issues |
| [queue-004-retry-strategy](rules/queue-004-retry-strategy.md) | MEDIUM | Queue Retry Strategy |
| [queue-005-duplicate-processing](rules/queue-005-duplicate-processing.md) | MEDIUM | Jobs Processed Multiple Times |

## Message Flow
<a name="flow"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [flow-001-message-tracing](rules/flow-001-message-tracing.md) | **HIGH** | Trace Message Through System |
| [flow-002-idempotency](rules/flow-002-idempotency.md) | **HIGH** | Implement Idempotent Processing |
| [flow-003-error-handling](rules/flow-003-error-handling.md) | MEDIUM | Proper Error Handling in Flow |
| [flow-004-response-timing](rules/flow-004-response-timing.md) | MEDIUM | Optimize Response Timing |

## Quick Reference by Tag

- **api**: line-004-profile-fetch-failure, telegram-002-bot-token-invalid
- **at-least-once**: queue-005-duplicate-processing
- **authentication**: telegram-002-bot-token-invalid, ws-002-auth-failure
- **backoff**: queue-004-retry-strategy
- **broadcast**: ws-004-broadcast-events
- **buttons**: telegram-004-inline-keyboard
- **callback**: telegram-004-inline-keyboard
- **channels**: ws-002-auth-failure, ws-003-channel-subscription
- **configuration**: line-005-rich-menu-issues, queue-003-worker-config, telegram-001-webhook-setup
- **connection**: ws-001-connection-drop
- **debugging**: queue-001-failed-jobs, flow-001-message-tracing
- **duplicate**: queue-005-duplicate-processing, flow-002-idempotency
- **error-handling**: flow-003-error-handling
- **events**: ws-003-channel-subscription, ws-004-broadcast-events
- **exceptions**: flow-003-error-handling
- **failed**: queue-001-failed-jobs
- **fallback**: flow-003-error-handling
- **flex-message**: line-003-flex-message-errors
- **flow**: flow-001-message-tracing
- **formatting**: line-003-flex-message-errors, telegram-003-message-format-errors
- **hmac**: line-001-signature-validation
- **html**: telegram-003-message-format-errors
- **idempotency**: queue-005-duplicate-processing, flow-002-idempotency
- **inline-keyboard**: telegram-004-inline-keyboard
- **jobs**: queue-001-failed-jobs
- **json**: line-003-flex-message-errors
- **keepalive**: ws-005-ping-pong-timeout
- **latency**: flow-004-response-timing
- **line**: line-001-signature-validation, line-002-reply-token-expiry, line-003-flex-message-errors, line-004-profile-fetch-failure, line-005-rich-menu-issues
- **logging**: flow-001-message-tracing
- **long-running**: queue-002-job-timeout
- **markdown**: telegram-003-message-format-errors
- **message**: telegram-003-message-format-errors
- **optimization**: flow-004-response-timing
- **performance**: queue-002-job-timeout, flow-004-response-timing
- **ping**: ws-005-ping-pong-timeout
- **pong**: ws-005-ping-pong-timeout
- **profile**: line-004-profile-fetch-failure
- **push-message**: line-002-reply-token-expiry
- **queue**: queue-001-failed-jobs, queue-002-job-timeout, queue-003-worker-config, queue-004-retry-strategy, queue-005-duplicate-processing, ws-004-broadcast-events
- **real-time**: ws-001-connection-drop
- **reply-token**: line-002-reply-token-expiry
- **resilience**: queue-004-retry-strategy, flow-003-error-handling
- **retry**: queue-004-retry-strategy
- **reverb**: ws-001-connection-drop
- **rich-menu**: line-005-rich-menu-issues
- **safety**: flow-002-idempotency
- **sanctum**: ws-002-auth-failure
- **security**: line-001-signature-validation
- **setup**: telegram-001-webhook-setup
- **signature**: line-001-signature-validation
- **subscription**: ws-003-channel-subscription
- **supervisor**: queue-003-worker-config
- **telegram**: telegram-001-webhook-setup, telegram-002-bot-token-invalid, telegram-003-message-format-errors, telegram-004-inline-keyboard
- **timeout**: line-002-reply-token-expiry, queue-002-job-timeout, ws-005-ping-pong-timeout
- **timing**: flow-004-response-timing
- **token**: telegram-002-bot-token-invalid
- **tracing**: flow-001-message-tracing
- **ui**: line-005-rich-menu-issues
- **user-data**: line-004-profile-fetch-failure
- **webhook**: line-001-signature-validation, telegram-001-webhook-setup, flow-002-idempotency
- **websocket**: ws-001-connection-drop, ws-002-auth-failure, ws-003-channel-subscription, ws-004-broadcast-events, ws-005-ping-pong-timeout
- **worker**: queue-003-worker-config
