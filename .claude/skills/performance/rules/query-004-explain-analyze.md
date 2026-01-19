---
id: query-004-explain-analyze
title: Using EXPLAIN ANALYZE Effectively
impact: MEDIUM
impactDescription: "Not understanding query execution plans, missing optimization opportunities"
category: query
tags: [database, explain, analyze, postgresql]
relatedRules: [query-002-slow-queries, query-003-missing-index]
---

## Symptom

- Unable to identify why query is slow
- Don't know if index is being used
- Guessing at optimizations
- Making changes that don't help

## Root Cause

1. Not using EXPLAIN ANALYZE
2. Misreading query plans
3. Not understanding cost estimates
4. Missing the real bottleneck
5. Not comparing before/after

## Diagnosis

### Quick Check

```sql
-- Basic EXPLAIN
EXPLAIN
SELECT * FROM messages WHERE conversation_id = 123;

-- Full analysis
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT * FROM messages WHERE conversation_id = 123;
```

### Using Neon MCP

```
Use explain_sql_statement with:
- projectId: your project
- sql: "SELECT * FROM messages WHERE conversation_id = 123"
- analyze: true
```

## Solution

### Reading EXPLAIN Output

```sql
-- Example output interpretation
EXPLAIN (ANALYZE, BUFFERS)
SELECT * FROM messages
WHERE conversation_id = 123
ORDER BY created_at DESC
LIMIT 50;

-- Result:
Limit (cost=0.43..45.12 rows=50 width=256) (actual time=0.052..0.521 rows=50 loops=1)
  -> Index Scan Backward using idx_messages_conv_created on messages
     (cost=0.43..1234.56 rows=1000 width=256)
     (actual time=0.050..0.510 rows=50 loops=1)
     Index Cond: (conversation_id = 123)
     Buffers: shared hit=15
Planning Time: 0.123 ms
Execution Time: 0.567 ms
```

### Key Metrics to Check

| Metric | Meaning | Target |
|--------|---------|--------|
| Seq Scan | Full table scan | Avoid on large tables |
| Index Scan | Using index | Good |
| Rows (estimated vs actual) | Query planner accuracy | Should be close |
| Buffers shared hit | Data in cache | Higher is better |
| Buffers shared read | Disk reads | Lower is better |
| Execution Time | Total time | < 100ms |

### Common Patterns

```sql
-- Bad: Sequential Scan
Seq Scan on messages (cost=0.00..12345.00 rows=100 width=256)
  Filter: (conversation_id = 123)
-- Fix: Add index on conversation_id

-- Bad: Sort operation
Sort (cost=1000.00..1050.00 rows=500)
  Sort Key: created_at
-- Fix: Add index that covers ORDER BY

-- Bad: Nested Loop with many iterations
Nested Loop (actual loops=1000)
-- Fix: Ensure inner table has index

-- Good: Index Scan
Index Scan using idx_messages_conv on messages
  Index Cond: (conversation_id = 123)
```

### Optimization Workflow

```php
// 1. Capture slow query
DB::enableQueryLog();
$result = $this->runSlowOperation();
$queries = DB::getQueryLog();

// 2. Get the SQL
$slowQuery = $queries[0]['query'];
$bindings = $queries[0]['bindings'];

// 3. Run EXPLAIN via Neon MCP
// Use explain_sql_statement tool

// 4. Identify issue (Seq Scan, Sort, etc.)

// 5. Create fix (add index, rewrite query)

// 6. Re-run EXPLAIN to verify improvement
```

### Before/After Comparison

```sql
-- BEFORE optimization
EXPLAIN ANALYZE SELECT * FROM messages WHERE status = 'pending';
-- Seq Scan, 500ms

-- Add index
CREATE INDEX idx_messages_status ON messages (status);

-- AFTER optimization
EXPLAIN ANALYZE SELECT * FROM messages WHERE status = 'pending';
-- Index Scan, 5ms
```

## Verification

```sql
-- Verify optimization worked
-- Should see:
-- 1. Index Scan (not Seq Scan)
-- 2. Lower execution time
-- 3. Rows examined ≈ rows returned
-- 4. High buffer hit ratio
```

## Prevention

- EXPLAIN new queries before deployment
- Compare execution plans in code review
- Document expected query plans
- Set up slow query monitoring
- Regular query plan audits

## Project-Specific Notes

**BotFacebook Context:**
- Use Neon MCP for EXPLAIN ANALYZE
- Target execution time: < 100ms
- Watch for Seq Scans on: messages, conversations, knowledge_base_documents
- HNSW index scans for vector queries
