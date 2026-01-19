---
id: index-002-hnsw-params
title: HNSW Index Parameters (m and ef_construction)
impact: HIGH
impactDescription: "Wrong parameters cause poor search quality or slow builds"
category: index
tags: [index, hnsw, parameters, tuning]
relatedRules: [index-001-hnsw-vs-ivfflat]
---

## Why This Matters

HNSW has two key parameters: `m` (connections per node) and `ef_construction` (build quality). Higher values = better recall but slower build and more memory. Must balance for your data size.

## Parameters Explained

| Parameter | Effect | Range |
|-----------|--------|-------|
| `m` | Connections per node | 8-64 |
| `ef_construction` | Build-time search width | 32-200 |

Higher `m` = better recall, more memory
Higher `ef_construction` = better recall, slower build

## Bad Example

```sql
-- Too low - poor recall
CREATE INDEX ON docs USING hnsw (embedding vector_cosine_ops)
WITH (m = 4, ef_construction = 16);

-- Too high - slow build, excessive memory
CREATE INDEX ON docs USING hnsw (embedding vector_cosine_ops)
WITH (m = 64, ef_construction = 500);
```

**Why it's wrong:**
- Low params: Misses relevant results
- High params: Build takes hours, uses too much memory

## Good Example

```sql
-- For 100K-1M rows (balanced)
CREATE INDEX ON knowledge_chunks
USING hnsw (embedding vector_cosine_ops)
WITH (m = 16, ef_construction = 64);

-- For >1M rows (higher quality needed)
CREATE INDEX ON large_documents
USING hnsw (embedding vector_cosine_ops)
WITH (m = 32, ef_construction = 128);

-- For <100K rows (can use lower)
CREATE INDEX ON small_docs
USING hnsw (embedding vector_cosine_ops)
WITH (m = 12, ef_construction = 40);
```

**Why it's better:**
- Parameters scaled to data size
- Good recall without excessive resources

## Project-Specific Notes

**BotFacebook HNSW Settings:**

| Table Size | m | ef_construction |
|------------|---|-----------------|
| <100K | 12 | 40 |
| 100K-500K | 16 | 64 |
| 500K-1M | 24 | 100 |
| >1M | 32 | 128 |

**Query-time tuning:**
```sql
-- Set ef_search for queries (higher = more accurate, slower)
SET hnsw.ef_search = 100;
SELECT * FROM docs ORDER BY embedding <=> ? LIMIT 10;
```
