# Runbook — VACUUM FULL on `bots` Table (Sprint 1 #12)

**Date executed:** TBD (record at run time)
**Operator:** TBD
**Window:** 02:00-08:00 +07 maintenance window
**Estimated duration:** 10-30 seconds (bots table is small)
**Lock impact:** ACCESS EXCLUSIVE on `bots` for the duration — all reads/writes blocked

## Why
Perf audit (2026-05-15) measured 52% dead tuples on `bots`. VACUUM (regular) cannot reclaim space without rewrites, so `VACUUM FULL` is required.

## Pre-flight checks (run from neon mcp or psql)

### Check 1 — confirm dead tuple ratio is still high
```sql
SELECT
    relname,
    n_live_tup,
    n_dead_tup,
    ROUND(100.0 * n_dead_tup / NULLIF(n_live_tup + n_dead_tup, 0), 2) AS dead_pct
FROM pg_stat_user_tables
WHERE relname = 'bots';
```
- If `dead_pct < 20`, skip this task — autovacuum already cleaned up.
- If `dead_pct >= 20`, proceed.

### Check 2 — confirm no long-running transactions are holding locks on `bots`
```sql
SELECT pid, state, query_start, query
FROM pg_stat_activity
WHERE query ILIKE '%bots%' AND state != 'idle';
```
- Expected: empty result. If anything is running, wait or coordinate.

## Operation

```sql
VACUUM FULL ANALYZE bots;
```

(`ANALYZE` is bundled so statistics get refreshed after the rewrite.)

## Post-flight verification

### Check 1 — dead tuple ratio collapsed
```sql
SELECT
    relname,
    n_live_tup,
    n_dead_tup,
    ROUND(100.0 * n_dead_tup / NULLIF(n_live_tup + n_dead_tup, 0), 2) AS dead_pct
FROM pg_stat_user_tables
WHERE relname = 'bots';
```
- Expected: `dead_pct < 5` (target < 10 per spec).

### Check 2 — table is still queryable
```sql
SELECT COUNT(*) FROM bots;
```
- Expected: matches `n_live_tup` from pre-flight check.

## Rollback
Not applicable. VACUUM FULL is non-destructive (only reclaims dead space). The "rollback" is doing nothing — the table is fully functional after the operation.

## Result (fill in after running)
- Pre-run dead_pct: ____
- Post-run dead_pct: ____
- Duration: ____ seconds
- Issues encountered: ____
