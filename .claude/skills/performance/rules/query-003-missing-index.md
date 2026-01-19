---
id: query-003-missing-index
title: Missing Database Indexes
impact: HIGH
impactDescription: "Full table scans causing slow queries"
category: query
tags: [database, index, optimization, postgresql]
relatedRules: [query-002-slow-queries, query-004-explain-analyze]
---

## Symptom

- EXPLAIN shows "Seq Scan" on large tables
- Query time increases with table size
- High rows examined vs rows returned
- Database CPU high during queries

## Root Cause

1. Foreign key columns not indexed
2. Frequently filtered columns not indexed
3. ORDER BY columns not indexed
4. Composite index needed but missing
5. Index dropped or never created

## Diagnosis

### Quick Check

```sql
-- Check if index exists
SELECT indexname, indexdef
FROM pg_indexes
WHERE tablename = 'messages';

-- Check index usage
SELECT schemaname, relname, seq_scan, idx_scan
FROM pg_stat_user_tables
WHERE relname = 'messages';
-- High seq_scan with low idx_scan = missing index
```

### Detailed Analysis

```sql
-- Find tables with high sequential scans
SELECT schemaname, relname,
       seq_scan, idx_scan,
       n_live_tup as row_count
FROM pg_stat_user_tables
WHERE seq_scan > idx_scan
  AND n_live_tup > 10000
ORDER BY seq_scan DESC;

-- Check missing indexes for foreign keys
SELECT
    tc.table_name, kcu.column_name
FROM information_schema.table_constraints tc
JOIN information_schema.key_column_usage kcu
    ON tc.constraint_name = kcu.constraint_name
WHERE tc.constraint_type = 'FOREIGN KEY'
  AND NOT EXISTS (
    SELECT 1 FROM pg_indexes
    WHERE tablename = tc.table_name
      AND indexdef LIKE '%' || kcu.column_name || '%'
  );
```

## Measurement

```
Before: Seq Scan, rows examined >> rows returned
Target: Index Scan, rows examined ≈ rows returned
```

## Solution

### Fix Steps

1. **Add simple index**
```php
// Migration
Schema::table('messages', function (Blueprint $table) {
    $table->index('conversation_id');
    $table->index('created_at');
});
```

2. **Add composite index**
```php
// For queries like: WHERE conversation_id = ? ORDER BY created_at
Schema::table('messages', function (Blueprint $table) {
    $table->index(['conversation_id', 'created_at']);
});
```

3. **Add partial index (PostgreSQL)**
```php
// For queries filtering specific status
DB::statement('
    CREATE INDEX idx_messages_unread
    ON messages (conversation_id)
    WHERE is_read = false
');
```

4. **Add covering index**
```php
// Include all columns needed for query
DB::statement('
    CREATE INDEX idx_messages_lookup
    ON messages (conversation_id, created_at)
    INCLUDE (content, sender_type)
');
```

### Index Selection Guide

| Query Pattern | Index Type |
|--------------|------------|
| `WHERE col = ?` | B-tree (default) |
| `WHERE col BETWEEN ? AND ?` | B-tree |
| `WHERE col LIKE 'x%'` | B-tree |
| `WHERE col LIKE '%x%'` | GIN with pg_trgm |
| `WHERE jsonb_col @> ?` | GIN |
| `ORDER BY col` | B-tree |
| Full-text search | GIN with tsvector |
| Vector similarity | HNSW or IVFFlat |

### Common Indexes to Add

```php
// Migration for typical BotFacebook tables
public function up(): void
{
    // Messages table
    Schema::table('messages', function (Blueprint $table) {
        $table->index('conversation_id');
        $table->index(['conversation_id', 'created_at']);
        $table->index('sender_type');
    });

    // Conversations table
    Schema::table('conversations', function (Blueprint $table) {
        $table->index('bot_id');
        $table->index(['bot_id', 'updated_at']);
        $table->index('platform');
    });

    // Knowledge base documents
    Schema::table('knowledge_base_documents', function (Blueprint $table) {
        $table->index('bot_id');
        $table->index(['bot_id', 'created_at']);
    });
}
```

## Verification

```sql
-- Verify index exists
SELECT indexname FROM pg_indexes WHERE tablename = 'messages';

-- Verify index is being used
EXPLAIN ANALYZE
SELECT * FROM messages
WHERE conversation_id = 123
ORDER BY created_at DESC
LIMIT 50;
-- Should show "Index Scan" not "Seq Scan"
```

## Prevention

- Index all foreign key columns
- Index frequently filtered columns
- Review EXPLAIN for new queries
- Monitor index usage statistics
- Remove unused indexes

## Project-Specific Notes

**BotFacebook Context:**
- Always index: Foreign keys, created_at, updated_at
- Vector indexes: HNSW on embedding columns
- GIN indexes: For JSONB metadata columns
- Check with Neon MCP tools
