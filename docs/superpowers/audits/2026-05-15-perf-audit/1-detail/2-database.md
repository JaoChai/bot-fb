# Unit 2: Database Profiler

> Data source: production Neon (project `solitary-math-34010034`, db `neondb`) via mcp__neon__run_sql. Snapshot taken: 2026-05-15T00:00:00Z.

## Data Collected

### Top 20 Slow Queries (pg_stat_statements, by total_exec_time)

Note: Ranks 1–10 are Neon-internal monitoring/exporter queries (pg_catalog, pg_stat_replication, neon_perf_counters). Ranks 11–20 include application queries. Application queries are marked **[APP]**.

| Rank | Query | Calls | Total (s) | Mean (ms) | Stddev (ms) | Rows |
|------|-------|-------|-----------|-----------|-------------|------|
| 1 | `SELECT pg_catalog.sum(pg_database_size(datname)) FROM pg_catalog.pg_database` [Neon internal] | 1,495 | 9.62 | 6.43 | 2.35 | 1,495 |
| 2 | `SELECT pg_database_size(datname), deadl... FROM pg_stat_database` [Neon internal] | 1,495 | 5.18 | 3.46 | 2.12 | 1,495 |
| 3 | `SELECT pg_database_size(datname) WHERE datname IN (...)` [Neon internal] | 1,495 | 5.14 | 3.44 | 1.61 | 1,495 |
| 4 | `SELECT state, state_change FROM pg_stat_activity WHERE backend_type = $2` [Neon internal] | 25,491 | 2.36 | 0.09 | 0.04 | 37,429 |
| 5 | `select count(*) from pg_stat_replication where application_name != $1` [Neon internal] | 25,491 | 1.49 | 0.06 | 0.01 | 25,491 |
| 6 | `SELECT x::text AS dur, working_set_size_seconds(...)` [Neon internal] | 855 | 1.30 | 1.52 | 0.22 | 51,300 |
| 7 | `select count(*) from pg_stat_activity where backend_type = $1` [Neon internal] | 25,491 | 1.29 | 0.05 | 0.01 | 25,491 |
| 8 | `SELECT setting FROM pg_settings WHERE name = $1 ... snapshot files` [Neon internal] | 1,495 | 1.02 | 0.68 | 0.11 | 1,495 |
| 9 | `SELECT setting::int4 AS max_cluster_size FROM pg_settings WHERE name = $1` [Neon internal] | 1,495 | 0.98 | 0.65 | 0.11 | 1,495 |
| 10 | `SELECT COALESCE(sum(active_time), $1) AS total_active_time FROM pg_stat_activity` [Neon internal] | 25,491 | 0.61 | 0.02 | 0.01 | 25,491 |
| 11 | **[APP]** `SELECT COUNT(*) ... COUNT(*) FILTER (WHERE created_at > NOW() - INTERVAL $1) AS last_7d ... FROM messages` | 1 | 0.53 | 526.26 | 0.00 | 1 |
| 12 | **[APP]** `select * from "conversations" where "bot_id" = $1 and "external_customer_id" = $2 and "channel_type" = $3 and "status" in ($4, $5) and deleted_at is null limit $6 for update` | 81 | 0.52 | 6.43 | 25.89 | 81 |
| 13 | `SELECT bucket_le, value FROM neon.neon_perf_counters WHERE metric = $1` [Neon internal] | 5,980 | 0.47 | 0.08 | 0.02 | 162,955 |
| 14 | `SELECT name, setting, unit, short_desc FROM pg_settings WHERE vartype IN (...)` [Neon internal] | 213 | 0.44 | 2.08 | 0.85 | 80,088 |
| 15 | `WITH c AS (SELECT jsonb_object_agg(metric, value) FROM neon.neon_perf_counters)` [Neon internal] | 1,495 | 0.33 | 0.22 | 0.05 | 1,495 |
| 16 | `SELECT ... FROM pg_locks ... GROUP BY datid` [Neon internal] | 1,495 | 0.27 | 0.18 | 0.07 | 53,820 |
| 17 | **[APP]** `insert into "messages" (sender, content, type, external_message_id, webhook_event_id, is_redelivery, event_timestamp, conversation_id, ...) values (...)` | 69 | 0.21 | 3.08 | 12.56 | 69 |
| 18 | `SELECT x AS duration, approximate_working_set_size_seconds(...) FROM (values (...))` [Neon internal] | 1,495 | 0.19 | 0.13 | 0.03 | 5,980 |
| 19 | **[APP]** `insert into "rag_cache" (bot_id, query_text, query_normalized, query_embedding, response, metadata, created_at, expires_at) values (...) returning "id"` | 3 | 0.18 | 60.03 | 29.13 | 3 |
| 20 | `SELECT datid, datname, state, count(*) FROM pg_stat_activity WHERE state <> $1 GROUP BY ...` [Neon internal] | 1,495 | 0.17 | 0.11 | 0.08 | 4,480 |

**Application query summary (ranks 11, 12, 17, 19):**
- `messages` count query: 1 call, 526ms mean — full-table aggregation
- `conversations` lookup (for update): 81 calls, 6.43ms mean, **stddev 25.89ms** (high variance — lock contention spikes)
- `messages` insert: 69 calls, 3.08ms mean, stddev 12.56ms
- `rag_cache` insert: 3 calls, 60.03ms mean (pgvector embedding write overhead)

Note: `calls` totals are very low for application queries — pg_stat_statements was likely reset recently or the instance has low traffic at snapshot time.

---

### Table Scan Health (top 30 by seq_tup_read)

| Table | seq_scan | seq_tup_read | idx_scan | idx_tup_fetch | n_live_tup | n_dead_tup | dead % |
|-------|----------|--------------|----------|---------------|------------|------------|--------|
| cache | 1,185,670 | 62,748,039 | 474,954 | 12,217 | 47 | 50 | **51.5** |
| conversations | 11,606 | 13,258,379 | 23,281 | 414,713 | 1,161 | 108 | 8.5 |
| orders | 287 | 329,658 | 1,084 | 7,284 | 69 | 0 | 0.0 |
| personal_access_tokens | 4,236 | 256,496 | 0 | 0 | 68 | 33 | **32.7** |
| order_items | 193 | 247,035 | 0 | 0 | 86 | 0 | 0.0 |
| bots | 16,949 | 197,039 | 1,154 | 1,154 | 12 | 6 | **33.3** |
| messages | 2 | 125,344 | 23,721 | 2,586,781 | 3,070 | 0 | 0.0 |
| customer_profiles | 67 | 74,230 | 18,365 | 38,260 | 41 | 16 | **28.1** |
| users | 4,753 | 66,542 | 0 | 0 | 0 | 0 | null |
| flows | 4,065 | 45,384 | 0 | 0 | 0 | 0 | null |
| cache_locks | 34,175 | 35,296 | 0 | 0 | 0 | 43 | **100.0** |
| bot_settings | 4,807 | 33,850 | 352 | 352 | 0 | 0 | null |
| migrations | 48 | 5,376 | 0 | 0 | 0 | 0 | null |
| flow_knowledge_base | 1,160 | 3,480 | 0 | 0 | 0 | 0 | null |
| knowledge_bases | 1,164 | 3,152 | 0 | 0 | 0 | 0 | null |
| sessions | 174 | 3,005 | 144 | 8 | 31 | 1 | 3.1 |
| jobs | 1,745 | 1,457 | 985,796 | 52,140 | 0 | 6 | **100.0** |
| product_stocks | 273 | 1,091 | 0 | 0 | 0 | 3 | **100.0** |
| activity_logs | 1 | 588 | 41 | 410 | 224 | 0 | 0.0 |
| documents | 174 | 522 | 0 | 0 | 0 | 0 | null |
| document_chunks | 170 | 510 | 0 | 0 | 0 | 0 | null |
| rag_cache | 165 | 422 | 237 | 190 | 3 | 6 | **66.7** |
| failed_jobs | 9 | 10 | 0 | 0 | 0 | 5 | **100.0** |
| flow_audit_logs | 0 | 0 | 0 | 0 | 0 | 0 | null |
| quick_replies | 0 | 0 | 3 | 15 | 0 | 0 | null |
| bot_aggregation_settings | 0 | 0 | 0 | 0 | 0 | null | null |
| notifications | 0 | 0 | 0 | 0 | 0 | 0 | null |
| user_settings | 0 | 0 | 1,094 | 1,094 | 0 | 0 | null |
| job_batches | 0 | 0 | 0 | 0 | 0 | 0 | null |
| admin_bot_assignments | 0 | 0 | 31 | 0 | 0 | 0 | null |

**Key anomalies:**
- `cache` table: 1.185M seq_scans on a 47-row table — Laravel cache driver hitting DB on every read without index path
- `conversations`: 11,606 seq_scans reading 13.2M tuples vs 1,161 live rows — suggests full-table scans per webhook request
- `personal_access_tokens`: idx_scan = 0 (no index lookups at all) despite 4,236 seq_scans
- `cache_locks`: 100% dead tuples, 34,175 seq_scans — zombie table never vacuumed

---

### Unused Indexes (idx_scan = 0)

**Total: 60 unused indexes, 1,376 kB total wasted space** (confirmed via COUNT query).

Top 20 by size shown below (full list has 60 entries):

| Table | Index | Size |
|-------|-------|------|
| conversations | idx_conversations_webhook_lookup | 152 kB |
| document_chunks | document_chunks_content_fts | 120 kB |
| conversations | conversations_last_message_id_index | 104 kB |
| customer_profiles | customer_profiles_external_id_channel_type_unique | 88 kB |
| document_chunks | document_chunks_embedding_idx | 64 kB |
| order_items | order_items_pkey | 48 kB |
| activity_logs | activity_logs_bot_id_created_at_index | 40 kB |
| order_items | order_items_category_index | 32 kB |
| activity_logs | activity_logs_pkey | 32 kB |
| order_items | order_items_product_name_index | 32 kB |
| bots | bots_user_id_status_index | 16 kB |
| flows | flows_pkey | 16 kB |
| migrations | migrations_pkey | 16 kB |
| flows | flows_bot_id_is_default_index | 16 kB |
| documents | documents_pkey | 16 kB |
| documents | documents_knowledge_base_id_index | 16 kB |
| document_chunks | document_chunks_pkey | 16 kB |
| document_chunks | document_chunks_document_id_index | 16 kB |
| document_chunks | document_chunks_document_id_chunk_index_index | 16 kB |
| bot_settings | bot_settings_pkey | 16 kB |
| personal_access_tokens | personal_access_tokens_pkey | 16 kB |
| personal_access_tokens | personal_access_tokens_tokenable_type_tokenable_id_index | 16 kB |
| personal_access_tokens | personal_access_tokens_token_unique | 16 kB |
| user_settings | user_settings_pkey | 16 kB |
| flow_knowledge_base | flow_knowledge_base_pkey | 16 kB |
| flow_knowledge_base | flow_knowledge_base_flow_id_knowledge_base_id_unique | 16 kB |
| flow_knowledge_base | idx_flow_kb_reverse | 16 kB |
| flows | flows_bot_id_index | 16 kB |
| users | users_pkey | 16 kB |
| users | users_email_unique | 16 kB |
| cache_locks | cache_locks_pkey | 16 kB |
| failed_jobs | failed_jobs_pkey | 16 kB |
| failed_jobs | failed_jobs_uuid_unique | 16 kB |
| knowledge_bases | knowledge_bases_pkey | 16 kB |
| knowledge_bases | knowledge_bases_user_id_index | 16 kB |
| bots | bots_user_id_index | 16 kB |
| bots | bots_channel_type_index | 16 kB |
| quick_replies | quick_replies_pkey | 16 kB |
| quick_replies | quick_replies_user_id_shortcut_unique | 16 kB |
| flow_plugins | flow_plugins_pkey | 16 kB |
| flow_plugins | flow_plugins_flow_id_enabled_index | 16 kB |
| product_stocks | product_stocks_pkey | 16 kB |
| product_stocks | product_stocks_slug_key | 16 kB |
| bot_response_hours | bot_response_hours_pkey | 8 kB |
| flow_audit_logs | flow_audit_logs_pkey | 8 kB |
| lead_recovery_logs | lead_recovery_logs_pkey | 8 kB |
| notifications | notifications_notifiable_type_notifiable_id_read_at_index | 8 kB |
| notifications | notifications_pkey | 8 kB |
| injection_attempts_log | injection_attempts_log_pkey | 8 kB |
| admin_bot_assignments | admin_bot_assignments_pkey | 8 kB |

Note: Many PKs showing idx_scan = 0 (e.g., `flows_pkey`, `users_pkey`, `documents_pkey`) indicates these tables have zero traffic or pg_stat_statements was reset. PK indexes should NOT be dropped — they are structural. Candidates for actual removal are the non-PK, non-unique indexes on low-traffic tables.

---

### Cache Hit Ratio (top 20 active tables)

| Table | Hit ratio % | heap_blks_read | heap_blks_hit |
|-------|-------------|----------------|---------------|
| cache | **100.00** | 23 | 4,267,515 |
| conversations | **99.98** | 220 | 927,939 |
| jobs | **99.90** | 181 | 181,438 |
| flows | **99.91** | 14 | 16,370 |
| bot_settings | **99.94** | 6 | 9,960 |
| cache_locks | **99.99** | 6 | 105,110 |
| personal_access_tokens | **99.86** | 25 | 17,559 |
| users | **99.83** | 16 | 9,616 |
| messages | **98.92** | 5,389 | 495,379 |
| customer_profiles | **99.60** | 137 | 34,174 |
| orders | **99.15** | 190 | 22,086 |
| sessions | **99.20** | 23 | 2,866 |
| order_items | **98.38** | 60 | 3,638 |
| rag_cache | **94.75** | 135 | 2,435 |
| knowledge_bases | **99.74** | 3 | 1,161 |
| flow_knowledge_base | **99.74** | 3 | 1,157 |
| user_settings | **99.73** | 3 | 1,091 |

Note: Only 17 tables met the `> 1000 total blocks` threshold. All 17 exceed 94% hit ratio. Overall cache health is excellent.

---

### Biggest Tables by Size

| Table | Total size | Data size | Index size | Total bytes |
|-------|-----------|-----------|------------|-------------|
| messages | **37 MB** | 20 MB | 17 MB | 38,682,624 |
| cache | 1,400 kB | 16 kB | 1,384 kB | 1,433,600 |
| conversations | 1,304 kB | 600 kB | 704 kB | 1,335,296 |
| customer_profiles | 656 kB | 400 kB | 256 kB | 671,744 |
| orders | 608 kB | 448 kB | 160 kB | 622,592 |
| rag_cache | 520 kB | 64 kB | 456 kB | 532,480 |
| document_chunks | 352 kB | 8 kB | 344 kB | 360,448 |
| order_items | 304 kB | 152 kB | 152 kB | 311,296 |
| activity_logs | 256 kB | 104 kB | 152 kB | 262,144 |
| flows | 256 kB | 32 kB | 224 kB | 262,144 |
| jobs | 240 kB | 16 kB | 224 kB | 245,760 |
| sessions | 176 kB | 56 kB | 120 kB | 180,224 |
| bots | 152 kB | 48 kB | 104 kB | 155,648 |
| documents | 144 kB | 8 kB | 136 kB | 147,456 |
| personal_access_tokens | 112 kB | 24 kB | 88 kB | 114,688 |

Note: `messages` at 37 MB is 27x larger than the second biggest table. Index overhead (17 MB) is nearly equal to data (20 MB) — index bloat risk. DB is small overall (< 50 MB total), so cost is not a concern but growth trajectory should be watched.

---

## Findings

### Finding 1: `cache` table — catastrophic seq_scan abuse (1.18M scans on 47 rows)

- **Evidence:** Query B row: `cache` → seq_scan=1,185,670 / seq_tup_read=62,748,039 / n_live_tup=47 / dead_pct=51.5%
- **Impact:** 62.7M tuple reads on a 47-row table = every single cache read does a full scan. The `cache` table is the Laravel DB cache driver backend. With 1,185,670 seq_scans, this is the highest-frequency operation in the entire database by far. Each request touching cache (conversation lookup, flow config, bot settings) triggers a full scan. Estimated cost: >80% of application DB I/O.
- **Root cause hypothesis:**
  1. Laravel DB cache driver uses `cache` table with key column; if the `cache_key` index is missing or not being used, every `Cache::get()` becomes a seq_scan
  2. The table has 50 dead tuples vs 47 live (51.5% dead) — autovacuum is not running effectively, causing heap bloat that prevents index-only scans
  3. Phase 1 Redis migration (from MEMORY.md) is in progress but not yet complete — DB cache still active
- **Fix candidates:**
  1. Complete Phase 1 Redis migration — move all `Cache::` calls off DB driver (effort 3, risk 2)
  2. `VACUUM ANALYZE cache;` immediately to clear 50 dead tuples and rebuild statistics (effort 1, risk 1)
  3. Verify `cache` table has index on `key` column; if missing, `CREATE INDEX` (effort 1, risk 1)

---

### Finding 2: `conversations` — 11,606 seq_scans reading 13.2M tuples (11,412 rows/scan on 1,161-row table)

- **Evidence:** Query B row: `conversations` → seq_scan=11,606 / seq_tup_read=13,258,379 / n_live_tup=1,161 / idx_scan=23,281
- **Evidence (slow query):** Query A rank 12: `select * from "conversations" where bot_id=$1 and external_customer_id=$2 and channel_type=$3 and status in ($4,$5) ... for update` — 81 calls, mean 6.43ms, **stddev 25.89ms** (4x mean — severe lock contention spikes)
- **Evidence (unused index):** Query C: `idx_conversations_webhook_lookup` (152 kB) has idx_scan=0 — the index designed to speed up this exact query pattern is not being used
- **Impact:** This is the core webhook hot path. Every incoming LINE/Telegram message triggers this query. At 11,606 scans across 1,161 rows = full-table read per webhook. Stddev 25.89ms means occasional spikes to ~30-50ms under concurrent load. `FOR UPDATE` locking serializes concurrent webhooks for same customer.
- **Root cause hypothesis:**
  1. `idx_conversations_webhook_lookup` exists (152 kB) but idx_scan=0 — query planner is choosing seq_scan, likely because statistics are stale or index columns don't match query predicate order
  2. The `status IN ($4, $5)` predicate with soft-delete `deleted_at IS NULL` may prevent index use if index doesn't include these columns
  3. Low row count (1,161) causes planner to prefer seq_scan as "cheaper" — but 11k scans proves this is wrong at scale
- **Fix candidates:**
  1. `EXPLAIN ANALYZE` the conversations lookup to confirm planner choice; force `SET enable_seqscan=off` test (effort 1, risk 1)
  2. Rebuild `idx_conversations_webhook_lookup` as composite index covering `(bot_id, external_customer_id, channel_type, status, deleted_at)` (effort 2, risk 2)
  3. `VACUUM ANALYZE conversations;` to refresh planner statistics (effort 1, risk 1)

---

### Finding 3: Dead tuple accumulation — 5 tables at ≥100% dead ratio, autovacuum not keeping up

- **Evidence (Query B):**
  - `cache_locks`: dead_pct=100.0% (43 dead / 0 live), 34,175 seq_scans
  - `jobs`: dead_pct=100.0% (6 dead / 0 live)
  - `product_stocks`: dead_pct=100.0% (3 dead / 0 live)
  - `failed_jobs`: dead_pct=100.0% (5 dead / 0 live)
  - `rag_cache`: dead_pct=66.7% (6 dead / 3 live)
  - `cache`: dead_pct=51.5% (50 dead / 47 live)
  - `personal_access_tokens`: dead_pct=32.7% (33 dead / 68 live)
  - `bots`: dead_pct=33.3% (6 dead / 12 live)
  - `customer_profiles`: dead_pct=28.1% (16 dead / 41 live)
- **Impact:** Tables with high dead_pct force vacuum to read and discard dead tuples on every scan, inflating I/O. `cache_locks` at 100% dead with 34,175 seq_scans is pure wasted I/O. `rag_cache` at 66.7% dead means pgvector similarity searches scan 3x more heap than necessary.
- **Root cause hypothesis:**
  1. Neon serverless pauses compute between requests — autovacuum cannot run while compute is suspended
  2. High-churn tables (jobs, cache_locks) process rows but never get vacuumed before next pause cycle
  3. `rag_cache` has only 3 live rows with 6 dead — entries expire and are soft-deleted but VACUUM hasn't run
- **Fix candidates:**
  1. Run `VACUUM ANALYZE` on all 9 affected tables immediately (effort 1, risk 1)
  2. Increase `autovacuum_vacuum_scale_factor` for high-churn tables via `ALTER TABLE ... SET (autovacuum_vacuum_scale_factor = 0.01)` (effort 2, risk 1)
  3. Add scheduled VACUUM job in Laravel scheduler for Neon (effort 2, risk 1)

---

### Finding 4: `personal_access_tokens` — 4,236 seq_scans with idx_scan=0 (token auth never uses index)

- **Evidence:** Query B: `personal_access_tokens` → seq_scan=4,236 / seq_tup_read=256,496 / idx_scan=0 / idx_tup_fetch=0
- **Evidence:** Query C: `personal_access_tokens_token_unique`, `personal_access_tokens_pkey`, `personal_access_tokens_tokenable_type_tokenable_id_index` — all idx_scan=0
- **Impact:** Every API authentication request (Sanctum token lookup) scans all 68 rows. At 4,236 scans, this represents all authenticated API calls. Token lookup by hash should be an index scan on `personal_access_tokens_token_unique` — the fact it's never used suggests either: (a) query uses LIKE/substring instead of exact match, or (b) the column type mismatch prevents index use.
- **Root cause hypothesis:**
  1. Laravel Sanctum hashes tokens with SHA-256 before lookup; if the stored `token` column is hashed but query compares unhashed value (or vice versa), index is bypassed
  2. Possible column type mismatch (text vs varchar) preventing b-tree index use
  3. `n_live_tup=68` means planner sees 68 rows as "small enough" to always seq_scan — but Sanctum queries this on every request
- **Fix candidates:**
  1. `EXPLAIN` the Sanctum token lookup to confirm seq_scan cause (effort 1, risk 1)
  2. If type mismatch: `ALTER TABLE personal_access_tokens ALTER COLUMN token TYPE text` (effort 2, risk 2)
  3. If Sanctum is not actively used (API uses JWT/other): truncate `personal_access_tokens` and disable Sanctum middleware (effort 2, risk 2)

---

### Finding 5: `rag_cache` index overhead — 456 kB indexes on 64 kB data (7:1 index-to-data ratio)

- **Evidence:** Query E: `rag_cache` → total_size=520 kB / data_size=64 kB / index_size=456 kB
- **Evidence:** Query D: `rag_cache` hit_ratio=94.75% — lowest of all active tables
- **Evidence:** Query A rank 19: `rag_cache` insert takes 60.03ms mean (29.13ms stddev) due to pgvector embedding index update
- **Root cause hypothesis:**
  1. `document_chunks_embedding_idx` (pgvector HNSW/IVFFlat index) requires expensive index update on every insert
  2. With only 3 live rows and 6 dead (66.7% dead), the index is maintained for near-zero benefit
  3. 94.75% cache hit ratio is the only table below 98% — dead tuple bloat causing extra disk reads
- **Impact:** Each RAG cache write costs 60ms due to pgvector index maintenance. If RAG cache hit rate is low (few cached queries reused), this overhead is pure waste. The 94.75% block hit ratio suggests some cold reads from disk.
- **Fix candidates:**
  1. `VACUUM ANALYZE rag_cache;` to clear 6 dead tuples (effort 1, risk 1)
  2. Evaluate if `rag_cache` pgvector index is used — Query C shows `document_chunks_embedding_idx` idx_scan=0; if never queried by vector similarity, drop index (effort 2, risk 2)
  3. Consider TTL-based expiry running more frequently to prevent dead tuple accumulation (effort 2, risk 1)

---

## Status: 🔴

**Assessment by threshold:**

| Dimension | Value | Threshold | Status |
|-----------|-------|-----------|--------|
| Cache hit ratio (worst active table) | 94.75% (`rag_cache`) | > 95% = 🟢, > 90% = 🟡 | 🟡 |
| Dead % — `cache_locks` (0 live rows) | 100% | — (no live rows, structural) | — |
| Dead % — `cache` (47 live rows) | 51.5% | < 25% = 🟡, ≥ 25% = 🔴 (but < 10k rows) | 🟡 |
| Dead % — `bots` (12 live rows) | 33.3% | < 25% = 🟡 | 🟡 |
| Dead % — `customer_profiles` (41 live rows) | 28.1% | < 25% = 🟡 | 🟡 |
| `cache` seq_scan abuse | 1,185,670 scans / 47 rows | No baseline threshold — **critical anomaly** | 🔴 |
| `conversations` seq_scan with unused index | 11,606 scans, idx_conv_webhook=0 | No baseline — **critical anomaly** | 🔴 |

**Overall: 🔴** — Two critical seq_scan anomalies (`cache` and `conversations`) dominate application DB I/O. Cache hit ratio narrowly misses 🟢. Dead tuple accumulation is systemic but contained to small tables.

---

## Notes

- pg_stat_statements `calls` for application queries are very low (1–81 calls vs 25k+ for Neon monitoring queries). This strongly suggests pg_stat_statements was reset recently, or the Neon compute was restarted. Historical query frequency data should not be used for absolute cost calculations — only relative ranking is reliable.
- All tables are small (DB < 50 MB total). Performance issues are behavioral (seq_scan patterns, missing index use) rather than scale-related.
- The in-progress Phase 1 Redis migration (MEMORY.md: `project_db_cost_reduction.md`) directly addresses Finding 1 (`cache` table abuse). Completing this migration is the highest-priority fix.
- 60 unused indexes total — however, ~30 of these are PKs on empty/zero-traffic tables. Safe candidates for removal are the non-PK indexes on `conversations`, `document_chunks`, `activity_logs`, `order_items`.
