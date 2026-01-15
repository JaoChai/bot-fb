# Query Optimization Guide

## Finding Slow Queries

### Using PostgreSQL Logs

```sql
-- Enable slow query logging
SET log_min_duration_statement = 100; -- Log queries > 100ms
```

### Using Laravel Query Log

```php
DB::enableQueryLog();

// ... your code ...

dd(DB::getQueryLog());
```

### Using EXPLAIN ANALYZE

```sql
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT * FROM messages
WHERE conversation_id = 123
ORDER BY created_at DESC
LIMIT 50;
```

## Understanding EXPLAIN Output

### Key Metrics

| Metric | Good | Concerning |
|--------|------|------------|
| Actual Time | < 10ms | > 100ms |
| Rows | Close to estimate | 10x+ difference |
| Buffers | Low shared read | High shared read |
| Loops | 1 | > 100 |

### Scan Types (Best to Worst)

1. **Index Scan** - Using index, sorted
2. **Index Only Scan** - Index covers all columns
3. **Bitmap Index Scan** - Multiple indexes combined
4. **Sequential Scan** - Full table scan (avoid!)

## Common N+1 Problem

### Detecting N+1

```php
// ❌ N+1 Problem - 1 query for bots + N queries for users
$bots = Bot::all();
foreach ($bots as $bot) {
    echo $bot->user->name; // Query executed per bot!
}

// Query log shows:
// SELECT * FROM bots
// SELECT * FROM users WHERE id = 1
// SELECT * FROM users WHERE id = 2
// ... (N more queries)
```

### Fixing N+1

```php
// ✅ Eager Loading - Only 2 queries total
$bots = Bot::with('user')->get();
foreach ($bots as $bot) {
    echo $bot->user->name; // No additional query
}

// Query log shows:
// SELECT * FROM bots
// SELECT * FROM users WHERE id IN (1, 2, 3, ...)
```

### Complex Eager Loading

```php
// Multiple relationships
$bots = Bot::with(['user', 'settings', 'flows'])->get();

// Nested relationships
$bots = Bot::with(['conversations.messages'])->get();

// Constrained eager loading
$bots = Bot::with(['conversations' => function ($query) {
    $query->where('is_active', true)
          ->orderBy('updated_at', 'desc')
          ->limit(5);
}])->get();

// Counting without loading
$bots = Bot::withCount('conversations')->get();
// Access via $bot->conversations_count
```

## Index Optimization

### When to Add Index

| Scenario | Index Type |
|----------|------------|
| WHERE column = value | B-tree (default) |
| WHERE column IN (...) | B-tree |
| WHERE column LIKE 'prefix%' | B-tree |
| ORDER BY column | B-tree |
| Full-text search | GIN |
| JSONB queries | GIN |
| Array contains | GIN |
| Geospatial | GiST |
| Vector similarity | HNSW/IVFFlat |

### Creating Indexes

```sql
-- Single column
CREATE INDEX idx_messages_conversation_id ON messages (conversation_id);

-- Composite (order matters!)
CREATE INDEX idx_messages_conv_created ON messages (conversation_id, created_at DESC);

-- Partial index (smaller, faster)
CREATE INDEX idx_active_bots ON bots (user_id) WHERE is_active = true;

-- Include columns (covering index)
CREATE INDEX idx_messages_covering ON messages (conversation_id)
INCLUDE (content, created_at);
```

### Non-Blocking Index Creation

```sql
-- Won't lock table (PostgreSQL)
CREATE INDEX CONCURRENTLY idx_name ON table (column);
```

## Query Patterns

### Pagination

```php
// ❌ Slow for large offsets
$messages = Message::skip(10000)->take(20)->get();

// ✅ Cursor pagination (constant time)
$messages = Message::where('id', '<', $lastId)
    ->orderBy('id', 'desc')
    ->limit(20)
    ->get();

// ✅ Laravel cursor pagination
$messages = Message::orderBy('id', 'desc')->cursorPaginate(20);
```

### Counting

```php
// ❌ Loads all records to count
$count = Bot::all()->count();

// ✅ Database count
$count = Bot::count();

// ✅ Count with conditions
$count = Bot::where('is_active', true)->count();
```

### Selecting Columns

```php
// ❌ Selects all columns
$bots = Bot::all();

// ✅ Select only needed columns
$bots = Bot::select('id', 'name', 'platform')->get();
```

### Chunking Large Results

```php
// ❌ Memory exhaustion
$messages = Message::all();
foreach ($messages as $message) {
    // process
}

// ✅ Process in chunks
Message::chunk(1000, function ($messages) {
    foreach ($messages as $message) {
        // process
    }
});

// ✅ Lazy collection (memory efficient)
Message::lazy()->each(function ($message) {
    // process
});
```

## JSONB Optimization

### Index JSONB Fields

```sql
-- GIN index for containment queries
CREATE INDEX idx_settings_jsonb ON bot_settings USING GIN (settings);

-- Expression index for specific field
CREATE INDEX idx_settings_model ON bot_settings ((settings->>'model'));
```

### Query Patterns

```sql
-- ✅ Uses GIN index
SELECT * FROM bot_settings WHERE settings @> '{"model": "gpt-4"}';

-- ❌ Doesn't use index well
SELECT * FROM bot_settings WHERE settings->>'model' = 'gpt-4';

-- ✅ Uses expression index
SELECT * FROM bot_settings WHERE (settings->>'model') = 'gpt-4';
```

## Caching Strategies

### Query Cache

```php
$stats = Cache::remember('bot.stats.'.$botId, 3600, function () use ($botId) {
    return DB::table('messages')
        ->where('bot_id', $botId)
        ->selectRaw('COUNT(*) as total, DATE(created_at) as date')
        ->groupBy('date')
        ->get();
});
```

### Cache Invalidation

```php
// When data changes
Bot::updated(function ($bot) {
    Cache::forget('bot.stats.'.$bot->id);
});
```

## Monitoring

### Track Slow Queries

```php
// AppServiceProvider
DB::listen(function ($query) {
    if ($query->time > 100) {
        Log::warning('Slow query', [
            'sql' => $query->sql,
            'bindings' => $query->bindings,
            'time' => $query->time,
        ]);
    }
});
```

### Neon Dashboard

- Check Query Statistics
- Monitor Connection Pool
- Review Slow Query Log
