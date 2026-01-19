---
id: perf-006-query-timeout
title: Set Appropriate Query Timeouts
impact: MEDIUM
impactDescription: "Runaway queries without timeout block connections indefinitely"
category: perf
tags: [performance, timeout, query, connection]
relatedRules: [gotcha-001-pool-exhaustion]
---

## Why This Matters

Queries without timeouts can run forever, blocking connections and potentially causing cascading failures. Setting appropriate timeouts ensures runaway queries get killed before causing damage.

## Bad Example

```php
// No timeout - query runs forever if slow
$results = DB::select("
    SELECT * FROM large_table
    WHERE complex_condition
");
// May run for 30 minutes, blocking connection
```

**Why it's wrong:**
- No safety limit
- Connection blocked indefinitely
- May cause pool exhaustion

## Good Example

```php
// Set statement timeout
DB::statement('SET statement_timeout = \'5000\''); // 5 seconds

try {
    $results = DB::select("SELECT * FROM large_table WHERE ...");
} catch (\PDOException $e) {
    if (str_contains($e->getMessage(), 'statement timeout')) {
        Log::warning('Query timed out', ['query' => $sql]);
        throw new QueryTimeoutException('Query took too long');
    }
    throw $e;
}

// Or in config
// config/database.php
'pgsql' => [
    'options' => [
        PDO::ATTR_TIMEOUT => 10, // Connection timeout
    ],
    'search_path' => 'public',
],
```

**Why it's better:**
- Queries killed after timeout
- Connections released
- Prevents cascading failures

## Project-Specific Notes

**BotFacebook Timeout Settings:**

| Query Type | Timeout |
|------------|---------|
| Web request | 5s |
| Background job | 30s |
| Migration | 5min |
| Vector search | 10s |

```php
// Helper for scoped timeout
public function withTimeout(int $seconds, callable $callback)
{
    DB::statement("SET statement_timeout = '{$seconds}s'");
    try {
        return $callback();
    } finally {
        DB::statement("SET statement_timeout = '5s'"); // Reset
    }
}

// Usage
$this->withTimeout(30, function () {
    // Long-running query
});
```
