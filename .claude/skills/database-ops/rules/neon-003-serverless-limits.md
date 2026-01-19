---
id: neon-003-serverless-limits
title: Neon Serverless Compute Limits
impact: MEDIUM
impactDescription: "Understanding Neon's limits prevents unexpected failures"
category: neon
tags: [neon, serverless, limits, compute]
relatedRules: [gotcha-001-pool-exhaustion]
---

## Why This Matters

Neon's serverless compute has limits on connections, compute time, and storage. Exceeding these causes failures or throttling. Understanding limits helps design robust applications.

## Neon Limits (Free Tier)

| Resource | Limit |
|----------|-------|
| Compute hours | 191.9 hrs/month |
| Storage | 0.5 GB |
| Branches | 10 |
| Projects | 1 |
| Direct connections | ~10 |
| Pooled connections | ~100 |

## Bad Example

```php
// Holding connections too long
public function processAll(): void
{
    $items = Item::all(); // Connection held

    foreach ($items as $item) {
        sleep(1); // 1 second per item
        $item->process();
    }
    // Connection held for entire duration
}
// 1000 items = 16 minutes holding one connection
```

**Why it's wrong:**
- Long-held connections exhaust pool
- Serverless may suspend during idle
- Compute hours consumed

## Good Example

```php
// Process in short bursts
public function processAll(): void
{
    Item::query()
        ->where('processed', false)
        ->chunkById(100, function ($items) {
            foreach ($items as $item) {
                ProcessItemJob::dispatch($item->id);
            }
        });
    // Connection released after each chunk
}

// Or use queue for long operations
class ProcessItemJob implements ShouldQueue
{
    public function handle(): void
    {
        $item = Item::find($this->itemId);
        $item->process();
        // Connection released after job
    }
}
```

**Why it's better:**
- Short connection durations
- Allows serverless scaling
- Efficient resource usage

## Project-Specific Notes

**BotFacebook Neon Optimization:**

```php
// Monitor compute usage
// Neon dashboard shows compute hours

// Optimize for serverless
// 1. Keep transactions short
// 2. Use connection pooling
// 3. Set appropriate timeouts

// config/database.php
'pgsql' => [
    'options' => [
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_PERSISTENT => false, // Don't persist, let pooler handle
    ],
],
```

**Monitoring:**
```sql
-- Active connections
SELECT count(*) FROM pg_stat_activity;

-- Long-running queries
SELECT pid, now() - pg_stat_activity.query_start AS duration, query
FROM pg_stat_activity
WHERE state = 'active'
ORDER BY duration DESC;
```
