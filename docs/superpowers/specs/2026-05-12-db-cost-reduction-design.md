# DB Cost Reduction — Phase 1 Two-batch Design

**Date:** 2026-05-12
**Target:** Reduce Neon compute cost while preserving service quality
**Project:** bot-facebook (Neon `solitary-math-34010034`)

## Problem Statement

Production Neon Postgres burns compute hours unnecessarily:

- Laravel uses DB as cache/session/queue driver → `cache` table seq-scanned 869,523 times, 45,989,490 rows read
- Autoscaling max CU is 2 (DB size 54 MB, 12 bots, light traffic — over-provisioned)
- Suspend timeout left at default (300s)
- DashboardController stats query runs uncached against `messages` (2,493 rows + joins)
- Active compute time: 1,082,084 sec over 141 days (8.9% uptime)

## Goal

Cut Neon `active_time_seconds` and CU-hours by ≥30% with no functional regression. Every change reversible within 5 minutes.

## Out of Scope (Phase 2/3 — future spec)

- `bots` over-update root cause (2,759 UPDATE on 12 rows)
- Missing indexes on `flows`/`users`/`tokens` (seq_scan-only)
- VACUUM FULL on bloated tables
- Unused index pruning (requires 2-week stat collection window)

## Architecture Changes

### Batch 1 — Zero-code Neon tuning + dashboard cache (Day 1)

| # | Change | Anchor | Action |
|---|--------|--------|--------|
| 1 | Provision Redis service | Railway project `bot-facebook` | Add Redis from Railway template; capture `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` |
| 2 | Cache DashboardController stats | `backend/app/Http/Controllers/Api/DashboardController.php` | Wrap heavy stats query with `Cache::remember($key, 60, fn() => ...)` mirroring `AnalyticsController.php:50` |
| 3 | Reduce max CU | Neon Console — compute `ep-steep-hall-a1uhvu89` | autoscaling max 2 → 1 |
| 4 | Reduce suspend timeout | Neon Console — compute `ep-steep-hall-a1uhvu89` | `suspend_timeout_seconds` 0 (default 300s) → 60 |

### Batch 2 — Driver migration (Day 2, after Batch 1 stable)

| # | Change | Anchor | Action |
|---|--------|--------|--------|
| 5 | Drain queue | Railway `scheduler` shell | `php artisan queue:work --stop-when-empty` then stop worker |
| 6 | Set Redis env | Railway `backend` env | `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`, plus `REDIS_HOST/PORT/PASSWORD` |
| 7 | Set Redis env | Railway `scheduler` env | Same as #6 |
| 8 | Set Redis env | Railway `reverb` env | Same as #6 (broadcasting uses its own driver — only cache/session/queue affected) |
| 9 | Redeploy | Railway services `backend`, `scheduler` | Auto on env change |

## Data Flow

**Before:**
```
HTTP request → Laravel → DB cache table (seq scan) → response
Queue job → DB jobs table (FOR UPDATE poll) → worker
```

**After Batch 2:**
```
HTTP request → Laravel → Redis (in-memory O(1)) → response
Queue job → Redis BLPOP → worker
DB queried only for business data (bots, conversations, messages, etc.)
```

## Verification

### Baseline capture (before Batch 1)

Save output of these queries to `/tmp/db-baseline-pre.json`:

1. `mcp__neon__describe_project` → record `active_time_seconds`, `cpu_used_sec`, `compute_time_seconds`
2. `SELECT relname, seq_scan, seq_tup_read, idx_scan, n_dead_tup FROM pg_stat_user_tables WHERE relname IN ('cache','cache_locks','jobs','sessions','bots','conversations');`
3. `SELECT calls, total_exec_time, mean_exec_time_ms, query FROM pg_stat_statements ORDER BY total_exec_time DESC LIMIT 15;`

### Success criteria (T+24h and T+48h after Batch 2)

| Metric | Source | Target |
|--------|--------|--------|
| `active_time_seconds` delta | `mcp__neon__describe_project` | -30% or more |
| `cache` table seq_scan delta | `pg_stat_user_tables` | near 0 |
| Dashboard slow query calls | `pg_stat_statements` | -50% or more |
| Sentry error rate | Sentry dashboard | unchanged |
| API p95 latency | Railway logs | unchanged or improved |
| Neon CU max utilized | Neon Console metrics | stays ≤ 1 (no throttling) |

### Failure indicators → trigger rollback

- Neon CPU sustained > 80% after CU reduction
- Sentry error rate +20% after Batch 2
- Redis memory > 80% of plan
- Any p95 latency regression > 200ms

## Rollback Plan

| Trigger | Action | Time |
|---------|--------|------|
| Neon throttling on max CU 1 | Neon Console: revert max CU to 2 | < 1 min |
| Cold-start spikes from 60s suspend | Neon Console: revert suspend to 300 | < 1 min |
| DashboardController cache bug | `git revert <sha>` + Railway redeploy | ~5 min |
| Redis migration breakage | Railway env: revert `CACHE_STORE`/`SESSION_DRIVER`/`QUEUE_CONNECTION` to `database` + redeploy (DB tables `cache`, `sessions`, `jobs` still intact) | ~3 min |
| Redis OOM | Railway: upgrade Redis plan or revert to DB driver | ~5 min |

## Risks

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Redis OOM | Low | Start with 256 MB plan, monitor in Railway metrics |
| Session loss on driver switch | High | Users re-login after Batch 2 deploy — acceptable, communicated as known limitation |
| Queue jobs lost on driver switch | Medium | Drain queue (step #5) before changing `QUEUE_CONNECTION` |
| CU max 1 insufficient under unforeseen peak | Low | Auto-rollback procedure documented; Neon allows live max CU change |
| Cold start after 60s suspend hits webhook | Low | LINE/Telegram webhooks have 1s+ retry; cold start ~500ms |
| `REDIS_HOST` not injected into `scheduler` service | Medium | Explicit env propagation step (#7) + post-deploy `redis-cli ping` from inside container |

## File Inventory

| Path | Modification |
|------|--------------|
| `backend/app/Http/Controllers/Api/DashboardController.php` | Add `Cache::remember` wrappers on stats query |
| Railway `backend` env vars | `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` |
| Railway `scheduler` env vars | Same as backend |
| Railway `reverb` env vars | Same as backend |
| Neon compute config | Max CU 2→1, suspend_timeout 300→60 |

No DB migration required.
