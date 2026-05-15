# Performance Phase 1 Design — 2026-05-15

## Problem Statement

Five issues from the 2026-05-15 performance audit qualify as Phase 1 (score ≥ 16, combined effort ≤ 5.5 days, covering 6 dimensions). The audit found chronic gaps: dashboard endpoints partially broken since `agent_cost_usage` was dropped 2026-04-18, circuit breaker events silently dropped due to nullable dependency injection, dead `CostTrackingService.php` would throw at runtime if called, frontend LCP 4.1s blocks every page load, and a single backend worker with 120s OpenRouter timeout creates a 2-minute freeze bomb.

Reference audit: `docs/superpowers/audits/2026-05-15-perf-audit/0-summary.md` and `2-priority-list.md`.

## Selected Items

### Item 1: Fix /api/analytics/costs + /api/dashboard/summary error rates
- **Baseline:** 50% error rate on `/api/analytics/costs` (2/4 requests), 20% on `/api/dashboard/summary` (1/5). Source: Unit 5 Finding.
- **Target:** < 1% error rate on both endpoints (matches global rate baseline).
- **Dimensions:** Reliability, Cost (analytics UI restored)
- **Evidence URL:** Sentry Discover, project=4510638630502400, query `event.type:transaction transaction:/api/analytics/costs` 7d

### Item 2: Restore CircuitBreaker → Sentry visibility
- **Baseline:** 0 CircuitBreaker events visible in Sentry over 7d. `ResilienceMetricsService` is nullable in constructor → real-world CB open events get dropped. Source: Unit 5 Finding.
- **Target:** CB open/close events appear as Sentry breadcrumbs/logs within 24h of deploy.
- **Dimensions:** Reliability (observability)

### Item 3: Remove dead CostTrackingService
- **Baseline:** File exists at `backend/app/Services/CostTrackingService.php`, references dropped `agent_cost_usage` table. Will throw at runtime if called. Source: Unit 3, Unit 7.
- **Target:** File deleted, no remaining `use App\Services\CostTrackingService` imports, `OpenRouterService.php` already stores cost on `messages` row (line 609) so no functional gap.
- **Dimensions:** Code Quality, Cost (eliminates silent-failure surface)

### Item 4: Frontend static HTML skeleton
- **Baseline:** LCP = FCP = 4.1-4.2s on all 3 measured pages (`/`, `/login`, `/dashboard`). Source: Unit 4 Lighthouse runs.
- **Target:** FCP < 1.5s on all 3 pages (visible content before JS parse). LCP still bottlenecked until Phase 2 code splitting.
- **Dimensions:** UX (Frontend), perceived performance

### Item 5: Split llm queue from default + add 2nd worker
- **Baseline:** 1 backend worker process, all jobs on `default` queue, LLM jobs = 93.5% of worker time. `OPENROUTER_TIMEOUT=120s` × 1 worker = entire pipeline can freeze for 120s on a single bad upstream call. Source: Unit 6 Finding.
- **Target:** Non-LLM jobs (Reverb broadcasts, lead recovery, sticker reply, etc.) processed in < 2s p95 even under LLM load.
- **Dimensions:** Throughput, Latency, Reliability

## Architecture Changes

```
Before:
┌─────────────────┐    ┌──────────────┐
│ Webhook ingest  │───▶│ Redis queue  │
└─────────────────┘    │  "default"   │
                       └──────┬───────┘
                              │
                       ┌──────▼───────┐
                       │ Worker × 1   │
                       │ (all jobs)   │
                       └──────────────┘

After Phase 1:
┌─────────────────┐    ┌──────────────┐
│ Webhook ingest  │───▶│ Redis "llm"  │───┐
└─────────────────┘    │ (slow jobs)  │   │
                       └──────────────┘   │
                       ┌──────────────┐   ├──▶ Worker A (LLM, 1 process)
                       │ Redis        │   │    --queue=llm
                       │ "default"    │───┤    --timeout=130
                       │ (fast jobs)  │   │
                       └──────────────┘   └──▶ Worker B (default, 1 process)
                                               --queue=default,broadcasts
                                               --timeout=30
```

## Implementation Steps

### Item 1: Analytics/Dashboard error fix

1. Grep for orphan `AgentCostUsage` references in `backend/app/`:
   ```
   grep -rn "AgentCostUsage" backend/app/
   ```
2. Open `backend/app/Http/Controllers/Api/AnalyticsController.php@costs` — replace any `AgentCostUsage::*` query with equivalent on `messages` table grouping by `model_used`, summing `cost`. Pattern from `CostTrackingService`'s former queries.
3. Open `backend/app/Http/Controllers/Api/DashboardController.php@summary` — same audit/fix.
4. Add Pest test `backend/tests/Feature/Api/AnalyticsCostsTest.php` asserting 200 OK + valid JSON shape on the endpoint.
5. Add Pest test `backend/tests/Feature/Api/DashboardSummaryTest.php` likewise.
6. Run `cd backend && php artisan test --filter=AnalyticsCosts --filter=DashboardSummary` — expect pass.

### Item 2: CircuitBreaker Sentry visibility

1. Open `backend/app/Services/CircuitBreakerService.php` — locate where `ResilienceMetricsService` is injected via constructor.
2. Change constructor signature from `?ResilienceMetricsService $metrics = null` to `ResilienceMetricsService $metrics` (required).
3. In `app/Providers/AppServiceProvider.php` (or wherever DI is wired), ensure `ResilienceMetricsService` is always registered (singleton).
4. Verify `ResilienceMetricsService` emits Sentry events on circuit open/close (search for `Sentry\captureMessage` or `Log::channel('sentry')` inside the service).
5. If Sentry emission missing, add it in the service's `recordCircuitOpen()` / `recordCircuitClose()` methods.
6. Add Pest unit test `backend/tests/Unit/ResilienceMetricsServiceTest.php` that mocks Sentry SDK and asserts capture is called when circuit opens.
7. Deploy → wait 24h → verify Sentry shows at least one CB event tag.

### Item 3: Remove dead CostTrackingService

1. Confirm no callers:
   ```
   grep -rn "CostTrackingService" backend/ --include="*.php"
   ```
   Expect: only the file itself + maybe service container binding (also remove).
2. Delete `backend/app/Services/CostTrackingService.php`.
3. Remove any binding from `app/Providers/AppServiceProvider.php`.
4. Run `cd backend && vendor/bin/pint --test` and `php artisan test` to confirm no regressions.

### Item 4: Frontend static HTML skeleton

1. Edit `frontend/index.html` — inside `<body>` before `<div id="root"></div>`, add inline-styled skeleton:
   ```html
   <div id="root">
     <div style="min-height: 100vh; display: flex; flex-direction: column; background: #f9fafb;">
       <header style="height: 56px; background: #fff; border-bottom: 1px solid #e5e7eb;"></header>
       <main style="flex: 1; padding: 24px;">
         <div style="height: 32px; width: 200px; background: #e5e7eb; border-radius: 4px; margin-bottom: 16px;"></div>
         <div style="height: 200px; background: #fff; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);"></div>
       </main>
     </div>
   </div>
   ```
2. Confirm React's `createRoot(document.getElementById('root'))` still works (it replaces the children — verified by Vite default behavior).
3. Test locally `cd frontend && npm run dev` — page loads with skeleton, then React swaps in real content.
4. Re-run Lighthouse to confirm FCP drops:
   ```
   cd frontend && npx lighthouse https://www.botjao.com/ --output=json --only-categories=performance --chrome-flags="--headless"
   ```
   Compare FCP value to baseline 4.1s.

### Item 5: LLM queue separation + 2nd worker

1. Open `backend/app/Jobs/` — for each LLM-bound job (`ProcessAggregatedMessages`, `ProcessLINEWebhook`, `ProcessLeadRecovery`, anything calling `OpenRouterService`):
   ```php
   public $queue = 'llm';
   public $timeout = 130;
   ```
   Or override via `onQueue('llm')` at dispatch sites.
2. Open `backend/config/queue.php` — verify `connections.redis.queue` defaults to `default`, no schema change needed (queue name passed per-job).
3. Update Railway config to run 2 worker services:
   - **Existing `scheduler` service:** change `Procfile` or start command to `php artisan queue:work redis --queue=default,broadcasts --tries=3 --max-time=3600 --timeout=30`
   - **New `worker-llm` service:** create Railway service with start command `php artisan queue:work redis --queue=llm --tries=3 --max-time=3600 --timeout=130`. Same Docker image as backend, copy env vars.
4. Update `backend/Procfile` if present — split worker line into two.
5. Deploy → verify in Railway logs both workers are running, pulling from different queues.
6. Send test webhook → confirm broadcasts arrive immediately (not blocked by LLM jobs).
7. Sentry: query `transaction.op:queue.process` filtered by queue tag — verify `llm` jobs and `default` jobs are isolated.

## Monitoring Plan

| Metric | Source | Alert threshold | Rollback trigger |
|--------|--------|-----------------|------------------|
| `/api/analytics/costs` error rate | Sentry events API, 1h window | > 5% | > 20% sustained 30min |
| `/api/dashboard/summary` error rate | Sentry events API, 1h window | > 5% | > 20% sustained 30min |
| Sentry CB events present | Sentry Issues API, daily | 0 events in 24h after deploy | Same |
| LCP on `/` | Lighthouse weekly CI run | > 3.5s | > 4.5s (worse than baseline) |
| FCP on `/` | Lighthouse weekly CI run | > 2.0s | > 4.0s |
| `default` queue p95 job time | Sentry events, 1h | > 2000ms | > 5000ms |
| `llm` queue depth | Redis `LLEN queues:llm` via cron | > 50 jobs | > 200 sustained 10min |
| `worker-llm` process alive | Railway healthcheck | down 2min | down 5min |

## Feature Flag Strategy

Items 1, 2, 3, 4 do not need flags (low-risk fixes / restoration of broken state).

Item 5 (queue split) uses a gradual rollout via env var:
- `QUEUE_LLM_SPLIT_ENABLED=false` (default) → all jobs use `default` queue (current behavior)
- `QUEUE_LLM_SPLIT_ENABLED=true` → LLM jobs dispatched to `llm` queue

Dispatch site reads this:
```php
$queue = config('queue.llm_split_enabled') ? 'llm' : 'default';
ProcessAggregatedMessages::dispatch(...)->onQueue($queue);
```

After 24h of stable observation on staging, flip to `true` on production.

## Verification (post-deploy + 1 week)

- [ ] `/api/analytics/costs` returns 200 with valid JSON in 99%+ of requests (Sentry weekly stats)
- [ ] `/api/dashboard/summary` returns 200 with valid JSON in 99%+ of requests
- [ ] CB events appear in Sentry Issues or breadcrumbs (at least 1 in 24h after deploy, given chronic embedding failures already in audit)
- [ ] FCP on `https://www.botjao.com/` < 2.0s (target: < 1.5s)
- [ ] LCP on the same URL ≤ 4.2s (no regression from baseline — full LCP win is Phase 2)
- [ ] `default` queue p95 job time < 2s when LLM jobs are running concurrently
- [ ] No cost regression: weekly OpenRouter spend within ±10% of $10.56/week baseline
- [ ] No new Sentry issues with `> 50 events/week` introduced by changes
- [ ] No regressions in HTTP endpoint p95 from Task 1 baseline

## Rollback Procedure

| Item | Rollback |
|------|----------|
| Item 1 (analytics/dashboard fix) | `git revert <commit>` — endpoints revert to prior orphan state (was broken anyway) |
| Item 2 (CB Sentry) | `git revert` — `ResilienceMetricsService` back to nullable; no harm |
| Item 3 (dead service) | `git revert` — file restored (was unused) |
| Item 4 (static skeleton) | `git revert frontend/index.html` |
| Item 5 (queue split) | Set `QUEUE_LLM_SPLIT_ENABLED=false` in Railway env → restart workers. No code revert needed. To fully remove `worker-llm` service: delete in Railway UI (kept dormant during flag-off has no cost beyond compute) |

## Out of Scope

Phase 2 backlog (see `2-priority-list.md`):
- Item 3 from priority list — frontend React.lazy code splitting (full LCP win)
- Item 9 — ProcessLINEWebhook.php refactor (1432 LOC → multiple services)
- Item 7 — drop 60 unused indexes
- Item 10 — OpenRouter timeout reduction + fallback model
- Items 11-14 — DB cleanup tasks (VACUUM FULL, covering indexes, cache table investigation, model strategy)
- Item 15 — react-hooks ESLint warnings

## Estimated Effort Breakdown

| Item | Hours | Risk-adjusted hours |
|------|-------|---------------------|
| Item 1 fix endpoints | 4 | 6 |
| Item 2 CB Sentry | 4 | 6 |
| Item 3 remove dead service | 2 | 2 |
| Item 4 static skeleton | 4 | 4 |
| Item 5 queue split + worker | 12 | 16 |
| **Phase 1 total** | **26** | **34 (~4.5 days)** |
