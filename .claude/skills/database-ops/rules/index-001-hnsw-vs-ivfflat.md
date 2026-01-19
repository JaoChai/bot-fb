---
id: index-001-hnsw-vs-ivfflat
title: HNSW vs IVFFlat Index Selection
impact: HIGH
impactDescription: "Choosing wrong index type affects query speed and accuracy"
category: index
tags: [index, hnsw, ivfflat, vector, performance]
relatedRules: [index-002-hnsw-params]
---

## Why This Matters

pgvector offers two index types: IVFFlat and HNSW. IVFFlat builds faster but queries slower. HNSW builds slower but queries faster. Choosing wrong affects both indexing time and search performance.

## Index Comparison

| Feature | IVFFlat | HNSW |
|---------|---------|------|
| Build time | Fast | Slow |
| Query time | Slower | Faster |
| Recall accuracy | Good | Better |
| Memory usage | Lower | Higher |
| Update cost | Re-index needed | Incremental |

## Bad Example

```sql
-- Using IVFFlat for production search (slower queries)
CREATE INDEX ON documents USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);

-- Small dataset with heavy index (unnecessary)
-- 5K rows with HNSW m=32 - overkill
CREATE INDEX ON small_table USING hnsw (embedding vector_cosine_ops) WITH (m = 32, ef_construction = 200);
```

**Why it's wrong:**
- IVFFlat too slow for high-traffic search
- HNSW overkill for small tables

## Good Example

```sql
-- For high-traffic production (100K+ rows)
CREATE INDEX knowledge_chunks_embedding_idx
ON knowledge_chunks
USING hnsw (embedding vector_cosine_ops)
WITH (m = 16, ef_construction = 64);

-- For development/staging or infrequent searches
CREATE INDEX documents_embedding_idx
ON documents
USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 100);

-- For small tables (<10K): No index needed
-- Linear scan is fast enough
```

**Why it's better:**
- HNSW for production search performance
- IVFFlat where build time matters more
- No index for small datasets

## Project-Specific Notes

**BotFacebook Index Strategy:**

| Table | Rows | Index |
|-------|------|-------|
| knowledge_chunks | >100K | HNSW |
| embeddings_cache | <10K | None |
| document_drafts | <5K | None |

```php
// Migration for production tables
DB::statement("
    CREATE INDEX knowledge_chunks_embedding_idx
    ON knowledge_chunks
    USING hnsw (embedding vector_cosine_ops)
    WITH (m = 16, ef_construction = 64)
");
```
