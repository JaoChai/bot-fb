# Unit 1: Backend Latency

> Data source: Sentry Discover API direct calls (`curl` with Bearer token), project `php-laravel` (id: 4510638630502400), org: `adsvance`, period: last 7 days. Raw JSON archives in `/tmp/audit-unit1/` (top-by-p95.json, top-by-total-time.json, top-http-by-p95.json). Handler mappings from `php artisan route:list` on main repo backend.

---

## Data Collected

### Top Transactions by p95 (7d) — All Types

| Rank | Transaction | Op | p50 (ms) | p95 (ms) | p99 (ms) | count (sampled) | epm | Handler |
|------|------------|-----|----------|----------|----------|-----------------|-----|---------|
| 1 | `App\Jobs\ProcessAggregatedMessages` | queue.process | 3,776 | **7,310** | 9,088 | 30 | 0.003 | `app/Jobs/ProcessAggregatedMessages.php` |
| 2 | `App\Jobs\ProcessLINEWebhook` | queue.process | 340 | **5,870** | 8,065 | 179 | 0.018 | `app/Jobs/ProcessLINEWebhook.php` |
| 3 | `db:ping` | console.command.scheduled | 325 | 422 | 474 | 230 | 0.023 | Artisan scheduled command |
| 4 | `App\Jobs\SendDelayedBubbleJob` | queue.process | 221 | 296 | 302 | 7 | 0.001 | `app/Jobs/SendDelayedBubbleJob.php` |
| 5 | `App\Jobs\ProcessLeadRecovery` | queue.process | 57 | 260 | 395 | 11 | 0.001 | `app/Jobs/ProcessLeadRecovery.php` |
| 6 | `/api/analytics/costs` | http.server | 212 | **247** | 251 | 4 | 0.000 | `Api\AnalyticsController@costs` |
| 7 | `/api/bots/{bot}/conversations` | http.server | 132 | **174** | 210 | 60 | 0.006 | `Api\ConversationController@index` |
| 8 | `/api/dashboard/summary` | http.server | 157 | **172** | 174 | 5 | 0.000 | `Api\DashboardController@summary` |
| 9 | `/api/bots/{bot}` | http.server | 145 | **166** | 168 | 2 | 0.000 | `Api\BotController@show` |
| 10 | `/api/bots/{bot}/conversations/{conversation}/messages` | http.server | 110 | **157** | 235 | 87 | 0.009 | `Api\MessageController@index` |
| 11 | `/api/bots/{bot}/conversations/{conversation}/notes` | http.server | 94 | **154** | 213 | 25 | 0.002 | `Api\ConversationController@notes` |
| 12 | `/api/bots` | http.server | 129 | **150** | 156 | 12 | 0.001 | `Api\BotController@index` |
| 13 | `/api/bots/{bot}/conversations/tags` | http.server | 109 | **148** | 152 | 4 | 0.000 | `Api\ConversationController@allTags` |
| 14 | `/api/product-stocks` | http.server | 94 | **143** | 147 | 3 | 0.000 | `Api\ProductStockController@index` |
| 15 | `/api/webhook/{token}` | http.server | 104 | **138** | 255 | 179 | 0.018 | `Api\WebhookController@handle` |
| 16 | `/api/bots/{bot}/conversations/{conversation}/mark-as-read` | http.server | 116 | **135** | 151 | 24 | 0.002 | `Api\ConversationController@markAsRead` |
| 17 | `App\Events\MessageSent` | queue.process | 45 | 130 | 161 | 227 | 0.023 | `app/Events/MessageSent.php` (Reverb broadcast) |
| 18 | `/api/orders` | http.server | 114 | **121** | 122 | 4 | 0.000 | `Api\OrderController@index` |
| 19 | `/api/orders/by-product` | http.server | 115 | **120** | 120 | 4 | 0.000 | `Api\OrderController@byProduct` |
| 20 | `/api/orders/summary` | http.server | 112 | **112** | 112 | 1 | 0.000 | `Api\OrderController@summary` |
| 21 | `/api/knowledge-bases` | http.server | 100 | **100** | 100 | 1 | 0.000 | `Api\KnowledgeBaseController@index` |
| 22 | `App\Events\ConversationUpdated` | queue.process | 38 | 70 | 91 | 221 | 0.022 | `app/Events/ConversationUpdated.php` (Reverb broadcast) |
| 23 | `App\Jobs\EvaluateVipStatusJob` | queue.process | 56 | 70 | 71 | 3 | 0.000 | `app/Jobs/EvaluateVipStatusJob.php` |
| 24 | `/` | http.server | 62 | 85 | 91 | 13 | 0.001 | Root / health check |
| 25 | `/api/broadcasting/auth` | http.server | 57 | 77 | 86 | 10 | 0.001 | `Api\BroadcastController@auth` |
| 26 | `lead-recovery` | console.command.scheduled | 17 | 26 | 28 | 11 | 0.001 | Artisan scheduled command |
| 27 | `/docs` | http.server | 17 | 17 | 17 | 1 | 0.000 | API docs route |

_27 distinct transactions observed in the 7-day window. Sentry sample rate = 10% — true volume ≈ count × 10._

---

### Top Transactions by Total Time (7d)

| Rank | Transaction | Op | count (sampled) | avg (ms) | sum (s, sampled) | true est. sum (s) | Handler |
|------|------------|-----|-----------------|----------|------------------|-------------------|---------|
| 1 | `App\Jobs\ProcessLINEWebhook` | queue.process | 179 | 1,387 | **248** | ~2,480 | `app/Jobs/ProcessLINEWebhook.php` |
| 2 | `App\Jobs\ProcessAggregatedMessages` | queue.process | 30 | 3,494 | **105** | ~1,050 | `app/Jobs/ProcessAggregatedMessages.php` |
| 3 | `db:ping` | console.command.scheduled | 230 | 314 | 72 | ~720 | Artisan scheduled |
| 4 | `/api/webhook/{token}` | http.server | 179 | 108 | 19 | ~190 | `Api\WebhookController@handle` |
| 5 | `App\Events\MessageSent` | queue.process | 227 | 55 | 12 | ~120 | `app/Events/MessageSent.php` |
| 6 | `/api/bots/{bot}/conversations/{conversation}/messages` | http.server | 87 | 118 | 10 | ~100 | `Api\MessageController@index` |
| 7 | `App\Events\ConversationUpdated` | queue.process | 221 | 41 | 9 | ~90 | `app/Events/ConversationUpdated.php` |
| 8 | `/api/bots/{bot}/conversations` | http.server | 60 | 136 | 8 | ~80 | `Api\ConversationController@index` |
| 9 | `/api/bots/{bot}/conversations/{conversation}/mark-as-read` | http.server | 24 | 116 | 3 | ~30 | `Api\ConversationController@markAsRead` |
| 10 | `/api/bots/{bot}/conversations/{conversation}/notes` | http.server | 25 | 101 | 3 | ~30 | `Api\ConversationController@notes` |
| 11 | `App\Jobs\SendDelayedBubbleJob` | queue.process | 7 | 239 | 2 | ~20 | `app/Jobs/SendDelayedBubbleJob.php` |
| 12 | `/api/bots` | http.server | 12 | 132 | 2 | ~20 | `Api\BotController@index` |
| 13 | `App\Jobs\ProcessLeadRecovery` | queue.process | 11 | 86 | 1 | ~10 | `app/Jobs/ProcessLeadRecovery.php` |
| 14 | `/` | http.server | 13 | 57 | 1 | ~10 | Root/health |
| 15 | `/api/analytics/costs` | http.server | 4 | 173 | 1 | ~10 | `Api\AnalyticsController@costs` |
| 16 | `/api/dashboard/summary` | http.server | 5 | 130 | 1 | ~10 | `Api\DashboardController@summary` |
| 17 | `/api/broadcasting/auth` | http.server | 10 | 58 | 1 | ~10 | `Api\BroadcastController@auth` |
| 18 | `/api/orders` | http.server | 4 | 115 | 0.5 | ~5 | `Api\OrderController@index` |
| 19 | `/api/bots/{bot}/conversations/tags` | http.server | 4 | 113 | 0.5 | ~5 | `Api\ConversationController@allTags` |
| 20 | `/api/orders/by-product` | http.server | 4 | 108 | 0.4 | ~4 | `Api\OrderController@byProduct` |
| 21 | `/api/product-stocks` | http.server | 3 | 110 | 0.3 | ~3 | `Api\ProductStockController@index` |
| 22 | `/api/bots/{bot}` | http.server | 2 | 145 | 0.3 | ~3 | `Api\BotController@show` |
| 23 | `lead-recovery` | console.command.scheduled | 11 | 17 | 0.2 | ~2 | Artisan scheduled |
| 24 | `App\Jobs\EvaluateVipStatusJob` | queue.process | 3 | 60 | 0.2 | ~2 | `app/Jobs/EvaluateVipStatusJob.php` |
| 25 | `/api/orders/summary` | http.server | 1 | 112 | 0.1 | ~1 | `Api\OrderController@summary` |
| 26 | `/api/knowledge-bases` | http.server | 1 | 100 | 0.1 | ~1 | `Api\KnowledgeBaseController@index` |
| 27 | `/docs` | http.server | 1 | 17 | 0.02 | ~0.2 | API docs |

---

### Top HTTP Endpoints Only (transaction.op:http.server) by p95

| Rank | Endpoint | p50 (ms) | p95 (ms) | p99 (ms) | count (sampled) | epm | Controller |
|------|----------|----------|----------|----------|-----------------|-----|------------|
| 1 | `/api/analytics/costs` | 212 | **247** | 251 | 4 | 0.000 | `Api\AnalyticsController@costs` |
| 2 | `/api/bots/{bot}/conversations` | 132 | **174** | 210 | 60 | 0.006 | `Api\ConversationController@index` |
| 3 | `/api/dashboard/summary` | 157 | **172** | 174 | 5 | 0.000 | `Api\DashboardController@summary` |
| 4 | `/api/bots/{bot}` | 145 | **166** | 168 | 2 | 0.000 | `Api\BotController@show` |
| 5 | `/api/bots/{bot}/conversations/{conversation}/messages` | 110 | **157** | 235 | 87 | 0.009 | `Api\MessageController@index` |
| 6 | `/api/bots/{bot}/conversations/{conversation}/notes` | 94 | **154** | 213 | 25 | 0.002 | `Api\ConversationController@notes` |
| 7 | `/api/bots` | 129 | **150** | 156 | 12 | 0.001 | `Api\BotController@index` |
| 8 | `/api/bots/{bot}/conversations/tags` | 109 | **148** | 152 | 4 | 0.000 | `Api\ConversationController@allTags` |
| 9 | `/api/product-stocks` | 94 | **143** | 147 | 3 | 0.000 | `Api\ProductStockController@index` |
| 10 | `/api/webhook/{token}` | 104 | **138** | 255 | 179 | 0.018 | `Api\WebhookController@handle` |
| 11 | `/api/bots/{bot}/conversations/{conversation}/mark-as-read` | 116 | **135** | 151 | 24 | 0.002 | `Api\ConversationController@markAsRead` |
| 12 | `/api/orders` | 114 | **121** | 122 | 4 | 0.000 | `Api\OrderController@index` |
| 13 | `/api/orders/by-product` | 115 | **120** | 120 | 4 | 0.000 | `Api\OrderController@byProduct` |
| 14 | `/api/orders/summary` | 112 | **112** | 112 | 1 | 0.000 | `Api\OrderController@summary` |
| 15 | `/api/knowledge-bases` | 100 | **100** | 100 | 1 | 0.000 | `Api\KnowledgeBaseController@index` |
| 16 | `/` | 62 | **85** | 91 | 13 | 0.001 | Root/health |
| 17 | `/api/broadcasting/auth` | 57 | **77** | 86 | 10 | 0.001 | `Api\BroadcastController@auth` |
| 18 | `/docs` | 17 | **17** | 17 | 1 | 0.000 | API docs |

---

## Findings

### Finding 1: ProcessAggregatedMessages — Highest p95 (7,310ms), Every Execution Hits LLM

- **Evidence:** Rank 1 by p95 (7,310ms), p50=3,776ms — the median execution is also 3.8s. Sampled sum: 105s / true est. ~1,050s per 7 days. Discover URL: `https://adsvance.sentry.io/explore/discover/results/?project=4510638630502400&query=transaction%3A%22App%5CJobs%5CProcessAggregatedMessages%22&statsPeriod=7d`
- **Impact:** 30 sampled × 10 = ~300 true executions/7d, all slow (no fast-path exit). At avg 3,494ms, ~1,050s of worker time consumed/week. p95=7,310ms means 5% of LLM-awaiting users wait >7s for a reply. `timeout=150s` in the job definition confirms the LLM call is allowed to block a worker thread for up to 2.5 minutes.
- **Root cause hypothesis:** `ProcessAggregatedMessages` (459 LOC) is always dispatched to generate an AI response — there is no non-LLM path. It serializes: lock acquisition → context load → `AIService` → `OpenRouterService` (external LLM API call, ~3-7s) → LINE push delivery. The LLM call is the dominant cost; no streaming or timeout-with-fallback is implemented at the job level.
- **Fix candidates:**
  1. Add per-request OpenRouter timeout (e.g., 20s hard limit) with fallback to a faster/cheaper model — (effort 2, risk 2)
  2. Enable prompt caching on OpenRouter for repeated system prompts to cut input processing time — (effort 1, risk 1)
  3. Separate `llm` queue with dedicated workers so LLM-blocked threads don't starve non-LLM jobs — (effort 2, risk 1)

### Finding 2: ProcessLINEWebhook — p95 5,870ms, Dominates Total Queue Time (~2,480s/7d)

- **Evidence:** Rank 2 by p95 (5,870ms), Rank 1 by total time (sampled sum 248s / true ~2,480s). p50=340ms shows a healthy fast path (short-circuit: sticker, rate limit, circuit open, outside hours). p95–p50 gap of 5,530ms is the LLM slow path. Discover URL: `https://adsvance.sentry.io/explore/discover/results/?project=4510638630502400&query=transaction%3A%22App%5CJobs%5CProcessLINEWebhook%22&statsPeriod=7d`
- **Impact:** 179 sampled × 10 = ~1,790 true executions/7d. Fast path (340ms) handles most; ~5% hit the LLM path at 5,870ms. The 1,432-LOC job is the largest in the codebase and orchestrates 15+ injected services including `OpenRouterService`, `RAGService`, `CircuitBreakerService`, `MessageAggregationService`.
- **Root cause hypothesis:** The LLM path in `ProcessLINEWebhook` chains: `CircuitBreakerService` check → `DB::transaction` (conversation lock + message insert) → `AIService::generate` → OpenRouter API (3-6s) → LINE reply push. The p99=8,065ms tail suggests occasional OpenRouter API spikes compound with DB lock wait time.
- **Fix candidates:**
  1. Smart aggregation already delays AI generation to `ProcessAggregatedMessages` — verify this is active for all bots, reducing direct LLM calls in this job — (effort 1, risk 0)
  2. Add OpenRouter streaming response to begin LINE reply delivery before full response is assembled — (effort 3, risk 3)
  3. Cache the bot's flow config lookup within the job (currently loaded fresh each execution) — (effort 1, risk 0)

### Finding 3: `/api/webhook/{token}` — Highest HTTP Hit Count (179 sampled), p99 Spike to 255ms

- **Evidence:** Rank 10 by p95 (138ms) among HTTP endpoints but p99 spikes to 255ms — an 85% jump from p95. Highest count among all HTTP endpoints (179 sampled = ~1,790 true). Discover URL: `https://adsvance.sentry.io/explore/discover/results/?project=4510638630502400&query=transaction%3A%22%2Fapi%2Fwebhook%2F%7Btoken%7D%22&statsPeriod=7d`
- **Impact:** The webhook handler is the ingestion entry point for all LINE messages. p99=255ms means 1-in-100 webhook acknowledgments to LINE is delayed by 255ms. While p95=138ms is acceptable, the p99 spike risks LINE platform timeout retries (LINE resends if no 200 within 10s — not a current risk, but indicates occasional cold path).
- **Root cause hypothesis:** Handler synchronously does: bot token lookup → signature validation → event parse → `ProcessLINEWebhook::dispatch`. The p99 spike is likely DB cold connection or occasional cache miss on bot model. p50=104ms confirms the hot path is fast.
- **Fix candidates:**
  1. Cache bot model by webhook token in Redis (30s TTL) — reduces DB lookup on every webhook — (effort 1, risk 1)
  2. Verify `bots.webhook_token` has a unique index (routes lookup is via token — check Unit 2 for index presence) — (effort 0, risk 0)

### Finding 4: `/api/bots/{bot}/conversations` — Most Active API Endpoint (60 sampled = ~600/7d), p95 Healthy but p99 Tail Needs Monitoring

- **Evidence:** Rank 2 by p95 among HTTP endpoints (174ms), highest count among non-webhook HTTP (60 sampled). p99=210ms. Discover URL: `https://adsvance.sentry.io/explore/discover/results/?project=4510638630502400&query=transaction%3A%22%2Fapi%2Fbots%2F%7Bbot%7D%2Fconversations%22&statsPeriod=7d`
- **Impact:** ~86 requests/day (600/7d). Each request loads paginated conversations with 3 eager-loaded relations (`customerProfile`, `assignedUser`, `lastMessage`) plus a status counts CTE query (`ConversationQueryService::getAllCounts`). Currently 🟢 but is the most likely endpoint to degrade as conversation volume grows.
- **Root cause hypothesis:** Code inspection confirms correct eager loading (no N+1). The `getAllCounts` is cached (via `ConversationStatsService`). The p95=174ms reflects a DB paginate query with ORDER BY `last_message_at DESC` — if this column lacks an index on `(bot_id, status, last_message_at)`, the sort will be slow on large datasets.
- **Fix candidates:**
  1. Add composite index `conversations(bot_id, status, last_message_at DESC)` if not present — (effort 1, risk 0)
  2. Extend `getAllCounts` cache TTL beyond 5 minutes for high-traffic bots — (effort 1, risk 1)

### Finding 5: Queue Worker Thread Saturation — All LLM Jobs Share One Queue

- **Evidence:** Combined total time of LLM-driven queue jobs: ProcessLINEWebhook (~2,480s) + ProcessAggregatedMessages (~1,050s) + SendDelayedBubbleJob (~20s) = ~3,550s of worker time/7d, all from LLM external API calls. Non-LLM events (MessageSent avg 55ms, ConversationUpdated avg 41ms) share the same default queue. Discover URL: `https://adsvance.sentry.io/explore/discover/results/?project=4510638630502400&query=event.type%3Atransaction+transaction.op%3Aqueue.process&statsPeriod=7d`
- **Impact:** A burst of 5 concurrent LINE webhooks triggers 5 ProcessAggregatedMessages jobs, each blocking a worker for ~3.5s avg. If only 1-2 queue workers are running (Railway single backend service), non-LLM jobs (Reverb broadcasts at 41-55ms) queue behind 3.5s LLM jobs. This causes broadcast delay to the chat UI during peak load.
- **Root cause hypothesis:** Laravel queue default worker count on Railway is controlled by the `backend` service process config. Without a `llm` vs `default` queue separation, all job types compete for the same workers. The `APP_QUEUE_CONNECTION` is Redis (predis installed in PR #153) but queue worker count is not visible from Sentry data alone.
- **Fix candidates:**
  1. Define `llm` queue for ProcessLINEWebhook and ProcessAggregatedMessages; run separate worker with `--queue=llm` on Railway — (effort 2, risk 1)
  2. Add `QUEUE_WORKER_COUNT` env var and scale worker replicas on Railway backend service — (effort 1, risk 1)
  3. Set `$timeout = 30` (down from 150) on ProcessAggregatedMessages with OpenRouter hard timeout + fallback — (effort 2, risk 2)

---

## Status: 🟢

**Threshold for HTTP endpoints:**
- p95 of busiest 10 HTTP endpoints < 500ms = 🟢
- < 1500ms = 🟡
- ≥ 1500ms = 🔴

**HTTP assessment:** All 18 HTTP endpoints have p95 < 250ms. Max HTTP p95 = 247ms (`/api/analytics/costs`). No endpoint breaches 500ms. Status: **🟢**

**Queue job assessment (user-perceived chatbot response latency):**
- ProcessAggregatedMessages p95 = 7,310ms → 🔴 (if measured as user-facing response time)
- ProcessLINEWebhook p95 = 5,870ms → 🔴

Queue jobs are async (user does not block on HTTP), but they directly determine chatbot reply speed. The HTTP API layer is healthy; the async LLM pipeline is the latency bottleneck.

---

## Notes

- All count values are sampled at 10% — multiply by ~10 for true volume estimate.
- Only 27 distinct transactions appeared in the 7-day window. Low-traffic endpoints (< 1 sampled hit/day) are invisible due to sampling.
- `db:ping` at rank 3 by p95 (422ms) is a scheduled health-check Artisan command, not user-facing.
- `App\Events\MessageSent` and `App\Events\ConversationUpdated` appearing as `queue.process` are Reverb WebSocket broadcast events dispatched to the Laravel queue.
- Raw Sentry JSON responses archived at `/tmp/audit-unit1/` for reproducibility. All Discover evidence links use `statsPeriod=7d` with direct transaction filter on project `4510638630502400`.
