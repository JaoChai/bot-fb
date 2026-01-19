---
id: gotcha-007-slow-vector-search
title: Slow Vector Search Without Index
impact: MEDIUM
impactDescription: "Vector search without index scans entire table"
category: gotcha
tags: [gotcha, vector, index, performance]
relatedRules: [index-001-hnsw-vs-ivfflat]
---

## Why This Matters

Without a vector index, similarity search must compare the query vector against every row in the table. On 100K rows, this takes seconds instead of milliseconds.

## Bad Example

```sql
-- No index on embedding column
SELECT * FROM knowledge_chunks
ORDER BY embedding <=> $query
LIMIT 10;

-- EXPLAIN shows:
-- Seq Scan on knowledge_chunks
-- Sort Method: top-N heapsort
-- Execution Time: 3500ms
```

**Why it's wrong:**
- Full table scan for every query
- O(n) instead of O(log n)
- Gets slower as data grows

## Good Example

```sql
-- Add HNSW index
CREATE INDEX knowledge_chunks_embedding_idx
ON knowledge_chunks
USING hnsw (embedding vector_cosine_ops)
WITH (m = 16, ef_construction = 64);

-- Same query now uses index
-- EXPLAIN shows:
-- Index Scan using knowledge_chunks_embedding_idx
-- Execution Time: 15ms
```

**Why it's better:**
- Index-based approximate search
- O(log n) performance
- Scales with data

## Project-Specific Notes

**BotFacebook Vector Index Checklist:**

```sql
-- Check if vector columns have indexes
SELECT t.relname as table, i.relname as index, a.attname as column
FROM pg_index ix
JOIN pg_class t ON t.oid = ix.indrelid
JOIN pg_class i ON i.oid = ix.indexrelid
JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
JOIN pg_am am ON am.oid = i.relam
WHERE am.amname IN ('ivfflat', 'hnsw');
```

**Add Missing Index:**
```php
// Migration if missing
public function up(): void
{
    // Check if index exists
    $exists = DB::select("
        SELECT 1 FROM pg_indexes
        WHERE indexname = 'knowledge_chunks_embedding_idx'
    ");

    if (empty($exists)) {
        DB::statement("
            CREATE INDEX knowledge_chunks_embedding_idx
            ON knowledge_chunks USING hnsw (embedding vector_cosine_ops)
        ");
    }
}
```
