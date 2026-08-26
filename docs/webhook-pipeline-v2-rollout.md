# Webhook Pipeline v2 — Rollout Plan

## Purpose

The shared webhook pipeline v2 (`App\Services\Webhook\WebhookPipeline`, built in
Tasks 6–9) routes webhook processing through a single
resolve → response → send step chain instead of three divergent per-channel job
paths. It is opt-in and **default OFF**: when the flag is off, the LINE,
Facebook, and Telegram jobs run their existing legacy paths with zero behavior
change. This document defines the per-channel enable order, the monitoring
signals to watch during rollout, the rollback procedure, and the known
pre-existing issues discovered during the refactor.

## Configuration

Defined in `backend/config/webhook_pipeline_v2.php`:

| Env var | Meaning | Default |
|---|---|---|
| `WEBHOOK_PIPELINE_V2_ENABLED` | Master switch for v2 | `false` |
| `WEBHOOK_PIPELINE_V2_BOT_IDS` | Comma-separated bot-id whitelist (restricts which bots use v2 when enabled) | empty (= all bots if enabled) |

Flag semantics (`App\Services\Webhook\WebhookPipelineV2Flag::enabledFor(Bot)`):
master switch OFF → legacy path. Master ON + empty whitelist → v2 for all
bots. Master ON + whitelist → v2 only for listed bot ids.

## Per-channel enable order

### 1. LINE — enable first

LINE is the safest first channel: the existing `LineWebhookPipelineFlag`
already provides a per-bot dual-path experience in `ProcessLINEWebhook` (the
job already branches between the LINE-specific pipeline and the legacy path),
so the v2 flag simply layers on top with the same semantics. Recommended
steps:

1. Set `WEBHOOK_PIPELINE_V2_ENABLED=true` and `WEBHOOK_PIPELINE_V2_BOT_IDS` to
   a single internal/low-traffic LINE bot.
2. Watch the monitoring signals below for ≥ 24–48 h.
3. Widen the whitelist incrementally (a few bot ids at a time).
4. Finally, clear `WEBHOOK_PIPELINE_V2_BOT_IDS` to enable all LINE bots.

### 2. Telegram — enable second

Telegram has a registered channel adapter (`TelegramChannelAdapter`) and a
clean `runSharedPipeline()` wiring in `ProcessTelegramWebhook`. Enable the same
way as LINE: single bot id first, widen incrementally, then all bots. (Do not
attempt this before LINE has been stable on v2.)

### 3. Facebook — DO NOT enable

**DO NOT enable v2 for Facebook bots until `FacebookChannelAdapter` exists in
`ChannelAdapterFactory`.** The factory currently registers only `line` and
`telegram`. If v2 is enabled for a Facebook bot, `SendResponseStep` calls
`ChannelAdapterFactory::make('facebook')`, which throws
`InvalidArgumentException`; the exception is caught and logged, so the bot
does not crash, but **the message is never sent**. Until a
`FacebookChannelAdapter` is added and the factory registers `facebook`,
Facebook bots must stay on the legacy path (i.e. never add Facebook bot ids to
`WEBHOOK_PIPELINE_V2_BOT_IDS`).

## Monitoring signals

Watch these during and after each enablement step:

1. **Error rate on webhook processing logs** — compare application-log errors
   (and Sentry error volume) for the three webhook jobs before vs. after
   flipping the flag for a given bot set. A new error class appearing only
   after enablement (e.g. `Unsupported channel type` from the adapter factory)
   is an immediate stop signal.
2. **Circuit-breaker fallback trigger count** — `ResilienceMetricsService`
   records every circuit-breaker state change via
   `recordCircuitStateChange(service, from, to)` (logged at INFO level and
   attached as a `circuit_breaker` Sentry breadcrumb). Count these events
   (e.g. log grep on `Circuit breaker for .* changed from`) and compare the
   trigger rate to baseline. A spike indicates v2-path failures pushing
   downstream services (AI, channel APIs) into the fallback path.
3. **Queue depth on the webhook queues** — webhook jobs are dispatched to
   `QueueRouter::llmQueue()` (the `llm` queue when `queue.llm_split_enabled`
   is set, otherwise the `webhooks` queue), with `QueueRouter::connection()`
   falling back to the `database` connection while Redis is down. Monitor the
   depth of both the `llm`/`webhooks` queue and the `database`-connection
   webhook queue: a sustained depth increase or age of oldest job signals
   slower v2 processing or worker starvation.

Stop conditions: error rate above baseline for the enabled bot set, an
unexpected circuit-breaker OPEN state, or queue depth trending upward without
recovering. Any of these → roll back (below) and investigate.

## Rollback procedure

Rollback is a config change only — **no deploy needed beyond a config refresh
(env var change / config re-read)**:

1. Set `WEBHOOK_PIPELINE_V2_ENABLED=false`, or
2. Narrow `WEBHOOK_PIPELINE_V2_BOT_IDS` to remove the affected bot id(s).

The flag is read per request/job (`config('webhook_pipeline_v2.enabled')` +
whitelist), so the legacy paths resume immediately for the affected bots with
zero behavior change. Verify the rollback by confirming webhook traffic for
the affected bots no longer produces v2-path log lines and the monitoring
signals return to baseline.

## Known pre-existing issues (documented during this refactor)

These are **pre-existing** defects found during the webhook-pipeline refactor.
They are not introduced by v2 and were intentionally not fixed in the
refactor branch; both block safe full rollout of v2.

1. **Facebook postback `messages.type='postback'` missing from the schema
   enum → production postbacks fail.**
   `ProcessFacebookWebhook::handlePostback()` writes the user message with
   `'type' => 'postback'`, but the `messages` table schema
   (`2025_12_23_145426_create_messages_table`) enum has no `postback` value.
   Every FB postback webhook therefore fails on the user-message insert (CHECK
   constraint violation) in production. Verified in Task 7 (controller-level
   reproduction). The Task 7 tests pin the buggy behavior with a known-bug
   docblock. A separate schema-fix task (add `postback` to the `messages.type`
   enum) is required before Facebook postback handling can be trusted.
2. **sqlite `channel_type` constraint skips `telegram` → Telegram e2e tests
   impossible locally.**
   `2025_12_27_172000_update_channel_type_constraint` widens the
   `bots.channel_type` CHECK to include `telegram` on pgsql but **skips sqlite
   entirely** (sqlite can't ALTER a CHECK constraint and the migration does
   not recreate the table). Local/test sqlite schemas therefore still enforce
   the original enum without `telegram`, so a full Telegram create/INSERT e2e
   flow cannot run on the sqlite test schema (production pgsql is unaffected).
   Telegram step tests therefore cover the conversation-reuse path (seeding
   via raw SQL) instead of the create path; the same limitation is documented
   in the Task 8 `ProcessTelegramWebhookMapperTest`.

Additional v2 limitations to be aware of (tracked, not yet blockers given the
flag is OFF): the v2 steps are not full-sequence-parity with the legacy jobs
(dedup, stats, post-transaction broadcasts, `LeadRecoveryService::markCustomerResponded`,
LINE aggregation/response-lock, and flow plugins are not in the v2 steps yet —
see `runSharedPipeline()` docblocks in each job).
