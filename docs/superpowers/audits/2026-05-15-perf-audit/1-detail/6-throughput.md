# Unit 6: Throughput Inspector

> Data sources: Sentry Discover API (`curl`, project `4510638630502400`), Sentry events-stats API (hourly time-series), Railway variables + Procfile, Railway backend logs. Period: 7d. Snapshot: 2026-05-15T18:30 UTC.
> Sentry sample rate = 0.1 (confirmed via `SENTRY_TRACES_SAMPLE_RATE=0.1`). All counts ×10 for true volume.

---

## Webhook Ingestion Rate (7d)

| Endpoint | Sampled count (7d) | True est. (×10) | epm (sampled) | True epm est. | Avg req/active-hr (true) | Peak hr (true) |
|----------|--------------------|-----------------|---------------|---------------|--------------------------|----------------|
| `/api/webhook/{token}` | 180 | **1,800** | 0.0179 | **0.179** | ~22.7 | **70** (2026-05-11T04 UTC) |
| (Telegram, Facebook webhooks) | 0 | 0 | — | — | — | — |

_Only one webhook endpoint observed in 7d window. Platform is LINE-only at current traffic level._

### Webhook Rate Derivation

- 7-day total true volume: 1,800 requests
- Daily average: ~257 req/day (~10.7 req/hr across all 168h)
- Active hours (non-zero): 79 of 168 hours (47% utilisation)
- Average over active hours only: ~22.7 req/hr (~0.38 req/min)
- Peak hour true estimate: 70 req (2026-05-11T04:00 UTC) = **1.17 req/min**
- Per-minute average (active hours): **0.38 req/min**

---

## Webhook Burst Profile (1h time-series, 7d)

| Metric | Value | Notes |
|--------|-------|-------|
| Total hourly buckets | 168 | 7d × 24h |
| Active hours (count > 0) | 79 | 47% of window |
| Peak hour (sampled) | 7 | 2026-05-11T04:00 UTC |
| Peak hour (true est.) | **70 req** | × 10 sample factor |
| Avg per active hour (true) | 22.7 req | |
| Avg per all hours (true) | 10.7 req | |
| **Burst factor (peak / avg active)** | **3.1×** | Moderate — not extreme |
| Peak req/min (within peak hour) | ~1.17 | Assuming uniform distribution |

**Top 10 active hours (true est.):**

| Hour (UTC) | True req est. |
|------------|---------------|
| 2026-05-11T04:00 | 70 |
| 2026-05-11T08:00 | 60 |
| 2026-05-11T15:00 | 60 |
| 2026-05-12T06:00 | 60 |
| 2026-05-11T05:00 | 50 |
| 2026-05-12T11:00 | 50 |
| 2026-05-13T10:00 | 50 |
| 2026-05-13T13:00 | 50 |
| 2026-05-08T14:00 | 40 |
| 2026-05-09T03:00 | 40 |

---

## Queue Depth

| Queue | Depth at snapshot | Notes |
|-------|-------------------|-------|
| default | n/a | Laravel Boost connects to local SQLite (127.0.0.1:6379 refused); prod Redis at `redis.railway.internal` not reachable from dev |
| high | n/a | Same — not reachable from audit context |
| llm | n/a | Queue does not exist yet (not configured in Procfile) |
| broadcasts | n/a | Not configured |
| webhooks | n/a | Defined in Procfile `--queue=webhooks,default` but depth unmeasurable |

_Production queue depth not directly measurable without Railway Redis proxy access. Inferred from Sentry job latency stats below._

---

## Job Processing Latency (7d, all queue.process transactions)

| Job | Sampled count | True est. | p50 (ms) | p95 (ms) | p99 (ms) | avg (ms) | True total time est. |
|-----|---------------|-----------|----------|----------|----------|----------|----------------------|
| `App\Events\MessageSent` | 228 | 2,280 | 44 | 130 | 160 | 55 | ~125s |
| `App\Events\ConversationUpdated` | 225 | 2,250 | 38 | 69 | 91 | 41 | ~92s |
| `App\Jobs\ProcessLINEWebhook` | 180 | **1,800** | 340 | **5,859** | **8,060** | 1,385 | **~2,493s** |
| `App\Jobs\ProcessAggregatedMessages` | 30 | **300** | 3,776 | **7,310** | **9,088** | 3,494 | **~1,048s** |
| `App\Jobs\ProcessLeadRecovery` | 11 | 110 | 57 | 260 | 395 | 86 | ~9s |
| `App\Jobs\SendDelayedBubbleJob` | 7 | 70 | 221 | 296 | 302 | 239 | ~17s |
| `App\Jobs\EvaluateVipStatusJob` | 3 | 30 | 56 | 70 | 71 | 60 | ~2s |

**Total estimated worker time (7d, all jobs):** ~3,786s  
**LLM-bound jobs share:** ProcessLINEWebhook + ProcessAggregatedMessages = ~3,541s = **93.5% of all worker time**

---

## Worker Configuration (from Procfile + Railway env)

| Process | Command | Queue(s) | Worker count | Timeout config |
|---------|---------|----------|--------------|----------------|
| `web` | `php artisan serve` | — | 1 (single process) | — |
| `worker` | `php artisan queue:work` | `webhooks,default` | **1 (no replicas)** | `--tries=3 --backoff=5 --max-jobs=1000 --max-time=3600` |
| `reverb` | `php artisan reverb:start` | — | 1 | — |
| `scheduler` | `php artisan schedule:work` | — | 1 (separate Railway service) | — |

**Key env facts:**
- `QUEUE_CONNECTION=redis` (production), Redis at `redis.railway.internal`
- `OPENROUTER_TIMEOUT=120` — LLM calls may block worker for up to **120 seconds**
- `DB_QUEUE_RETRY_AFTER=180` — jobs retried after 3 minutes
- No `QUEUE_WORKER_COUNT` env var — single worker process, no horizontal scaling
- No `llm` queue defined anywhere

---

## Worker Utilization

| Service | CPU avg | CPU peak | Memory | Replicas | Notes |
|---------|---------|----------|--------|----------|-------|
| backend (web) | n/a | n/a | n/a | 1 | Railway CPU metrics not available via CLI |
| backend (worker) | n/a | n/a | n/a | **1** | Single worker process confirmed via Procfile |
| scheduler | n/a | n/a | n/a | 1 | Separate Railway service, runs `schedule:work` |
| reverb | n/a | n/a | n/a | 1 | WebSocket server |
| Redis | n/a | n/a | n/a | 1 | `redis.railway.internal:6379` |

_Railway CPU/memory metrics not exposed via CLI or MCP. Worker count confirmed from `backend/Procfile`. No horizontal scaling configured._

**Inferred saturation from log observation:**
From Railway backend logs (2026-05-15T18:31 UTC), two back-to-back LINE webhooks processed sequentially:
- Job 1: `ProcessLINEWebhook` → 927ms DONE
- Job 2: `ProcessLINEWebhook` → 199ms DONE

These ran sequentially (not concurrently), confirming single-threaded worker. During LLM-path jobs (p95=5,859ms), the single worker is blocked for ~6s, starving `MessageSent` (40ms) and `ConversationUpdated` (18ms) broadcast events.

---

## Findings

### Finding 1: OPENROUTER_TIMEOUT=120s — LLM Can Block Single Worker for 2 Minutes
- **Evidence:** `OPENROUTER_TIMEOUT=120` confirmed in Railway backend env. `ProcessAggregatedMessages` p99=9,088ms (observed), timeout ceiling=120,000ms. Single worker process in `backend/Procfile`. Sentry: `https://adsvance.sentry.io/explore/discover/results/?project=4510638630502400&query=transaction%3A%22App%5CJobs%5CProcessAggregatedMessages%22&statsPeriod=7d`
- **Impact:** If OpenRouter is slow/unresponsive, the single queue worker is blocked for up to 120s. During that window, zero other jobs (including fast Reverb broadcasts at 40ms) can process. With ~300 true `ProcessAggregatedMessages` executions/7d, a 1% OpenRouter timeout rate = 3 complete worker stalls of up to 2 minutes each per week. Chat UI appears frozen during stalls.
- **Root cause hypothesis:**
  1. `OPENROUTER_TIMEOUT` was set conservatively high to avoid premature timeouts on slow models. No fallback or streaming is implemented.
  2. Single worker process means one slow LLM call serialises the entire queue.
- **Fix candidates:**
  1. Reduce `OPENROUTER_TIMEOUT` to 30s with a faster-model fallback on timeout — (effort 2, risk 2)
  2. Add `llm` queue and run a second dedicated worker `--queue=llm` on Railway backend — (effort 2, risk 1)
  3. Set `$timeout = 30` on `ProcessAggregatedMessages` job class — (effort 1, risk 1)

### Finding 2: Single Queue Worker — All Job Types Compete, No Priority Separation
- **Evidence:** `backend/Procfile` worker line: `php artisan queue:work --queue=webhooks,default`. No replica count. No `llm` or `broadcasts` queue. Railway logs show sequential execution. `App\Events\MessageSent` (40ms) queued behind `ProcessLINEWebhook` (p95=5,859ms) on same `default` queue.
- **Impact:** During a burst of 70 true webhooks in peak hour (2026-05-11T04:00 UTC), each triggering a `ProcessLINEWebhook` job: with single worker at avg 1,385ms/job, processing 70 jobs takes ~96s sequential. Broadcast events (`MessageSent`, `ConversationUpdated`) for early messages are delayed up to 96s behind LLM jobs. At p95=5,859ms, worst-case throughput is ~0.17 LLM jobs/sec = **6 jobs/min**. Peak hour delivers 70 webhooks → ~11 minutes to drain at p95 rate.
- **Root cause hypothesis:** Worker configuration was not updated when Redis queue was introduced (PR #153). `QUEUE_CONNECTION=redis` is live but worker process count was not scaled up to match.
- **Fix candidates:**
  1. Add `llm` queue in Procfile: `worker-llm: php artisan queue:work --queue=llm --sleep=3 --tries=3 --max-time=3600` — dispatch `ProcessLINEWebhook` + `ProcessAggregatedMessages` to `llm` queue — (effort 2, risk 1)
  2. Scale `worker` to 2 replicas in Railway (horizontal scaling) — halves queue drain time — (effort 1, risk 1)
  3. Add `broadcasts` queue with higher priority for `MessageSent`/`ConversationUpdated` — (effort 1, risk 0)

### Finding 3: LLM Jobs Consume 93.5% of All Worker Time, Masking True Throughput Ceiling
- **Evidence:** ProcessLINEWebhook true total time ~2,493s + ProcessAggregatedMessages ~1,048s = 3,541s of 3,786s total worker time (7d). Sentry: `https://adsvance.sentry.io/explore/discover/results/?project=4510638630502400&query=event.type%3Atransaction+transaction.op%3Aqueue.process&statsPeriod=7d&sort=-count`
- **Impact:** Non-LLM jobs (broadcasts, lead-recovery, VIP eval) consume only 6.5% of worker time but are fully blocked during LLM execution windows. At current traffic (~1,800 webhooks/7d = 257/day), the single worker is marginally adequate. At 3× traffic (growth scenario), the queue would chronically backlog.
- **Root cause hypothesis:** No queue separation means there is no mechanism to shed LLM load independently of broadcast load. The architecture conflates I/O-bound (LLM API) and CPU-light (Reverb dispatch) work.
- **Fix candidates:**
  1. Separate `llm` and `broadcasts` queues — LLM jobs can queue freely while broadcasts are always processed within seconds — (effort 2, risk 1)
  2. Implement circuit-breaker skip for LLM path when queue depth exceeds threshold — (effort 3, risk 2)
  3. Move Reverb broadcast events (`MessageSent`, `ConversationUpdated`) to `ShouldBroadcastNow` (synchronous) instead of queued — eliminates queue dependency for real-time UI — (effort 2, risk 2)

### Finding 4: Webhook Rate is Low but Bursty — Architecture Handles It Barely
- **Evidence:** Peak hour 70 true req (2026-05-11T04:00 UTC), burst factor 3.1×, avg active-hour rate 22.7 req. `/api/webhook/{token}` HTTP p95=138ms, p99=255ms (from Unit 1). Time-series shows 79/168 active hours.
- **Impact:** At peak 70 req/hr, each webhook dispatches `ProcessLINEWebhook` (avg 1,385ms). Single worker drains at ~43 jobs/min at p50, ~10 jobs/min at p95. Peak hour injects 70 jobs; at p50 throughput (43/min), drain time = ~1.6 min. At p95 throughput (10/min), drain time = 7 min. This means during peak bursts at p95 latency, users wait up to 7 additional minutes for AI replies beyond the processing time itself.
- **Root cause hypothesis:** Current traffic is low enough that the system survives, but the single worker has no headroom. One LLM provider slowdown during peak hour causes cascading delays.
- **Fix candidates:**
  1. Add second Railway worker replica — immediate 2× throughput — (effort 1, risk 1)
  2. Redis-backed queue depth monitoring alert (alert at depth > 20) — (effort 1, risk 0)
  3. Webhook fan-out: if MessageAggregation is active, many webhooks are absorbed into single `ProcessAggregatedMessages` calls — verify aggregation is enabled for all bots — (effort 0, risk 0)

---

## Status: 🟡

**Assessment:**

| Dimension | Value | Threshold | Status |
|-----------|-------|-----------|--------|
| Webhook HTTP p95 | 138ms | < 500ms | 🟢 |
| Job p95 (LLM path) | 5,859ms–7,310ms | < 2,000ms | 🔴 |
| Single worker | 1 process | ≥ 2 recommended | 🔴 |
| Queue depth | n/a (unmeasurable) | < 100 | — |
| Burst factor | 3.1× | < 5× | 🟢 |
| Worker saturation (inferred) | High at peak | CPU < 70% peak | 🟡 |
| OPENROUTER_TIMEOUT | 120s | ≤ 30s recommended | 🔴 |

**Overall 🟡** — HTTP ingestion is healthy; async pipeline is a latency risk at current scale and a saturation risk at 2-3× traffic growth. No current outage, but single-worker architecture has no fault tolerance for LLM provider slowdowns.

---

## Notes

- Production queue depth not measured directly — Railway Redis (`redis.railway.internal`) not accessible from audit context. Sentry job latency stats used as load proxy.
- Webhook count ×10 throughout for true volume (Sentry sample rate = 0.1, confirmed via `SENTRY_TRACES_SAMPLE_RATE=0.1` env var).
- Only LINE webhooks observed in 7d window. Telegram/Facebook endpoints either inactive or below sampling threshold.
- `backend/Procfile` `worker` process runs as a single Railway service instance — no autoscaling configured.
- Railway logs confirmed sequential job execution (single worker thread), not concurrent.
- `OPENROUTER_TIMEOUT=120` is the dominant risk factor: one OpenRouter failure = 2-minute complete worker freeze.
- Sentry evidence base URL: `https://adsvance.sentry.io/explore/discover/results/?project=4510638630502400&statsPeriod=7d`
