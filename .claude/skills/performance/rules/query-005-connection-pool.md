---
id: query-005-connection-pool
title: Database Connection Pool Issues
impact: MEDIUM
impactDescription: "Connection exhaustion or inefficient connection usage"
category: query
tags: [database, connection, pool, neon]
relatedRules: [query-002-slow-queries, cache-001-query-caching]
---

## Symptom

- "Too many connections" errors
- Connection timeout errors
- Slow initial queries
- Spiky latency patterns

## Root Cause

1. Pool size too small for load
2. Connections not being released
3. Long-running transactions holding connections
4. No connection pooling configured
5. Neon cold start delays

## Diagnosis

### Quick Check

```sql
-- Check current connections
SELECT count(*) FROM pg_stat_activity;

-- Check connection limits
SHOW max_connections;

-- Check idle connections
SELECT count(*), state
FROM pg_stat_activity
GROUP BY state;
```

### Detailed Analysis

```php
// Check Laravel connection settings
dd(config('database.connections.pgsql'));

// Monitor connections in logs
Log::info('DB connections', [
    'count' => DB::connection()->select('SELECT count(*) FROM pg_stat_activity')[0]->count,
]);
```

## Measurement

```
Before: Connection errors, cold start delays
Target: Stable connections, < 50ms connection time
```

## Solution

### Fix Steps

1. **Configure pool size**
```php
// config/database.php
'pgsql' => [
    // ... other settings
    'options' => [
        PDO::ATTR_PERSISTENT => false,  // Don't use persistent in serverless
    ],
],
```

2. **Use Neon connection pooler**
```env
# Use pooled connection string from Neon
DATABASE_URL="postgresql://user:pass@ep-xxx-pooler.region.neon.tech/db?sslmode=require"
#                                          ^^^^^^^ pooler endpoint
```

3. **Handle cold starts**
```php
// Warm up database connection
class DatabaseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (app()->environment('production')) {
            try {
                DB::connection()->getPdo();
            } catch (\Exception $e) {
                Log::warning('DB warmup failed', ['error' => $e->getMessage()]);
            }
        }
    }
}
```

4. **Release connections properly**
```php
// Don't hold connections during long operations
DB::disconnect();

// Do long external API call
$result = Http::timeout(30)->get('external-api');

// Reconnect when needed
DB::reconnect();
```

5. **Use transaction timeouts**
```php
// Prevent long-running transactions
DB::statement('SET statement_timeout = 30000');  // 30 seconds

// Or in query
DB::transaction(function () {
    // Operations...
}, 5);  // 5 attempts
```

### Connection Pooling Strategies

```php
// config/database.php for Neon
'pgsql' => [
    'driver' => 'pgsql',
    'url' => env('DATABASE_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'forge'),
    'username' => env('DB_USERNAME', 'forge'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => 'require',
    'options' => [
        PDO::ATTR_EMULATE_PREPARES => true,  // Better for pooling
    ],
],
```

### Queue Job Connection Management

```php
// Release DB connection after job
class ProcessMessage implements ShouldQueue
{
    public function handle(): void
    {
        try {
            // Job logic
        } finally {
            // Release connection for next job
            DB::disconnect();
        }
    }
}
```

## Verification

```sql
-- Verify connection count is stable
SELECT count(*), state
FROM pg_stat_activity
WHERE datname = 'your_database'
GROUP BY state;

-- Should show mostly 'idle' not 'active'
```

```bash
# Test under load
ab -n 100 -c 10 https://api.botjao.com/api/endpoint
# Should not see connection errors
```

## Prevention

- Use Neon pooler endpoint
- Monitor connection counts
- Set appropriate timeouts
- Release connections in long jobs
- Handle cold starts gracefully

## Project-Specific Notes

**BotFacebook Context:**
- Database: Neon PostgreSQL
- Use pooler endpoint in production
- Connection limit: Check Neon plan
- Cold start: First request may be slower
