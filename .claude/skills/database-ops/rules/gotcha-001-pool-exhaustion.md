---
id: gotcha-001-pool-exhaustion
title: Connection Pool Exhaustion
impact: CRITICAL
impactDescription: "Running out of database connections causes application-wide failures"
category: gotcha
tags: [gotcha, connection, pool, neon, serverless]
relatedRules: [neon-002-connection-pooling]
---

## Why This Matters

Connection pools have limits (typically 100 for Neon pooler). When all connections are in use, new requests fail with "too many connections" errors. This causes cascading failures across your entire application, not just the slow endpoint.

## Bad Example

```php
// Problem: Long-running connections block the pool
public function processLargeDataset(): void
{
    $items = Item::all(); // Holds connection

    foreach ($items as $item) {
        // Long processing per item
        sleep(1);
        $item->update(['processed' => true]);
    }
    // Connection held for entire duration
}

// Problem: Connection leaks
public function getData(): array
{
    $pdo = DB::connection()->getPdo();
    // If exception thrown before return, connection may leak
    throw new \Exception('Error');
}
```

**Why it's wrong:**
- Long-running queries hold connections
- Pool exhaustion affects all users
- Application-wide outage

## Good Example

```php
// Use chunking to release connections periodically
public function processLargeDataset(): void
{
    Item::query()
        ->whereNull('processed_at')
        ->chunkById(100, function ($items) {
            foreach ($items as $item) {
                $item->update(['processed_at' => now()]);
            }
            // Connection released after each chunk
        });
}

// Use queued jobs for long operations
public function processLargeDataset(): void
{
    Item::query()
        ->whereNull('processed_at')
        ->chunk(100, function ($items) {
            ProcessItemsJob::dispatch($items->pluck('id'));
        });
}

// Set appropriate timeouts
// config/database.php
'pgsql' => [
    'options' => [
        PDO::ATTR_TIMEOUT => 5, // 5 second timeout
    ],
],
```

**Why it's better:**
- Connections released regularly
- Pool stays available
- Graceful handling of long operations

## Project-Specific Notes

**BotFacebook Connection Limits:**

| Environment | Limit | URL Type |
|-------------|-------|----------|
| Neon Pooler | 100 | `?pooler=true` |
| Neon Direct | 10 | Direct connection |
| Local | 100 | Default |

**Config Pattern:**
```php
// .env
DATABASE_URL=postgres://user:pass@host/db?sslmode=require

// For queue workers (long-running)
QUEUE_DATABASE_URL=postgres://user:pass@host/db?sslmode=require

// For web requests (pooled)
WEB_DATABASE_URL=postgres://user:pass@ep-xxx-pooler.region.neon.tech/db
```

**Monitoring Connections:**
```sql
-- Current connection count
SELECT count(*) FROM pg_stat_activity;

-- Connections by application
SELECT application_name, count(*)
FROM pg_stat_activity
GROUP BY application_name;

-- Kill idle connections
SELECT pg_terminate_backend(pid)
FROM pg_stat_activity
WHERE state = 'idle'
  AND query_start < NOW() - INTERVAL '10 minutes';
```

## MCP Tools

```
# Check connection count
mcp__neon__run_sql(
    projectId="your-project",
    sql="SELECT count(*) as connections FROM pg_stat_activity"
)
```

## References

- [Neon Connection Pooling](https://neon.tech/docs/connect/connection-pooling)
- [PostgreSQL Connection Limits](https://www.postgresql.org/docs/current/runtime-config-connection.html)
