---
id: perf-001-explain-analyze
title: Use EXPLAIN ANALYZE for Query Optimization
impact: HIGH
impactDescription: "Guessing query performance without EXPLAIN leads to wrong optimizations"
category: perf
tags: [performance, explain, analyze, query, optimization]
relatedRules: [perf-002-sequential-scan]
---

## Why This Matters

EXPLAIN ANALYZE shows the actual execution plan and timing of queries. Without it, you're guessing at performance issues. The difference between Sequential Scan and Index Scan can be 100x.

## Bad Example

```php
// Guessing at slow query cause
// "It's probably the JOIN, let me add an index..."
Schema::table('messages', function ($table) {
    $table->index('bot_id'); // May not help!
});
```

**Why it's wrong:**
- Guessing at bottleneck
- Index may not be used
- Wasted effort

## Good Example

```sql
-- Step 1: Run EXPLAIN ANALYZE
EXPLAIN ANALYZE
SELECT m.*, c.title
FROM messages m
JOIN conversations c ON m.conversation_id = c.id
WHERE m.bot_id = 123
  AND m.created_at > NOW() - INTERVAL '7 days'
ORDER BY m.created_at DESC
LIMIT 50;

-- Output shows:
-- Seq Scan on messages  (cost=0.00..15234.00 rows=5000 actual time=234.5ms)
--   Filter: (bot_id = 123 AND created_at > ...)
--   Rows Removed by Filter: 450000

-- Problem identified: Sequential scan with filter
```

```php
// Step 2: Add appropriate index based on EXPLAIN output
DB::statement('CREATE INDEX CONCURRENTLY idx_messages_bot_date
              ON messages (bot_id, created_at DESC)');

// Step 3: Verify improvement
// EXPLAIN ANALYZE again - should show Index Scan
```

**Why it's better:**
- Data-driven optimization
- Verifiable improvement
- No guesswork

## Project-Specific Notes

**BotFacebook EXPLAIN Workflow:**

```php
// In artisan command or tinker
$sql = "SELECT * FROM messages WHERE bot_id = 123";
$explain = DB::select("EXPLAIN ANALYZE " . $sql);
foreach ($explain as $row) {
    dump($row->{'QUERY PLAN'});
}
```

**MCP Tool:**
```
mcp__neon__explain_sql_statement(
    projectId="your-project",
    sql="SELECT * FROM messages WHERE bot_id = 123",
    analyze=true
)
```

**Key Metrics:**
| Metric | Good | Bad |
|--------|------|-----|
| Execution time | <100ms | >500ms |
| Rows scanned | ~rows returned | >>rows returned |
| Scan type | Index Scan | Seq Scan (large table) |
