---
id: gotcha-008-connection-timeout
title: Connection Timeout vs Query Timeout
impact: MEDIUM
impactDescription: "Different timeouts serve different purposes - configure both"
category: gotcha
tags: [gotcha, timeout, connection, query]
relatedRules: [perf-006-query-timeout, gotcha-001-pool-exhaustion]
---

## Why This Matters

There are two types of timeouts: connection timeout (how long to wait to establish connection) and query timeout (how long a query can run). Confusing them leads to either hung connections or premature query termination.

## Bad Example

```php
// Only sets connection timeout
'pgsql' => [
    'options' => [
        PDO::ATTR_TIMEOUT => 5, // This is connection timeout only!
    ],
];

// Query runs for 10 minutes, no timeout
$result = DB::select("SELECT * FROM huge_table");
```

**Why it's wrong:**
- Query can run indefinitely
- Connection timeout only affects initial connect
- No protection against runaway queries

## Good Example

```php
// config/database.php
'pgsql' => [
    'url' => env('DATABASE_URL'),
    'options' => [
        PDO::ATTR_TIMEOUT => 5, // Connection timeout: 5 seconds
    ],
],

// Set query timeout per session
// AppServiceProvider.php
public function boot(): void
{
    DB::listen(function ($query) {
        // Log slow queries
        if ($query->time > 1000) {
            Log::warning('Slow query', ['sql' => $query->sql, 'time' => $query->time]);
        }
    });
}

// Set statement timeout globally
DB::statement("SET statement_timeout = '30s'"); // Query timeout: 30 seconds
```

**Why it's better:**
- Both timeouts configured
- Queries limited to reasonable time
- Runaway queries killed

## Project-Specific Notes

**BotFacebook Timeout Strategy:**

| Timeout Type | Setting | Value |
|--------------|---------|-------|
| Connection | PDO::ATTR_TIMEOUT | 5s |
| Web queries | statement_timeout | 5s |
| Job queries | statement_timeout | 30s |
| Migrations | statement_timeout | 5min |

```php
// Dynamic timeout based on context
public function setQueryTimeout(int $seconds): void
{
    DB::statement("SET statement_timeout = '{$seconds}s'");
}

// In job
public function handle(): void
{
    $this->setQueryTimeout(30);
    // Long-running query
}
```
