---
id: search-004-index-missing
title: Vector Index Missing or Invalid
impact: MEDIUM
impactDescription: "Search performance severely degraded"
category: search
tags: [search, index, pgvector, performance]
relatedRules: [search-003-slow-search, embed-003-dimension-mismatch]
---

## Symptom

- Search extremely slow (seconds vs milliseconds)
- EXPLAIN shows "Seq Scan" instead of "Index Scan"
- CPU spikes during search queries
- Works fine with few documents, slow with many

## Root Cause

1. Index never created
2. Index dropped during migration
3. Index invalidated by dimension change
4. Index needs rebuild (IVFFlat)
5. Wrong index type for workload

## Diagnosis

### Quick Check

```sql
-- List all indexes on table
SELECT indexname, indexdef
FROM pg_indexes
WHERE tablename = 'knowledge_base_documents';

-- Check for vector indexes specifically
SELECT indexname, indexdef
FROM pg_indexes
WHERE tablename = 'knowledge_base_documents'
  AND indexdef LIKE '%vector%' OR indexdef LIKE '%hnsw%' OR indexdef LIKE '%ivfflat%';
```

### Detailed Analysis

```sql
-- Check index health
SELECT
    schemaname,
    tablename,
    indexname,
    idx_scan,
    idx_tup_read,
    idx_tup_fetch
FROM pg_stat_user_indexes
WHERE tablename = 'knowledge_base_documents';

-- Check if index is valid
SELECT indexrelid::regclass as index_name,
       indisvalid as is_valid,
       indisready as is_ready
FROM pg_index
WHERE indrelid = 'knowledge_base_documents'::regclass;
```

## Solution

### Fix Steps

1. **Check current state**
```sql
-- See existing indexes
\di knowledge_base_documents*
```

2. **Create appropriate index**
```sql
-- For small datasets (<100k): HNSW
CREATE INDEX CONCURRENTLY idx_kb_docs_embedding_hnsw
ON knowledge_base_documents
USING hnsw (embedding vector_cosine_ops)
WITH (m = 16, ef_construction = 64);

-- For large datasets (>100k): IVFFlat
-- Calculate lists as sqrt(row_count)
CREATE INDEX CONCURRENTLY idx_kb_docs_embedding_ivfflat
ON knowledge_base_documents
USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 100);
```

3. **Rebuild if invalid**
```sql
-- Rebuild invalid index
REINDEX INDEX CONCURRENTLY idx_kb_docs_embedding_hnsw;
```

### Code Fix

```php
// Migration to ensure index exists
return new class extends Migration
{
    public function up(): void
    {
        // Check row count to determine index type
        $rowCount = DB::table('knowledge_base_documents')
            ->whereNotNull('embedding')
            ->count();

        if ($rowCount < 100000) {
            // Use HNSW for smaller datasets
            DB::statement("
                CREATE INDEX IF NOT EXISTS idx_kb_docs_embedding
                ON knowledge_base_documents
                USING hnsw (embedding vector_cosine_ops)
                WITH (m = 16, ef_construction = 64)
            ");
        } else {
            // Use IVFFlat for larger datasets
            $lists = max(100, (int) sqrt($rowCount));
            DB::statement("
                CREATE INDEX IF NOT EXISTS idx_kb_docs_embedding
                ON knowledge_base_documents
                USING ivfflat (embedding vector_cosine_ops)
                WITH (lists = {$lists})
            ");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_kb_docs_embedding');
    }
};

// Artisan command to rebuild index
class RebuildVectorIndex extends Command
{
    protected $signature = 'rag:rebuild-index {--type=hnsw}';

    public function handle(): int
    {
        $type = $this->option('type');
        $rowCount = DB::table('knowledge_base_documents')
            ->whereNotNull('embedding')
            ->count();

        $this->info("Rebuilding vector index for {$rowCount} documents...");

        // Drop existing
        DB::statement('DROP INDEX IF EXISTS idx_kb_docs_embedding');

        // Create new
        if ($type === 'hnsw') {
            DB::statement("
                CREATE INDEX idx_kb_docs_embedding
                ON knowledge_base_documents
                USING hnsw (embedding vector_cosine_ops)
                WITH (m = 16, ef_construction = 64)
            ");
        } else {
            $lists = max(100, (int) sqrt($rowCount));
            DB::statement("
                CREATE INDEX idx_kb_docs_embedding
                ON knowledge_base_documents
                USING ivfflat (embedding vector_cosine_ops)
                WITH (lists = {$lists})
            ");
        }

        $this->info('Index rebuilt successfully!');
        return 0;
    }
}
```

## Verification

```sql
-- Verify index exists and is valid
SELECT indexname, indexdef
FROM pg_indexes
WHERE tablename = 'knowledge_base_documents'
  AND (indexdef LIKE '%hnsw%' OR indexdef LIKE '%ivfflat%');

-- Verify index is used
EXPLAIN (ANALYZE)
SELECT id FROM knowledge_base_documents
WHERE bot_id = 1
ORDER BY embedding <=> '[...]'::vector
LIMIT 10;
-- Should show "Index Scan" not "Seq Scan"

-- Benchmark
\timing on
SELECT COUNT(*) FROM (
    SELECT id FROM knowledge_base_documents
    WHERE bot_id = 1
    ORDER BY embedding <=> '[...]'::vector
    LIMIT 10
) t;
-- Should be < 100ms
```

## Prevention

- Include index in migrations
- Monitor index usage stats
- Schedule periodic REINDEX for IVFFlat
- Alert on Seq Scan in slow query log
- Test after dimension changes

## Project-Specific Notes

**BotFacebook Context:**
- Index type: HNSW (current ~10k docs)
- Command: `php artisan rag:rebuild-index`
- Migration: `database/migrations/*_create_vector_index.php`
- Neon auto-scales, index persists
