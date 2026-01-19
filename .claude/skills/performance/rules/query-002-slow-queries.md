---
id: query-002-slow-queries
title: Slow Database Queries
impact: HIGH
impactDescription: "Individual queries taking too long, slowing API responses"
category: query
tags: [database, slow-query, optimization, index]
relatedRules: [query-001-n-plus-one, query-003-missing-index]
---

## Symptom

- Specific API endpoints consistently slow
- "Slow query" warnings in logs
- High database CPU during certain operations
- Timeout errors on complex queries

## Root Cause

1. Missing or wrong indexes
2. Full table scans
3. Inefficient query structure
4. Large dataset without limits
5. Complex JOINs or subqueries

## Diagnosis

### Quick Check

```bash
# Check slow queries via Neon MCP
Use list_slow_queries with:
- projectId: your project
- minExecutionTime: 1000  # > 1 second
```

### Detailed Analysis

```sql
-- Run EXPLAIN ANALYZE
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT * FROM messages
WHERE conversation_id = 123
ORDER BY created_at DESC
LIMIT 50;

-- Look for:
-- - Seq Scan (missing index)
-- - High rows examined
-- - Sort operation without index
```

## Measurement

```
Before: Query time > 1000ms
Target: Query time < 100ms
```

## Solution

### Fix Steps

1. **Identify slow query**
```php
// Add query logging
DB::listen(function ($query) {
    if ($query->time > 100) {  // > 100ms
        Log::warning('Slow query', [
            'sql' => $query->sql,
            'bindings' => $query->bindings,
            'time' => $query->time . 'ms',
        ]);
    }
});
```

2. **Analyze with EXPLAIN**
```php
// Use Neon MCP
Use explain_sql_statement with:
- projectId: your project
- sql: "SELECT * FROM messages WHERE..."
- analyze: true
```

3. **Common optimizations**
```php
// Instead of: COUNT(*) for existence
$exists = Model::where('condition')->exists();  // Not count()

// Instead of: Loading all columns
$items = Model::select('id', 'name')->get();

// Instead of: Loading all then filtering
$items = Model::where('status', 'active')->get();  // Not ->filter()

// Limit results
$items = Model::latest()->limit(100)->get();
```

4. **Add missing index**
```php
// Migration for common slow query
Schema::table('messages', function (Blueprint $table) {
    $table->index(['conversation_id', 'created_at']);
});
```

### Query Optimization Patterns

```php
// Before: Slow subquery
$users = User::whereIn('id', function ($q) {
    $q->select('user_id')->from('bots');
})->get();

// After: Join instead
$users = User::join('bots', 'users.id', '=', 'bots.user_id')
    ->select('users.*')
    ->distinct()
    ->get();

// Before: Multiple queries
$bot = Bot::find($id);
$messageCount = Message::where('bot_id', $id)->count();
$userCount = Conversation::where('bot_id', $id)->distinct('user_id')->count();

// After: Single query with aggregates
$bot = Bot::withCount(['messages', 'conversations as unique_users' => function ($q) {
    $q->selectRaw('COUNT(DISTINCT user_id)');
}])->find($id);
```

## Verification

```bash
# Re-run EXPLAIN after fix
# Should show:
# - Index Scan (not Seq Scan)
# - Lower rows examined
# - Faster execution time

# Verify query time improved
curl -w "Time: %{time_total}s\n" -o /dev/null -s https://api.botjao.com/api/endpoint
```

## Prevention

- Review EXPLAIN for new queries
- Monitor slow query logs
- Set up slow query alerts
- Index common query patterns
- Regular performance testing

## Project-Specific Notes

**BotFacebook Context:**
- Slow query threshold: 100ms
- Common slow tables: messages, conversations, knowledge_base_documents
- Use Neon MCP for query analysis
- Target: All queries < 100ms
