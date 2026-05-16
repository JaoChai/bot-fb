# ProcessLINEWebhook Refactor — Design

**Status:** Spec  **Date:** 2026-05-16  **Driver:** Phase 2 perf audit #9

## Problem

`backend/app/Jobs/ProcessLINEWebhook.php` is 1432 LOC, 19 methods, 16 service imports. Audit identifies it as the system's central hotspot (4 of 6 dimensions). The `processEvent` method alone is 458 LOC. Test coverage on the runtime path is near zero — `tests/Unit/Jobs/ProcessLINEWebhookOfflineTest.php` covers only the offline-hours branch.

## Goal

Split the job into four pipeline-stage services. Behavior identical to the byte. No latency, error-handling, or DB-write-order changes. Dark-launch via feature flag.

## Constraints

- LINE OA traffic must not regress
- Rollback in seconds via env flag flip
- Existing services (`AIService`, `LINEService`, `RateLimitService`, `MessageAggregationService`, `SmartAggregationAnalyzer`, etc.) are reused unchanged
- Existing Sentry breadcrumbs and `failed_jobs` behavior preserved

## Architecture

```
ProcessLINEWebhook::handle()
  ├─ LineWebhookPipelineFlag::enabledFor($bot) ?
  │    yes → new pipeline (4 stages, sequential)
  │    no  → legacy processEvent() path (unchanged)
```

## Components

| File | Responsibility | Target LOC |
|------|----------------|------------|
| `backend/app/Services/LineWebhook/WebhookContext.php` | DTO carrying state through pipeline | 80 |
| `backend/app/Services/LineWebhook/GateDecision.php` | Enum: `ALLOW`, `RATE_LIMITED`, `OUTSIDE_HOURS` | 15 |
| `backend/app/Services/LineWebhook/ResponseEnvelope.php` | DTO `{type: 'text'\|'sticker'\|'flex', payload: mixed}` for generated reply | 30 |
| `backend/app/Services/LineWebhook/LineWebhookGatingService.php` | RateLimit + ResponseHours check, fallback dispatch | 150 |
| `backend/app/Services/LineWebhook/LineWebhookContextService.php` | Profile, conversation, user message save, aggregation decision | 250 |
| `backend/app/Services/LineWebhook/LineWebhookResponseService.php` | Branch by event type (text/sticker/image), vision pipeline as private methods | 400 |
| `backend/app/Services/LineWebhook/LineWebhookOutputService.php` | LINE push, bot Message save, stats batch, post-processing | 200 |
| `backend/app/Services/LineWebhook/LineWebhookPipelineFlag.php` | Reads `config('line_webhook.pipeline_enabled')` + bot whitelist | 30 |
| `backend/config/line_webhook.php` | New config file binding env vars | 20 |
| `backend/tests/Unit/Services/LineWebhook/*Test.php` | One test class per stage service | 500 |

## Data flow

`backend/app/Jobs/ProcessLINEWebhook.php:79` (`handle`) gains a flag branch at the top. On the new path, the four stages run sequentially with the same `WebhookContext` mutated by each.

- Stage 1 returns `GateDecision` enum. On `RATE_LIMITED` or `OUTSIDE_HOURS`, dispatch fallback inline and return.
- Stage 2 returns void; populates `ctx.conversation`, `ctx.userMessage`, `ctx.aggregationDecision`. On `BUFFERED`, return.
- Stage 3 returns void; populates `ctx.response` (`ResponseEnvelope`). `OpenRouterException` becomes the existing fallback string from `backend/app/Services/AIService.php:189` wrapped in a `ResponseEnvelope` of `type: 'text'` (no behavior change).
- Stage 4 returns void; reads `ctx.response`, dispatches via `LINEService` (text → push text, sticker → push sticker, flex → push flex), saves bot `Message`, updates stats, fires `AutoAssignmentService` / `LeadRecoveryService`.

## Error handling

Errors mirror the legacy path. Job-level `failed()` callback at `backend/app/Jobs/ProcessLINEWebhook.php:1425` unchanged. One new breadcrumb at pipeline entry: `pipeline.start { bot_id, event_type, flag_decision }`. No new try/catch, retries, timeouts, or fallback texts.

## Idempotency

LINE webhook idempotency at `backend/app/Http/Controllers/Api/LineWebhookController.php` is upstream of the job — unchanged. `messages_webhook_event_id_idx` unique constraint prevents duplicate user message inserts on retry — unchanged.

## Testing

`backend/tests/Unit/Services/LineWebhook/`:
- `LineWebhookGatingServiceTest.php` — rate limit hit, hours closed, allow
- `LineWebhookContextServiceTest.php` — new vs existing conversation, aggregation fire vs hold, LINE 404 on profile
- `LineWebhookResponseServiceTest.php` — text branch, sticker branch, image branch, `OpenRouterException` → fallback
- `LineWebhookOutputServiceTest.php` — push success → save; push 4xx → no bot Message; `MultipleBubblesService` split

`backend/tests/Unit/Jobs/ProcessLINEWebhookPipelineTest.php` — end-to-end with flag ON, one mock bot, deterministic fixtures.

Existing `tests/Feature/LINEWebhookTest.php` and `tests/Unit/Jobs/ProcessLINEWebhookOfflineTest.php` must stay green with flag OFF.

## Rollout

| Phase | Action | Verify |
|-------|--------|--------|
| 1 | Implement + tests, merge with `PROCESS_LINE_PIPELINE_ENABLED=false` | CI green; zero traffic impact |
| 2 | `PROCESS_LINE_PIPELINE_BOT_IDS=26` (test bot only) | A few LINE conversations behave identically |
| 3 | 24h soak on bot 26 | Sentry error rate / latency on bot 26 ≤ bot 28 (legacy) |
| 4 | Add bot 28 to whitelist, keep flag for fast rollback | 100% pipeline traffic, 24h Sentry watch |
| 5 | Cleanup PR — delete legacy `processEvent` + helpers + flag | -1000 LOC, `ProcessLINEWebhook.php` ≤ 200 LOC |

Rollback: `railway variables --set PROCESS_LINE_PIPELINE_ENABLED=false` — instant, no deploy.

## Env vars

| Name | Default | Purpose |
|------|---------|---------|
| `PROCESS_LINE_PIPELINE_ENABLED` | `false` | Master switch |
| `PROCESS_LINE_PIPELINE_BOT_IDS` | empty | Comma-separated bot IDs to opt in; empty = all bots when master on |

## Out of scope

- Latency / cost / cache improvements
- LLM model changes
- New error handling / retry / circuit breaker logic
- Refactoring services consumed by stages (`AIService`, `LINEService`, etc.)
- Changes to `LineWebhookController` or upstream webhook handling

## Implementation effort

| Phase | Estimate |
|-------|----------|
| 1 (implement + tests) | ~6-8h |
| 2-3 (smoke + 24h soak) | passive |
| 4-5 (ramp + cleanup) | ~2h |
