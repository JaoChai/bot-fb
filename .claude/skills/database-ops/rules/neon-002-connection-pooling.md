---
id: neon-002-connection-pooling
title: Neon Connection Pooling vs Direct Connection
impact: HIGH
impactDescription: "Wrong connection type causes pool exhaustion or migration failures"
category: neon
tags: [neon, pooling, connection, serverless]
relatedRules: [gotcha-001-pool-exhaustion, perf-005-connection-pool]
---

## Why This Matters

Neon offers two connection types: pooled (via PgBouncer) and direct. Pooled connections are efficient for web requests but can't handle long transactions or prepared statements. Direct connections are limited but necessary for migrations.

## Connection Types

| Type | URL Pattern | Limit | Use For |
|------|-------------|-------|---------|
| Pooled | `ep-xxx-pooler.region.neon.tech` | 100+ | Web requests |
| Direct | `ep-xxx.region.neon.tech` | 10-20 | Migrations, jobs |

## Bad Example

```php
// Using pooled connection for migration
// .env
DATABASE_URL=postgres://user@ep-xxx-pooler.region.neon.tech/db

// Migration with long transaction fails
php artisan migrate
// ERROR: prepared statement "pdo_stmt_xxx" already exists
// (PgBouncer can't handle prepared statements in transaction mode)
```

**Why it's wrong:**
- Pooler doesn't support some operations
- Prepared statements fail
- Long transactions timeout

## Good Example

```php
// .env
# Pooled for web requests (default)
DATABASE_URL=postgres://user:pass@ep-xxx-pooler.region.neon.tech/db

# Direct for migrations and jobs
DATABASE_DIRECT_URL=postgres://user:pass@ep-xxx.region.neon.tech/db

// config/database.php
'connections' => [
    'pgsql' => [
        'url' => env('DATABASE_URL'),
    ],
    'pgsql_direct' => [
        'driver' => 'pgsql',
        'url' => env('DATABASE_DIRECT_URL'),
    ],
],

// In migration that needs direct connection
public function up(): void
{
    DB::connection('pgsql_direct')->transaction(function () {
        // Long-running migration
    });
}
```

**Why it's better:**
- Right connection for right task
- Pooled for efficiency
- Direct for reliability

## Project-Specific Notes

**BotFacebook Connection Setup:**

```bash
# Railway environment variables
DATABASE_URL=postgres://...@ep-xxx-pooler.../db  # Web
DATABASE_DIRECT_URL=postgres://...@ep-xxx.../db   # Migrations

# Queue worker uses direct
QUEUE_CONNECTION=database
QUEUE_DATABASE_URL=${DATABASE_DIRECT_URL}
```

**Check Which Connection:**
```sql
-- Shows if using pooler
SELECT application_name FROM pg_stat_activity WHERE pid = pg_backend_pid();
-- Pooler: "PgBouncer"
-- Direct: "your-app-name"
```
