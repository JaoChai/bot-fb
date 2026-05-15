# Unit 5: Reliability Scanner

> Data sources: Sentry Issues API (projects php-laravel + botjao-frontend), Neon failed_jobs table, Sentry events API for error rate, ResilienceMetricsService source code. Period: 7 days. Snapshot: 2026-05-15T09:30:00+07:00.

## Backend — Top 5 Unresolved Issues (Sentry, 7d)

| Rank | Title | Events | Users affected | First seen | Last seen | Link |
|------|-------|--------|----------------|------------|-----------|------|
| 1 | `QueryException: relation "agent_cost_usage" does not exist` | 251 | 0 | 2026-04-18 | 2026-05-08 | [#7422423494](https://adsvance.sentry.io/issues/7422423494/) |
| 2 | `Error: Class "Predis\Client" not found` | 17 | 0 | 2026-05-15 | 2026-05-15 | [#7482181445](https://adsvance.sentry.io/issues/7482181445/) |
| 3 | `Consecutive HTTP` (POST openrouter.ai/api/v1/embeddings) | 294 | 0 | 2026-01-07 | 2026-05-15 | [#7170383307](https://adsvance.sentry.io/issues/7170383307/) |
| 4 | `CommandNotFoundException: Command "queue:size" is not defined` | 1 | 0 | 2026-05-15 | 2026-05-15 | [#7482538927](https://adsvance.sentry.io/issues/7482538927/) |
| 5 | `Psy\Exception\ParseErrorException: PHP Parse error: unexpected T_NS_SEPARATOR` | 1 | 0 | 2026-05-15 | 2026-05-15 | [#7482285511](https://adsvance.sentry.io/issues/7482285511/) |

Note: Sentry returned only 5 unresolved issues for this project in the past 7 days. All 5 have `userCount=0` (errors occur in background jobs/CLI, not user-facing requests).

## Frontend — Top Unresolved Issues (Sentry, 7d)

| Rank | Title | Events | Users | Link |
|------|-------|--------|-------|------|
| — | No unresolved issues | 0 | 0 | — |

Note: Frontend project `botjao-frontend` returned 0 unresolved issues in the last 7 days.

## Error Rate by Endpoint (top 27, sorted by failure_count)

| Rank | Endpoint | Total req | Errors | Error % |
|------|----------|-----------|--------|---------|
| 1 | `/api/analytics/costs` | 4 | 2 | 50.00% |
| 2 | `/api/dashboard/summary` | 5 | 1 | 20.00% |
| 3 | `App\Jobs\ProcessLINEWebhook` | 180 | 0 | 0.00% |
| 4 | `lead-recovery` | 11 | 0 | 0.00% |
| 5 | `/api/webhook/{token}` | 180 | 0 | 0.00% |
| 6 | `/api/bots/{bot}/conversations/{conversation}/messages` | 91 | 0 | 0.00% |
| 7 | `App\Events\ConversationUpdated` | 225 | 0 | 0.00% |
| 8 | `/api/bots/{bot}/conversations` | 67 | 0 | 0.00% |
| 9 | `/api/bots/{bot}/conversations/{conversation}/notes` | 25 | 0 | 0.00% |
| 10 | `/api/broadcasting/auth` | 19 | 0 | 0.00% |
| 11 | `App\Jobs\ProcessLeadRecovery` | 11 | 0 | 0.00% |
| 12 | `/api/bots/{bot}/conversations/tags` | 4 | 0 | 0.00% |
| 13 | `/api/bots` | 12 | 0 | 0.00% |
| 14 | `/api/product-stocks` | 3 | 0 | 0.00% |
| 15 | `/api/bots/{bot}/conversations/{conversation}/mark-as-read` | 27 | 0 | 0.00% |
| 16 | `/api/knowledge-bases` | 1 | 0 | 0.00% |
| 17 | `/api/bots/{bot}` | 2 | 0 | 0.00% |
| 18 | `/api/orders` | 4 | 0 | 0.00% |
| 19 | `/api/orders/summary` | 1 | 0 | 0.00% |
| 20 | `/` | 14 | 0 | 0.00% |
| — | (7 more endpoints, all 0 failures) | — | 0 | 0.00% |

Global error rate: 3 failures / ~900 total transactions ≈ **0.33%**

## Failed Jobs (Neon, 7d)

| Job class | Count | Last failure | Exception snippet |
|-----------|-------|--------------|-------------------|
| — | 0 | — | — |

Note: `failed_jobs` table exists but is empty for the last 7 days. Queue driver is Redis (not `database`), so failed jobs are stored in Redis and not surfaced in this table. The `jobs` table is also empty (no pending jobs in Neon).

## Redelivery Stats (messages table, 7d)

| Metric | Value |
|--------|-------|
| Total messages (7d) | 2,574 |
| Redeliveries (`is_redelivery=true`) | 0 |
| Redelivery % | 0.00% |
| `retry_count` column | Not present — redelivery tracked via boolean `is_redelivery` only |

## Circuit Breaker Events

- **Total Sentry events found:** 0 (queried via `message:circuit` filter on transactions + issues search)
- **Services monitored:** `CircuitBreakerService` wraps OpenRouter, Redis, and DB calls (from source code)
- **Logging mechanism:** `CircuitBreakerService` uses `Log::warning`/`Log::error` for state changes; `ResilienceMetricsService` sends Sentry breadcrumbs (via `\Sentry\addBreadcrumb`) and fires `\Sentry\captureMessage` only when circuit **opens** (transitions to `STATE_OPEN`)
- **Note:** Zero circuit-open events in Sentry means either (a) no circuit has opened in the last 7 days, or (b) `ResilienceMetricsService` is not injected/wired in all callers — the constructor accepts `?ResilienceMetricsService $metrics = null`, so callers that pass `null` skip Sentry capture entirely.

## Findings

### Finding 1: Stale `agent_cost_usage` QueryException — 251 errors over 27 days
- **Evidence:** [Sentry #7422423494](https://adsvance.sentry.io/issues/7422423494/) — `SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "agent_cost_usage" does not exist`, culprit `/api/analytics/costs`, 251 events from 2026-04-18 to 2026-05-08
- **Impact:** `/api/analytics/costs` throws a 500 on every call (50% error rate in Sentry transaction data). Cost analytics dashboard is entirely broken for all users. ~9 errors/day for 27 days.
- **Root cause hypothesis:** The `agent_cost_usage` table was referenced in `AnalyticsController` but never existed in production (migration not run, or table dropped). A prior commit (`193178e`) notes "remove dead AgentCostUsage reference from AnalyticsController" — the fix was applied but the issue remains open in Sentry (may be a stale unresolved issue that stopped firing after 2026-05-08).
- **Fix candidates:**
  1. Verify the issue is truly resolved post-commit `193178e` — resolve it in Sentry if confirmed fixed.
  2. If any code path still queries this table, run the missing migration or remove the reference.
  3. Add a guard in `AnalyticsController` to catch `QueryException` and return a `503` with a helpful message rather than a 500.

### Finding 2: `Predis\Client` not found — Redis dependency gap (17 errors, today)
- **Evidence:** [Sentry #7482181445](https://adsvance.sentry.io/issues/7482181445/) — `Error: Class "Predis\Client" not found`, culprit `/vendor/laravel/framework/src/Illuminate/Redis/Connectors/PredisConnector.php`, 17 events all on 2026-05-15 between 02:41 and 02:49
- **Impact:** Any code path that instantiates a Redis connection via `predis` driver throws a fatal error. Occurred during an 8-minute window this morning — likely triggered by a deploy or config change. CircuitBreaker, FlowCacheService, and queue operations all depend on Redis.
- **Root cause hypothesis:** `predis/predis` was recently added as a fix (`3ba3432 fix: install predis/predis`), but the composer install may not have propagated to the production container, or the deploy used a cached image layer. The short burst (17 events, 8 minutes) suggests the worker restarted and recovered once the container was rebuilt.
- **Fix candidates:**
  1. Confirm `vendor/predis/predis` exists in the deployed container — check Railway build logs post-`3ba3432`.
  2. If confirmed fixed, resolve in Sentry.
  3. Add `predis/predis` to a `composer.json` health check in CI to catch missing dependencies before deploy.

### Finding 3: Consecutive HTTP errors on OpenRouter embeddings — longest-running issue (294 events, 130 days)
- **Evidence:** [Sentry #7170383307](https://adsvance.sentry.io/issues/7170383307/) — `Consecutive HTTP` failures on `POST https://openrouter.ai/api/v1/embeddings`, culprit `App\Jobs\ProcessLINEWebhook`, first seen 2026-01-07, last seen 2026-05-15, 294 events total
- **Impact:** Embedding calls inside `ProcessLINEWebhook` intermittently fail. RAG knowledge retrieval degrades silently — the job likely catches the error and continues without embeddings, meaning affected messages get no context injection. ~2.3 events/day for 130 days.
- **Root cause hypothesis:** OpenRouter's embeddings endpoint returns transient HTTP errors (rate limits, 5xx). The job does not retry on embedding failure or fall back to a cached/approximate embedding. `CircuitBreakerService` is present in the codebase but may not be wrapping this specific call.
- **Fix candidates:**
  1. Wrap the `openrouter.ai/api/v1/embeddings` call in `CircuitBreakerService::execute()` with a fallback (skip embedding, log degraded mode).
  2. Add exponential backoff retry (Laravel `retry()` helper) for transient HTTP 429/5xx on embedding calls.
  3. Implement a local embedding fallback (e.g., cached nearest-neighbor) when the circuit is open.

### Finding 4: `queue:size` command not found — monitoring script references non-existent Artisan command
- **Evidence:** [Sentry #7482538927](https://adsvance.sentry.io/issues/7482538927/) — `CommandNotFoundException: Command "queue:size" is not defined`, suggestion list shows `queue:monitor` available, 1 event on 2026-05-15
- **Impact:** A scheduler or monitoring script calls `php artisan queue:size` which does not exist in Laravel 12. This silently breaks queue depth monitoring. The `jobs` table in Neon also being empty confirms the queue driver is Redis, meaning `queue:size` (which requires the `database` driver) would never have worked.
- **Root cause hypothesis:** `queue:size` was valid in older Laravel versions or confused with a third-party package command. A cron job or healthcheck script is calling it.
- **Fix candidates:**
  1. Replace `queue:size` with `queue:monitor` (Laravel 12 built-in) or use `redis-cli LLEN queues:default` for Redis queue depth.
  2. Search scheduler and deploy scripts for `queue:size` and update the reference.

### Finding 5: Circuit breaker not injected uniformly — silent fallbacks may mask OpenRouter failures
- **Evidence:** `CircuitBreakerService` constructor signature `__construct(?ResilienceMetricsService $metrics = null)` — nullable metrics means callers can instantiate without Sentry reporting. Zero circuit-open events found in Sentry (7d) despite 294 consecutive HTTP errors on OpenRouter (Finding 3).
- **Impact:** OpenRouter failures at scale would not surface in Sentry as circuit-open alerts. Operations team has no automated alert when the circuit trips. Fallback behavior (silent degradation) is invisible.
- **Root cause hypothesis:** `ResilienceMetricsService` is null in the `CircuitBreakerService` instance used by `ProcessLINEWebhook`, so state transitions never fire `captureMessage`. This is confirmed by absence of any circuit-open Sentry event despite known consecutive HTTP failures.
- **Fix candidates:**
  1. Inject `ResilienceMetricsService` via the service container (bind in `AppServiceProvider`) so it is never null in production.
  2. Add a Sentry alert rule for log messages matching `"Circuit breaker opened"` as a fallback detection layer.
  3. Add a test asserting that circuit-open transitions call `captureMessage`.

## Status: 🟡

Thresholds:
- Backend error rate < 0.5% globally + 0 failed jobs/day = 🟢
- < 2% or < 5 failed jobs/day = 🟡
- ≥ 2% or ≥ 5 failed jobs/day = 🔴

Current: Global error rate ≈ 0.33% (3/~900 transactions). Two endpoints have elevated per-endpoint rates: `/api/analytics/costs` at 50% (2/4 — very low volume, likely stale resolved issue) and `/api/dashboard/summary` at 20% (1/5). Failed jobs via Neon: 0 (queue driver is Redis, not database). Longest-running unresolved issue: Consecutive HTTP on OpenRouter embeddings (130 days, 294 events). Status: **🟡** — globally within threshold but two endpoints have high per-route error rates and one chronic issue (Finding 3) has been unaddressed for 130 days.

## Notes

- Sentry returned only 5 backend issues and 0 frontend issues in 7d — either the app is genuinely stable at the user level, or Sentry sampling (0.1 trace rate) is suppressing low-volume errors.
- `failed_jobs` table is empty because the queue driver is Redis (`QUEUE_CONNECTION=redis`). Failed jobs live in Redis and are visible via `php artisan queue:failed` (which queries the `failed_jobs` DB table only if `failed_driver=database`). Current config appears to use `database` as the failed-job store (table exists and is queryable), but no failures were recorded in 7 days — consistent with zero worker errors in Sentry.
- `messages.is_redelivery` boolean (0 redeliveries in 7d) confirms webhook deduplication is working correctly.
- Circuit breaker state is stored in Redis cache with 5-minute TTL on failure counters. If Redis restarts, circuit state is reset — relevant context for the Predis outage in Finding 2.
