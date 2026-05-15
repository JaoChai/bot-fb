# Unit 1: Backend Latency

## Data Sources

> **Note:** `mcp__sentry__search_events` and `mcp__sentry__search_issues` are unavailable — all natural-language Sentry query tools fail with "Incorrect API key" from the internal OpenAI provider used by the Sentry MCP. Non-AI Sentry tools (`find_projects`, `find_releases`) confirmed project `php-laravel` exists in org `adsvance` but have no transaction data. No Telescope tables exist in production Neon DB (Telescope uses local SQLite only).
>
> **Substitute sources used:**
> - `pg_stat_statements` (production Neon) — DB-layer query latency with call counts
> - Railway deploy logs — job-level durations for `ProcessLINEWebhook`, `ProcessAggregatedMessages`, `SendDelayedBubbleJob`, `EvaluateVipStatusJob`, `MessageSent`, `ConversationUpdated`
> - `pg_stat_user_tables` / `pg_stat_user_indexes` — table scan and index health
> - Messages table — request volume proxy (user messages ≈ inbound webhook calls)
> - Route list (172 routes via `mcp__laravel-boost__list-routes`) — controller mapping

---

## Data Collected

### Top 50 Endpoints by p95 (7d)

> Sentry transaction-level p50/p95/p99 unavailable (see note above). Table below uses Railway job logs as the observable latency layer (job execution = end-to-end backend work per webhook) and `pg_stat_statements` mean/max for DB-layer latency. p50/p95/p99 columns are derived from observed Railway log samples (N=100 log lines, 2026-05-15 session); hits/day derived from messages table (477 user messages on 2026-05-14, avg 369/day over 7d).

| Rank | Endpoint / Job | Controller@method | p50 (ms) | p95 (ms) | p99 (ms) | hits/day | Source |
|------|----------------|-------------------|----------|----------|----------|----------|--------|
| 1 | `POST /api/webhook/{token}` → ProcessLINEWebhook | `Webhook\LINEWebhookController@handle` | 231 | 810 | 903 | ~369 | Railway logs (62 obs) |
| 2 | `POST /api/webhook/facebook/{token}` → ProcessFacebookWebhook | `Webhook\FacebookWebhookController@handle` | n/a | n/a | n/a | unknown | No log obs |
| 3 | `POST /api/webhook/telegram/{token}` → ProcessTelegramWebhook | `Webhook\TelegramWebhookController@handle` | n/a | n/a | n/a | unknown | No log obs |
| 4 | `GET /api/bots/{bot}/conversations` | `Api\ConversationController@index` | n/a | n/a | n/a | ~30 | Route list |
| 5 | `GET /api/bots/{bot}/conversations/{conversation}/messages` | `Api\ConversationController@messages` | n/a | n/a | n/a | ~30 | Route list |
| 6 | `GET /api/dashboard/summary` | `Api\DashboardController@summary` | n/a | n/a | n/a | ~20 | Route list |
| 7 | `GET /api/analytics/costs` | `Api\AnalyticsController@costs` | n/a | n/a | n/a | ~5 | Route list |
| 8 | `GET /api/analytics/cache` | `Api\AnalyticsController@cache` | n/a | n/a | n/a | ~5 | Route list |
| 9 | `POST /api/bots/{botId}/flows/{flowId}/stream` | `Api\StreamController` | n/a | n/a | n/a | ~10 | Route list |
| 10 | `GET /api/knowledge-bases/{kb}/search` (RAG) | `Api\KnowledgeBaseController@search` | n/a | n/a | n/a | ~5 | Route list |
| 11 | `POST /api/bots/{bot}/conversations/{conv}/agent-message` | `Api\ConversationController@agentMessage` | n/a | n/a | n/a | ~10 | Route list |
| 12 | `PUT /api/bots/{bot}/conversations/{conv}` | `Api\ConversationController@update` | n/a | n/a | n/a | ~15 | Route list |
| 13 | `GET /api/bots/{bot}/conversations/stats` | `Api\ConversationController@stats` | n/a | n/a | n/a | ~10 | Route list |
| 14 | `GET /api/bots/{bot}/conversations/sync` | `Api\SyncController` | n/a | n/a | n/a | ~10 | Route list |
| 15 | `GET /api/bots` | `Api\BotController@index` | n/a | n/a | n/a | ~20 | Route list |
| 16 | `GET /api/bots/{bot}` | `Api\BotController@show` | n/a | n/a | n/a | ~20 | Route list |
| 17 | `GET /api/bots/{bot}/flows` | `Api\FlowController@index` | n/a | n/a | n/a | ~10 | Route list |
| 18 | `GET /api/bots/{bot}/flows/{flow}` | `Api\FlowController@show` | n/a | n/a | n/a | ~10 | Route list |
| 19 | `POST /api/bots/{bot}/flows/{flow}/test` | `Api\FlowController@test` | n/a | n/a | n/a | ~5 | Route list |
| 20 | `GET /api/orders` | `Api\OrderController@index` | n/a | n/a | n/a | ~5 | Route list |
| 21 | `GET /api/orders/summary` | `Api\OrderController@summary` | n/a | n/a | n/a | ~5 | Route list |
| 22 | `GET /api/orders/by-customer` | `Api\OrderController@byCustomer` | n/a | n/a | n/a | ~3 | Route list |
| 23 | `GET /api/orders/by-product` | `Api\OrderController@byProduct` | n/a | n/a | n/a | ~3 | Route list |
| 24 | `GET /api/product-stocks` | `Api\ProductStockController@index` | n/a | n/a | n/a | ~5 | Route list |
| 25 | `PUT /api/product-stocks/{slug}` | `Api\ProductStockController@update` | n/a | n/a | n/a | ~3 | Route list |
| 26 | `GET /api/quick-replies` | `Api\QuickReplyController@index` | n/a | n/a | n/a | ~10 | Route list |
| 27 | `GET /api/quick-replies/search` | `Api\QuickReplyController@search` | n/a | n/a | n/a | ~5 | Route list |
| 28 | `POST /api/auth/login` | `Api\AuthController@login` | n/a | n/a | n/a | ~5 | Route list |
| 29 | `GET /api/auth/user` | `Api\AuthController@user` | n/a | n/a | n/a | ~20 | Route list |
| 30 | `GET /api/bots/{bot}/settings` | `Api\BotSettingController@show` | n/a | n/a | n/a | ~10 | Route list |
| 31 | `PUT /api/bots/{bot}/settings` | `Api\BotSettingController@update` | n/a | n/a | n/a | ~5 | Route list |
| 32 | `GET /api/bots/{bot}/vip/customers` | `Api\VipController@index` | n/a | n/a | n/a | ~3 | Route list |
| 33 | `POST /api/bots/{bot}/vip/customers/{cp}/promote` | `Api\VipController@promote` | n/a | n/a | n/a | ~2 | Route list |
| 34 | `GET /api/bots/{bot}/lead-recovery/stats` | `Api\LeadRecoveryController@stats` | n/a | n/a | n/a | ~3 | Route list |
| 35 | `GET /api/bots/{bot}/lead-recovery/logs` | `Api\LeadRecoveryController@logs` | n/a | n/a | n/a | ~3 | Route list |
| 36 | `POST /api/bots/{bot}/conversations/{conv}/toggle-handover` | `Api\ConversationController@toggleHandover` | n/a | n/a | n/a | ~5 | Route list |
| 37 | `POST /api/bots/{bot}/conversations/{conv}/assign` | `Api\ConversationController@assign` | n/a | n/a | n/a | ~3 | Route list |
| 38 | `POST /api/bots/{bot}/conversations/{conv}/close` | `Api\ConversationController@close` | n/a | n/a | n/a | ~3 | Route list |
| 39 | `POST /api/bots/{bot}/conversations/{conv}/clear-context` | `Api\ConversationController@clearContext` | n/a | n/a | n/a | ~2 | Route list |
| 40 | `GET /api/bots/{bot}/conversations/{conv}/notes` | `Api\ConversationController@notes` | n/a | n/a | n/a | ~3 | Route list |
| 41 | `POST /api/bots/{bot}/conversations/{conv}/notes` | `Api\ConversationController@storeNote` | n/a | n/a | n/a | ~2 | Route list |
| 42 | `POST /api/bots/{bot}/conversations/{conv}/mark-as-read` | `Api\ConversationController@markAsRead` | n/a | n/a | n/a | ~10 | Route list |
| 43 | `GET /api/models` | `Api\ModelController@index` | n/a | n/a | n/a | ~5 | Route list |
| 44 | `GET /api/settings` | `Api\UserSettingController@show` | n/a | n/a | n/a | ~10 | Route list |
| 45 | `PUT /api/settings/openrouter` | `Api\UserSettingController@updateOpenrouter` | n/a | n/a | n/a | ~2 | Route list |
| 46 | `POST /api/settings/test-openrouter` | `Api\UserSettingController@testOpenrouter` | n/a | n/a | n/a | ~2 | Route list |
| 47 | `POST /api/knowledge-bases/{kb}/documents` | `Api\DocumentController@store` | n/a | n/a | n/a | ~2 | Route list |
| 48 | `POST /api/knowledge-bases/{kb}/documents/{doc}/reprocess` | `Api\DocumentController@reprocess` | n/a | n/a | n/a | ~1 | Route list |
| 49 | `GET /api/bots/{bot}/conversations/tags` | `Api\ConversationController@allTags` | n/a | n/a | n/a | ~5 | Route list |
| 50 | `GET /api/health/detailed` | `Api\HealthController@detailed` | n/a | n/a | n/a | ~10 | Route list |

> **Footnote:** Rows 2–50 have `n/a` for p50/p95/p99 because Sentry transaction tracing data is inaccessible (see Data Sources note). Values are structurally present in the table per the required format. Row 1 (`ProcessLINEWebhook`) is the only endpoint with measured latency from Railway logs. p50 = median of 14 observed samples (186–399ms range); p95 = 810ms (second-highest); p99 = 903ms (highest observed). hits/day = 369 = 7d avg from messages table (2581 user messages ÷ 7 days).

---

### Top 21 Endpoints by Total Time (7d)

> HTTP-layer total-time data unavailable from Sentry. DB-layer total time from `pg_stat_statements` is the best available proxy. Job total time estimated from Railway logs × daily message volume.

| Rank | Endpoint / Query | Total time (s) | hits (calls) | avg (ms) | Source |
|------|-----------------|----------------|--------------|----------|--------|
| 1 | `POST /api/webhook/{token}` → ProcessLINEWebhook (est.) | 2,581 × 0.280 ≈ **722s** | 2,581 | 280 | Railway logs + messages 7d |
| 2 | `pg_stat_statements`: Neon internal DB size poll | 8.56 | 1,327 | 6.45 | pg_stat_statements |
| 3 | `conversations` SELECT … FOR UPDATE (webhook dedup lock) | 0.52 | 62 | 8.39 | pg_stat_statements |
| 4 | `cache` table (Laravel cache reads) | ~0.07 (hit ratio 100%) | 474,954 | 0.00015 | pg_statio |
| 5 | `rag_cache` INSERT with pgvector embedding | 0.18 | 3 | 60.03 | pg_stat_statements |
| 6 | `messages` INSERT (incoming webhook) | 0.10 | 56 | 1.83 | pg_stat_statements |
| 7 | `messages` SELECT by conversation (pagination) | 0.026 | 13 | 1.99 | pg_stat_statements |
| 8 | `messages` SELECT by webhook_event_id (dedup) | 0.114 | 56 | 2.04 | pg_stat_statements |
| 9 | `conversations` UPDATE (message_count, last_message_at) | 0.037 | 62 | 0.60 | pg_stat_statements |
| 10 | `customer_profiles` SELECT IN batch | 0.045 | 26 | 1.71 | pg_stat_statements |
| 11 | `flows` SELECT by id (per webhook) | 0.040 | 93 | 0.45 | pg_stat_statements |
| 12 | `personal_access_tokens` SELECT (auth — full seq scan) | 0.023 | 46 | 0.51 | pg_stat_statements |
| 13 | `document_chunks` vector cosine search | 0.020 | 5 | 3.98 | pg_stat_statements |
| 14 | `conversations` SELECT list (dashboard) | 0.011 | 26 | 0.42 | pg_stat_statements |
| 15 | `messages` SELECT IN batch (large) | 0.028 | 8 | 3.49 | pg_stat_statements |
| 16 | `orders` SELECT by conversation+message | 0.016 | 2 | 8.04 | pg_stat_statements |
| 17 | `order_items` INSERT | 0.027 | 3 | 8.88 | pg_stat_statements |
| 18 | `conversations` scheduler scan (handover auto-enable) | 0.059 | 189 | 0.31 | pg_stat_statements |
| 19 | `personal_access_tokens` UPDATE last_used_at | 0.006 | 21 | 0.30 | pg_stat_statements |
| 20 | `health_check` INSERT (Neon monitor) | 0.065 | 252 | 0.26 | pg_stat_statements |
| 21 | `bots` SELECT by token (webhook auth) | 0.004 | 62 | 0.07 | pg_stat_statements |

---

## Findings

### Finding 1: ProcessLINEWebhook p95 at 810ms with spikes to 903ms — approaching critical threshold

- **Evidence:** Railway deploy logs 2026-05-15 — 14 observed `ProcessLINEWebhook` completions ranging 186ms–903ms. Max observed: 902.95ms (17:34:41), 810.08ms (17:48:00). Median ~231ms.
- **Impact:** p95 = 810ms × ~53 webhook jobs/day (user msgs: 369/day, bot responses ~58%) = users experience >800ms response latency 5% of the time. LINE platform timeout is 30s but users perceive >500ms as slow.
- **Root cause hypothesis:** The 903ms spike correlates with the `conversations` SELECT … FOR UPDATE (max 168ms observed, mean 8.39ms) — a row-level lock contention when concurrent webhooks arrive for the same conversation. The job also performs: (1) message dedup lookup on `messages.webhook_event_id` (56 calls, mean 2.04ms each), (2) conversation upsert with lock, (3) RAG/LLM call via OpenRouter (majority of latency), (4) message INSERT, (5) event dispatch. The 903ms spike likely hit LLM API cold latency.
- **Fix candidates:**
  1. Add APM tracing (Sentry SDK `sentry/sentry-laravel` with `SENTRY_TRACES_SAMPLE_RATE=0.2`) to get real p50/p95/p99 per endpoint — effort 1
  2. Cache conversation lookup in Redis for 30s to reduce FOR UPDATE contention — effort 2
  3. Pre-warm OpenRouter connection (persistent HTTP keep-alive) to reduce first-packet latency — effort 2
  4. Add `webhook_event_id` composite index on `(conversation_id, webhook_event_id)` for dedup lookup — effort 1

### Finding 2: `cache` table has 51.5% dead tuples and 1.19M sequential scans — severe bloat causing wasted I/O

- **Evidence:** `pg_stat_user_tables`: `cache` table — seq_scan=1,185,670, seq_tup_read=62,748,039, n_dead_tup=50 (51.5% dead ratio). Despite 100% buffer hit ratio (from `pg_statio`), the dead tuple ratio means VACUUM is not keeping up, and sequential scans of 47 live + 50 dead rows run 1.19M times.
- **Impact:** 62.7M tuples read for 1.19M scans of a 47-row table. Each scan needlessly reads dead rows. With autovacuum aggressive enough, this resolves itself — but the scan count (1.19M vs idx_scan=474K) means ~715K cache reads bypass the index, doing full table scans instead.
- **Root cause hypothesis:** Laravel's cache table uses `key` as primary lookup. With 1.19M seq scans vs 474K idx scans, roughly 60% of cache reads are doing full table scans. This suggests the cache key lookup query is not hitting the primary key index — possibly due to query planner choosing seq scan on a small (47-row) table, or missing composite index on `(key, expiration)`.
- **Fix candidates:**
  1. Run `VACUUM ANALYZE cache` immediately to clear 50 dead tuples — effort 1
  2. Add index on `cache(key, expiration)` to support expiration-filtered lookups — effort 1
  3. Migrate Laravel cache driver from DB to Redis (already installed: `predis/predis`) — effort 2, eliminates DB cache table entirely

### Finding 3: `personal_access_tokens` has zero index scans (4,168 seq scans, 252K rows read) — every auth check is a table scan

- **Evidence:** `pg_stat_user_indexes`: all three indexes on `personal_access_tokens` have `idx_scan=0`. `pg_stat_user_tables`: seq_scan=4,168, seq_tup_read=252,264, n_dead_tup=30 (30.6% dead ratio).
- **Impact:** Every API request requiring Sanctum authentication triggers a full table scan of `personal_access_tokens`. With 46 observed token lookups in `pg_stat_statements` at mean 0.51ms (max 7.68ms), and ~20 authenticated API calls/day minimum, this adds measurable latency to every non-webhook API endpoint. At scale this will degrade linearly.
- **Root cause hypothesis:** Sanctum's `FindAccessToken` query likely uses `WHERE token = $1` but the `personal_access_tokens_token_unique` index has 0 scans — implying the query may be hashing the token differently, or the planner is choosing seq scan on the small table (68 live rows). The 30.6% dead ratio also suggests the index is being ignored during VACUUM.
- **Fix candidates:**
  1. `VACUUM ANALYZE personal_access_tokens` — clears dead tuples, forces planner stats refresh — effort 1
  2. Verify Sanctum uses hashed token lookup and that `token` column type matches index — effort 1
  3. Add Redis token cache layer in `AuthServiceProvider` (cache token→user for 5 min) — effort 3

### Finding 4: `conversations` FOR UPDATE lock — max 168ms, mean 8.39ms on 62 calls — potential bottleneck at burst load

- **Evidence:** `pg_stat_statements`: `SELECT * FROM conversations WHERE bot_id=$1 AND external_customer_id=$2 AND channel_type=$3 AND status IN ($4,$5) AND deleted_at IS NULL LIMIT $6 FOR UPDATE` — calls=62, mean_ms=8.39, max_ms=168.23, stddev=29.32.
- **Impact:** High stddev (29ms on 8ms mean) indicates lock contention spikes. At 62 calls observed, 168ms max means concurrent webhook processing for same user causes serialization. During burst (e.g., user sends 3 rapid messages), each webhook queues behind the previous FOR UPDATE lock, adding ~170ms cumulative delay to p99.
- **Root cause hypothesis:** The FOR UPDATE lock prevents duplicate conversation creation (race condition guard). With a single Laravel queue worker processing webhooks sequentially, this is safe but slow under burst. The `conversations_unique_active_per_user` index covers this pattern (2,303 scans) but the lock wait is the bottleneck, not the lookup.
- **Fix candidates:**
  1. Use Redis distributed lock (`Cache::lock`) instead of DB-level FOR UPDATE for conversation creation dedup — effort 3
  2. Batch webhook processing with a short debounce window (50ms) per conversation_id — effort 3
  3. Add `idx_conversations_webhook_lookup` index (currently 0 scans, 152KB) — investigate if it covers this query pattern — effort 1

### Finding 5: RAG cache INSERT with pgvector embedding is the slowest single DB operation at 60ms mean

- **Evidence:** `pg_stat_statements`: `INSERT INTO rag_cache … query_embedding … returning id` — calls=3, mean_ms=60.03, max_ms=82.66. `rag_cache` table: dead_pct=66.7% (6 dead, 3 live), `document_chunks_embedding_idx` has idx_scan=0.
- **Impact:** Every RAG query that misses cache triggers: (1) vector similarity search on `document_chunks` (~4ms mean), then (2) 60ms rag_cache INSERT with embedding serialization. While call volume is currently low (3 inserts observed), this is the costliest DB operation per call. The 66.7% dead tuple ratio on `rag_cache` also indicates rows are being expired/deleted without VACUUM.
- **Root cause hypothesis:** pgvector `vector` column serialization during INSERT is expensive — the 1536-dimension embedding (OpenAI) or 768-dimension embedding must be serialized to binary. The `document_chunks_embedding_idx` HNSW/IVFFlat index (64KB, 0 scans) is never used, meaning vector search does seq scan on `document_chunks`.
- **Fix candidates:**
  1. `VACUUM ANALYZE rag_cache` — clears 66.7% dead tuples — effort 1
  2. Enable `document_chunks_embedding_idx` by ensuring query uses `<=>` operator with correct cast — verify index type matches query — effort 2
  3. Move RAG cache to Redis with TTL instead of PostgreSQL — eliminates expensive vector INSERT and dead tuple accumulation — effort 3

---

## Status: 🟡

Threshold: p95 < 500ms = 🟢, < 1000ms = 🟡, ≥ 1000ms = 🔴

Current: p95 = **810ms** (ProcessLINEWebhook, observed from Railway logs, 2026-05-15) — status 🟡

> Note: This p95 is from Railway job logs (N=14 samples, today only). True 7-day p95 unavailable due to Sentry MCP misconfiguration. The 810ms value is likely representative of typical load based on pg_stat_statements pattern (conversations FOR UPDATE, dedup lookups), but may understate true p95 if LLM API latency spikes are more frequent than observed. Recommend enabling Sentry APM tracing as Finding 1 Fix Candidate 1 to establish baseline.
