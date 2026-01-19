---
id: index-003-ivfflat-lists
title: IVFFlat Lists Parameter
impact: MEDIUM
impactDescription: "Wrong lists count affects search speed and accuracy"
category: index
tags: [index, ivfflat, lists, tuning]
relatedRules: [index-001-hnsw-vs-ivfflat]
---

## Why This Matters

IVFFlat divides vectors into `lists` (clusters). Too few lists = slow search (large clusters). Too many lists = poor accuracy (vectors may be in wrong cluster). Rule of thumb: `lists = sqrt(rows)`.

## Bad Example

```sql
-- 1M rows with only 10 lists - slow search
CREATE INDEX ON docs USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 10);

-- 10K rows with 1000 lists - poor accuracy
CREATE INDEX ON small_docs USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 1000);
```

**Why it's wrong:**
- Too few lists: Each list too large, slow scan
- Too many lists: Relevant vectors in other clusters

## Good Example

```sql
-- Calculate based on row count
-- 10K rows: sqrt(10000) = 100 lists
CREATE INDEX ON docs_10k USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 100);

-- 100K rows: sqrt(100000) ≈ 316, round to 300
CREATE INDEX ON docs_100k USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 300);

-- 1M rows: sqrt(1000000) = 1000 lists
CREATE INDEX ON docs_1m USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 1000);
```

**Why it's better:**
- Lists proportional to data size
- Balanced speed and accuracy

## Project-Specific Notes

**BotFacebook IVFFlat Sizing:**

| Rows | Lists | Probes (query) |
|------|-------|----------------|
| 10K | 100 | 10 |
| 100K | 300 | 20 |
| 1M | 1000 | 50 |

```sql
-- Query-time probes (search more lists = better recall)
SET ivfflat.probes = 20;
SELECT * FROM docs ORDER BY embedding <=> ? LIMIT 10;
```
