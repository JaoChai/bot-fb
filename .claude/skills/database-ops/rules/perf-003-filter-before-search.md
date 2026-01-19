---
id: perf-003-filter-before-search
title: Filter Before Vector Search
impact: HIGH
impactDescription: "Filtering after vector search wastes computation on irrelevant rows"
category: perf
tags: [performance, vector, filter, optimization]
relatedRules: [vector-004-similarity-threshold]
---

## Why This Matters

Vector similarity search is expensive. If you search all 1M vectors then filter to one knowledge base's 1K, you wasted 99.9% of computation. Filter first, then search.

## Bad Example

```sql
-- Search all, then filter - SLOW
SELECT * FROM (
    SELECT *, 1 - (embedding <=> ?) as similarity
    FROM knowledge_chunks
    ORDER BY embedding <=> ?
    LIMIT 1000
) sub
WHERE knowledge_base_id = 123  -- Filter after!
LIMIT 10;
```

**Why it's wrong:**
- Searches entire table
- Most results discarded
- Wasted computation

## Good Example

```sql
-- Filter first, then search - FAST
SELECT *, 1 - (embedding <=> ?) as similarity
FROM knowledge_chunks
WHERE knowledge_base_id = 123  -- Filter first!
ORDER BY embedding <=> ?
LIMIT 10;
```

**Why it's better:**
- Only searches relevant subset
- Index can combine filter + search
- Much faster

## Project-Specific Notes

**BotFacebook Pattern:**

```php
// In SemanticSearchService
public function search(int $knowledgeBaseId, array $embedding): Collection
{
    return DB::select("
        SELECT id, content,
               1 - (embedding <=> ?) as similarity
        FROM knowledge_chunks
        WHERE knowledge_base_id = ?  -- Filter FIRST
          AND 1 - (embedding <=> ?) > ?
        ORDER BY embedding <=> ?
        LIMIT ?
    ", [
        $this->vectorToString($embedding),
        $knowledgeBaseId,
        $this->vectorToString($embedding),
        $this->threshold,
        $this->vectorToString($embedding),
        $this->limit,
    ]);
}
```

**Partial Index for Better Performance:**
```sql
-- Index only for specific KB (if one KB is queried frequently)
CREATE INDEX idx_kb_123_embedding
ON knowledge_chunks USING hnsw (embedding vector_cosine_ops)
WHERE knowledge_base_id = 123;
```
