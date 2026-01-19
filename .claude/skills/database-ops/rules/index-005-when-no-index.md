---
id: index-005-when-no-index
title: When Vector Index Is Unnecessary
impact: MEDIUM
impactDescription: "Small tables don't benefit from indexes - linear scan is fast"
category: index
tags: [index, optimization, small-table, performance]
relatedRules: [index-001-hnsw-vs-ivfflat]
---

## Why This Matters

Creating indexes on small tables wastes resources. Linear scan on <10K vectors is fast enough. Index maintenance overhead may actually slow things down.

## Bad Example

```sql
-- Overkill: HNSW on 1000 rows
CREATE INDEX ON tiny_docs USING hnsw (embedding vector_cosine_ops)
WITH (m = 32, ef_construction = 128);
-- Build time: 30 seconds for marginal improvement
```

**Why it's wrong:**
- Linear scan on 1K rows: ~10ms
- Index adds complexity, maintenance
- No meaningful speed improvement

## Good Example

```sql
-- Small tables: No index needed
-- Just query directly
SELECT * FROM small_docs
ORDER BY embedding <=> ?
LIMIT 10;
-- Fast enough without index

-- Add index only when needed
-- Monitor query times, add when approaching 100ms+
```

**Why it's better:**
- Simpler setup
- No index maintenance
- Fast enough for small data

## Project-Specific Notes

**BotFacebook Index Decision:**

| Rows | Index? | Reason |
|------|--------|--------|
| <1K | No | <10ms scan |
| 1K-10K | Maybe | Depends on query frequency |
| 10K-100K | Yes (IVFFlat) | Noticeable improvement |
| >100K | Yes (HNSW) | Required for performance |

```php
// Check if index needed
$count = KnowledgeChunk::where('knowledge_base_id', $kbId)->count();

if ($count < 5000) {
    // Direct query is fine
    return $this->directSearch($embedding);
} else {
    // Use indexed search
    return $this->indexedSearch($embedding);
}
```
