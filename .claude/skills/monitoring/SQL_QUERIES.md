# Database Monitoring Queries

## Connection Pool

```sql
-- Check active connections
SELECT count(*) FROM pg_stat_activity;

-- Check connection by state
SELECT state, count(*)
FROM pg_stat_activity
GROUP BY state;
```

## Table Stats

```sql
-- Table sizes
SELECT relname, pg_size_pretty(pg_total_relation_size(relid))
FROM pg_catalog.pg_statio_user_tables
ORDER BY pg_total_relation_size(relid) DESC
LIMIT 10;

-- Row counts
SELECT relname, n_live_tup
FROM pg_stat_user_tables
ORDER BY n_live_tup DESC;
```

## Performance Analysis

```sql
-- Slow queries (requires pg_stat_statements)
SELECT query, calls, mean_exec_time, total_exec_time
FROM pg_stat_statements
ORDER BY mean_exec_time DESC
LIMIT 10;

-- Index usage
SELECT relname, seq_scan, idx_scan
FROM pg_stat_user_tables
WHERE seq_scan > idx_scan
ORDER BY seq_scan DESC;
```

## Health Check Endpoint

```php
// routes/api.php
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'services' => [
            'database' => DB::connection()->getPdo() ? 'ok' : 'error',
            'cache' => Cache::store()->get('health') !== null ? 'ok' : 'error',
            'queue' => Queue::size() < 1000 ? 'ok' : 'warning',
        ],
    ]);
});
```

## Health Check Response

```json
{
  "status": "ok",
  "timestamp": "2026-01-17T10:30:00Z",
  "services": {
    "database": "ok",
    "cache": "ok",
    "queue": "ok"
  }
}
```
