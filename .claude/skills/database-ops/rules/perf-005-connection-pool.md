---
id: perf-005-connection-pool
title: Connection Pooling Configuration
impact: MEDIUM
impactDescription: "Wrong pool settings cause connection exhaustion or overhead"
category: perf
tags: [performance, connection, pool, neon]
relatedRules: [gotcha-001-pool-exhaustion]
---

## Why This Matters

Database connections are expensive to create. Connection pooling reuses connections across requests. Wrong settings cause either exhaustion (too few) or wasted resources (too many).

## Bad Example

```php
// No pooling - new connection per request
// .env
DATABASE_URL=postgres://user:pass@db.neon.tech/neondb

// High connection overhead
// May hit connection limits
```

**Why it's wrong:**
- Connection setup takes ~100ms
- Direct connections limited (10-20)
- No reuse between requests

## Good Example

```php
// Use Neon's pooler
// .env
DATABASE_URL=postgres://user:pass@ep-xxx-pooler.region.neon.tech/neondb

// config/database.php
'pgsql' => [
    'url' => env('DATABASE_URL'),
    'options' => [
        PDO::ATTR_PERSISTENT => false, // Pooler handles this
        PDO::ATTR_TIMEOUT => 5,
    ],
],

// For queue workers (long-running, use direct)
// .env
QUEUE_DATABASE_URL=postgres://user:pass@ep-xxx.region.neon.tech/neondb
```

**Why it's better:**
- Pooler handles connection reuse
- Higher connection limit (100+)
- Faster request handling

## Project-Specific Notes

**BotFacebook Connection Strategy:**

| Use Case | Connection Type | Limit |
|----------|----------------|-------|
| Web requests | Pooler | 100 |
| Queue workers | Direct | 10 |
| Migrations | Direct | 1 |

```php
// Dynamic connection selection
// config/database.php
'connections' => [
    'pgsql' => [
        'url' => env('DATABASE_URL'), // Pooler for web
    ],
    'pgsql_direct' => [
        'url' => env('DATABASE_DIRECT_URL'), // Direct for migrations
    ],
],

// In migration
public function up(): void
{
    // Use direct connection for DDL
    DB::connection('pgsql_direct')->statement('...');
}
```
