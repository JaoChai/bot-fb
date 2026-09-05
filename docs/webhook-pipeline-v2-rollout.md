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

### 3. Facebook — enabled together with "all bots"

`FacebookChannelAdapter` is registered in `ChannelAdapterFactory` (Track 2
PR-2). Facebook bots follow the same flag as LINE/Telegram; no separate step.
There are no Facebook bots serving customers today (spec D4), so this step
only proves nothing crashes for them before the flag is widened to "all".

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

## Known pre-existing issues (status as of Track 2, 2026-09-05)

1. **Facebook postback schema — fixed.** `messages.type` gained `postback` in
   migration `2026_08_26_000000_add_postback_to_messages_type_enum.php` (PR
   #250). No longer a blocker.
2. **sqlite `channel_type` constraint skips `telegram` → Telegram e2e tests
   impossible locally.**
   `2025_12_27_172000_update_channel_type_constraint` widens the
   `bots.channel_type` CHECK to include `telegram` on pgsql but **skips sqlite
   entirely** (sqlite can't ALTER a CHECK constraint and the migration does
   not recreate the table). Local/test sqlite schemas therefore still enforce
   the original enum without `telegram`, so a full Telegram create/INSERT e2e
   flow cannot run on the sqlite test schema (production pgsql is unaffected).
   Telegram step tests cover the conversation-reuse path (seeding via raw
   SQL) instead of the create path; the same limitation is documented in
   `ProcessTelegramWebhookMapperTest`. Similarly, the Facebook postback v2
   parity test (`ProcessFacebookWebhookPostbackTest::test_postback_v2_path_matches_legacy_stats`)
   skips on sqlite for the same class of reason (its CHECK constraint predates
   the pgsql-only widening) — production (pgsql) is unaffected in both cases.

Parity status (Track 2 PR-2): dedup, stats, post-commit broadcasts,
`LeadRecoveryService::markCustomerResponded`, Facebook typing indicator,
Telegram media/placeholder and flow plugins are implemented in the v2 steps.
LINE runs its production `LineWebhook/*` pipeline unchanged via
`LinePipelineStep`. See `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md`
§6 and `docs/superpowers/runbooks/2026-09-05-webhook-v2-rollout.md` for the
rollout procedure superseding the per-channel order below.
