---
id: metrics-002-slow-queries
title: Slow Database Query Monitoring
impact: HIGH
impactDescription: "Slow queries cause API delays and resource exhaustion"
category: metrics
tags: [metrics, database, performance, neon]
relatedRules: [metrics-001-api-response-time, sentry-003-performance-monitoring]
---

## Symptom

- API responses slow
- Database CPU high
- Connection pool exhausted
- Timeouts on queries

## Root Cause

1. Missing indexes
2. N+1 query patterns
3. Large table scans
4. Unoptimized queries
5. Connection pool issues

## Diagnosis

### Quick Check

```bash
# List slow queries via Neon MCP
mcp__neon__list_slow_queries(
  projectId='your-project-id',
  minExecutionTime=1000
)
```

### Detailed Analysis

```bash
# Run EXPLAIN on slow query
mcp__neon__explain_sql_statement(
  projectId='your-project-id',
  sql='SELECT * FROM messages WHERE conversation_id = 123',
  analyze=true
)
```

## Solution

### Find Slow Queries

```bash
# Queries > 1 second
mcp__neon__list_slow_queries(
  projectId='your-project-id',
  minExecutionTime=1000
)

# Queries > 500ms
mcp__neon__list_slow_queries(
  projectId='your-project-id',
  minExecutionTime=500
)

# With limit
mcp__neon__list_slow_queries(
  projectId='your-project-id',
  minExecutionTime=100,
  limit=20
)
```

### Analyze Query Performance

```bash
# Get execution plan
mcp__neon__explain_sql_statement(
  projectId='your-project-id',
  sql='SELECT * FROM messages WHERE bot_id = $1 ORDER BY created_at DESC LIMIT 50',
  analyze=true
)
```

### Check Connection Stats

```bash
# Active connections
mcp__neon__run_sql(
  projectId='your-project-id',
  sql='SELECT count(*), state FROM pg_stat_activity GROUP BY state'
)

# Table sizes
mcp__neon__run_sql(
  projectId='your-project-id',
  sql='SELECT relname, pg_size_pretty(pg_total_relation_size(relid)) as size FROM pg_catalog.pg_statio_user_tables ORDER BY pg_total_relation_size(relid) DESC LIMIT 10'
)
```

### Enable Query Logging in Laravel

```php
// AppServiceProvider.php
public function boot(): void
{
    if (config('app.debug')) {
        DB::listen(function ($query) {
            if ($query->time > 100) {
                Log::warning('slow.query', [
                    'sql' => $query->sql,
                    'time_ms' => $query->time,
                    'bindings' => $query->bindings,
                ]);
            }
        });
    }
}
```

### Common Slow Query Patterns

| Pattern | Cause | Solution |
|---------|-------|----------|
| Seq Scan | Missing index | Add index |
| Sort | No index for ORDER BY | Add covering index |
| Nested Loop | N+1 queries | Eager loading |
| Large rows | SELECT * | Select specific columns |
| No LIMIT | Full table scan | Add pagination |

### Query Performance Targets

| Query Type | Target | Alert |
|------------|--------|-------|
| Simple lookup | < 10ms | > 50ms |
| Join query | < 50ms | > 200ms |
| Aggregate | < 100ms | > 500ms |
| Search | < 200ms | > 1s |

## Verification

```bash
# Verify query optimized
mcp__neon__explain_sql_statement(
  projectId='your-project-id',
  sql='your optimized query',
  analyze=true
)

# Should see Index Scan, not Seq Scan
```

## Prevention

- Log slow queries automatically
- Review pg_stat_statements regularly
- Add indexes proactively
- Use eager loading
- Monitor connection pool

## Project-Specific Notes

**BotFacebook Context:**
- Neon project ID: Check Railway env
- Slow threshold: 100ms warning, 500ms error
- Key tables: messages, conversations, knowledge_base
- Vector queries: Use EXPLAIN for HNSW performance
