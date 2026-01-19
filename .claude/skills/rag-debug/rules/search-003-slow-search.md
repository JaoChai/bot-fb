---
id: search-003-slow-search
title: Search is Slow
impact: MEDIUM
impactDescription: "Bot response time degraded"
category: search
tags: [search, performance, index, optimization]
relatedRules: [search-004-index-missing, thresh-003-context-limit]
---

## Symptom

- Search takes >1 second
- Response time increased recently
- Timeout errors on search
- Database CPU spikes during search

## Root Cause

1. Missing vector index
2. Too many candidates before filtering
3. Large result set returned
4. Inefficient query
5. Connection pool exhaustion

## Diagnosis

### Quick Check

```sql
-- Check query execution plan
EXPLAIN ANALYZE
SELECT id, content
FROM knowledge_base_documents
WHERE bot_id = 1
ORDER BY embedding <=> '[0.1, 0.2, ...]'::vector
LIMIT 10;

-- Look for "Seq Scan" (bad) vs "Index Scan" (good)
```

### Detailed Analysis

```sql
-- Check index exists
SELECT indexname, indexdef
FROM pg_indexes
WHERE tablename = 'knowledge_base_documents'
AND indexdef LIKE '%vector%';

-- Check table size
SELECT pg_size_pretty(pg_total_relation_size('knowledge_base_documents'));

-- Check vector column size
SELECT COUNT(*) as row_count,
       pg_size_pretty(SUM(LENGTH(embedding::text)::bigint)) as embedding_size
FROM knowledge_base_documents
WHERE embedding IS NOT NULL;
```

## Solution

### Fix Steps

1. **Create vector index**
```sql
-- For < 100k rows, use HNSW
CREATE INDEX idx_kb_docs_embedding_hnsw
ON knowledge_base_documents
USING hnsw (embedding vector_cosine_ops)
WITH (m = 16, ef_construction = 64);

-- For > 100k rows, use IVFFlat
CREATE INDEX idx_kb_docs_embedding_ivfflat
ON knowledge_base_documents
USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 100);  -- sqrt(num_rows)
```

2. **Add composite index for bot filtering**
```sql
-- Filter by bot_id first, then vector search
CREATE INDEX idx_kb_docs_bot_embedding
ON knowledge_base_documents (bot_id)
INCLUDE (embedding);
```

3. **Limit candidate set**
```php
// Reduce initial candidates
$results = DB::select("
    SELECT id, content,
           1 - (embedding <=> ?::vector) as similarity
    FROM knowledge_base_documents
    WHERE bot_id = ?
      AND embedding IS NOT NULL
    ORDER BY embedding <=> ?::vector
    LIMIT 50  -- Limit candidates, then rerank top 10
", [$embedding, $botId, $embedding]);
```

### Code Fix

```php
// Optimized search with proper indexing
class SemanticSearchService
{
    public function search(string $query, int $botId): Collection
    {
        // Set search parameters for HNSW
        DB::statement('SET hnsw.ef_search = 40');

        $embedding = $this->embeddingService->generate($query);
        $embeddingJson = json_encode($embedding);

        // Use parameterized query for safety and plan caching
        $results = DB::select("
            SELECT id, content,
                   1 - (embedding <=> ?::vector) as similarity
            FROM knowledge_base_documents
            WHERE bot_id = ?
              AND embedding IS NOT NULL
            ORDER BY embedding <=> ?::vector
            LIMIT ?
        ", [$embeddingJson, $botId, $embeddingJson, config('rag.max_candidates', 50)]);

        return collect($results);
    }
}

// Migration to create optimized index
return new class extends Migration
{
    public function up(): void
    {
        // Drop existing index
        DB::statement('DROP INDEX IF EXISTS idx_kb_docs_embedding');

        // Create HNSW index (faster for ANN search)
        DB::statement("
            CREATE INDEX idx_kb_docs_embedding_hnsw
            ON knowledge_base_documents
            USING hnsw (embedding vector_cosine_ops)
            WITH (m = 16, ef_construction = 64)
        ");

        // Create partial index for active documents
        DB::statement("
            CREATE INDEX idx_kb_docs_active_embedding
            ON knowledge_base_documents (bot_id)
            WHERE embedding IS NOT NULL
        ");
    }
};
```

## Verification

```sql
-- Verify index is being used
EXPLAIN (ANALYZE, BUFFERS)
SELECT id FROM knowledge_base_documents
WHERE bot_id = 1
ORDER BY embedding <=> '[...]'::vector
LIMIT 10;

-- Look for "Index Scan using idx_kb_docs_embedding_hnsw"

-- Benchmark search time
\timing on
SELECT id, 1 - (embedding <=> $query::vector) as sim
FROM knowledge_base_documents
WHERE bot_id = 1
ORDER BY embedding <=> $query::vector
LIMIT 10;
-- Should be < 100ms
```

## Prevention

- Always create index for vector columns
- Monitor query execution time
- Set up alerts for slow queries
- Periodically REINDEX for IVFFlat
- Consider partitioning for large tables

## Project-Specific Notes

**BotFacebook Context:**
- Current: ~10k documents (HNSW recommended)
- Index type: HNSW with m=16, ef_construction=64
- Search target: <100ms
- Connection pool: Neon pooler
