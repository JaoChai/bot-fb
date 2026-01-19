---
id: db-002-query-optimization
title: Query Optimization Refactoring
impact: HIGH
impactDescription: "Optimize slow database queries"
category: db
tags: [query, optimization, performance, eloquent]
relatedRules: [db-001-eager-loading, db-003-add-index]
---

## Code Smell

- Query takes > 100ms
- EXPLAIN shows "Seq Scan" on large table
- High "rows examined" vs "rows returned"
- Memory usage spikes on queries
- Pagination feels slow

## Root Cause

1. Missing indexes
2. SELECT * when few columns needed
3. Inefficient WHERE clauses
4. Subqueries that could be JOINs
5. Large OFFSET pagination

## When to Apply

**Apply when:**
- Query > 100ms
- EXPLAIN shows issues
- High CPU on DB server
- Scaling concerns

**Don't apply when:**
- Query already fast
- One-time operations
- Would add complexity

## Solution

### Before (Unoptimized)

```php
// Problem 1: SELECT * when only need few columns
$conversations = Conversation::where('bot_id', $botId)
    ->orderBy('updated_at', 'desc')
    ->get();

// Problem 2: Filtering in PHP instead of SQL
$activeUsers = User::all()
    ->filter(fn($u) => $u->last_active_at > now()->subDays(7));

// Problem 3: Large OFFSET pagination
$messages = Message::where('conversation_id', $id)
    ->offset(10000)
    ->limit(20)
    ->get();

// Problem 4: N queries for aggregation
$stats = [];
foreach ($bots as $bot) {
    $stats[$bot->id] = Message::where('bot_id', $bot->id)->count();
}
```

### After (Optimized)

```php
// Solution 1: Select only needed columns
$conversations = Conversation::where('bot_id', $botId)
    ->select(['id', 'title', 'updated_at', 'message_count'])
    ->orderBy('updated_at', 'desc')
    ->get();

// Solution 2: Filter in SQL
$activeUsers = User::where('last_active_at', '>', now()->subDays(7))
    ->get();

// Solution 3: Cursor pagination
$messages = Message::where('conversation_id', $id)
    ->where('id', '<', $lastId)  // Cursor-based
    ->orderBy('id', 'desc')
    ->limit(20)
    ->get();

// Or use Laravel's cursor pagination
$messages = Message::where('conversation_id', $id)
    ->orderBy('id', 'desc')
    ->cursorPaginate(20);

// Solution 4: Single aggregation query
$stats = Message::whereIn('bot_id', $bots->pluck('id'))
    ->groupBy('bot_id')
    ->selectRaw('bot_id, COUNT(*) as count')
    ->pluck('count', 'bot_id');
```

### Query Analysis Pattern

```php
// Step 1: Enable query log
DB::enableQueryLog();

// Step 2: Run query
$result = YourModel::complexQuery()->get();

// Step 3: Get query and analyze
$query = DB::getQueryLog();
$sql = $query[0]['query'];
$bindings = $query[0]['bindings'];

// Step 4: Run EXPLAIN
$explain = DB::select('EXPLAIN ANALYZE ' . $sql, $bindings);
```

### Common Optimizations

```php
// 1. Use chunking for large datasets
Bot::chunk(100, function ($bots) {
    foreach ($bots as $bot) {
        // Process bot
    }
});

// 2. Use lazy collections for memory
Bot::lazy()->each(function ($bot) {
    // Process without loading all into memory
});

// 3. Use subqueries instead of joins sometimes
$latestMessages = Message::whereColumn('conversation_id', 'conversations.id')
    ->latest()
    ->limit(1)
    ->select('content');

$conversations = Conversation::select([
    '*',
    'last_message' => $latestMessages,
])->get();

// 4. Use raw expressions for complex calculations
$bots = Bot::select([
    'bots.*',
    DB::raw('(SELECT COUNT(*) FROM messages WHERE messages.bot_id = bots.id) as message_count'),
])->get();

// 5. Use upsert for bulk operations
Message::upsert(
    $messages,  // Data array
    ['id'],     // Unique keys
    ['content', 'updated_at']  // Columns to update
);
```

### Step-by-Step

1. **Identify slow query**
   ```php
   DB::listen(function ($query) {
       if ($query->time > 100) {
           Log::warning('Slow query', [
               'sql' => $query->sql,
               'time' => $query->time,
           ]);
       }
   });
   ```

2. **Run EXPLAIN ANALYZE**
   ```sql
   EXPLAIN ANALYZE SELECT * FROM messages
   WHERE conversation_id = 123
   ORDER BY created_at DESC
   LIMIT 20;
   ```

3. **Check for issues**
   - Seq Scan → Add index
   - High rows examined → Filter earlier
   - Sort → Add index for ORDER BY

4. **Optimize query**
   - Add missing indexes
   - Select specific columns
   - Use cursor pagination
   - Move filtering to SQL

5. **Verify improvement**
   - Check execution time
   - Re-run EXPLAIN
   - Monitor in production

## Verification

```bash
# Before optimization
EXPLAIN ANALYZE SELECT * FROM messages WHERE conversation_id = 123;
# Execution Time: 150ms

# After adding index
CREATE INDEX idx_messages_conversation ON messages(conversation_id, created_at);

# Re-check
EXPLAIN ANALYZE SELECT * FROM messages WHERE conversation_id = 123;
# Execution Time: 2ms
```

## Anti-Patterns

- **Premature optimization**: Optimize only measured problems
- **Over-indexing**: Too many indexes slow writes
- **N+1 disguised**: Moving N+1 to raw SQL doesn't fix it
- **Ignoring EXPLAIN**: Guessing instead of measuring

## Project-Specific Notes

**BotFacebook Context:**
- Use Neon MCP tools for EXPLAIN
- Monitor slow queries in Railway logs
- Common slow: messages table queries
- Index strategy: conversation_id + created_at composite
