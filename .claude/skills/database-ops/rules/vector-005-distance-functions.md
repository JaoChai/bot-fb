---
id: vector-005-distance-functions
title: Choosing the Right Distance Function
impact: MEDIUM
impactDescription: "Different distance functions suit different use cases"
category: vector
tags: [vector, distance, cosine, euclidean]
relatedRules: [vector-004-similarity-threshold]
---

## Why This Matters

pgvector supports multiple distance functions. Cosine distance (<=>) is best for text embeddings since it measures angle between vectors, ignoring magnitude. Using the wrong function gives poor results.

## Distance Functions

| Function | Operator | Formula | Best For |
|----------|----------|---------|----------|
| Cosine | `<=>` | 1 - cos(θ) | Text embeddings |
| L2/Euclidean | `<->` | √Σ(a-b)² | Normalized vectors |
| Inner Product | `<#>` | -Σ(a×b) | When vectors are normalized |

## Bad Example

```sql
-- Using L2 for text embeddings (not ideal)
SELECT * FROM documents
ORDER BY embedding <-> $query_embedding
LIMIT 10;

-- Results may not reflect semantic similarity well
```

**Why it's wrong:**
- L2 affected by vector magnitude
- Text embeddings not always normalized
- Cosine is more robust for text

## Good Example

```sql
-- Use cosine for text embeddings
SELECT id, content,
       1 - (embedding <=> $query_embedding) as similarity
FROM documents
WHERE 1 - (embedding <=> $query_embedding) > 0.7
ORDER BY embedding <=> $query_embedding
LIMIT 10;
```

**Why it's better:**
- Cosine ignores magnitude
- Standard for text similarity
- Consistent results

## Project-Specific Notes

**BotFacebook Convention:** Always use cosine (`<=>`) for text embeddings.

```php
// In SemanticSearchService
public function search(array $queryEmbedding): Collection
{
    return DB::select("
        SELECT id, content,
               1 - (embedding <=> ?) as similarity
        FROM knowledge_chunks
        ORDER BY embedding <=> ?
        LIMIT 10
    ", [$this->vectorToString($queryEmbedding), $this->vectorToString($queryEmbedding)]);
}
```

**Index Must Match:**
```sql
-- Index for cosine distance
CREATE INDEX ON documents USING hnsw (embedding vector_cosine_ops);

-- Index for L2 distance
CREATE INDEX ON documents USING hnsw (embedding vector_l2_ops);

-- Index for inner product
CREATE INDEX ON documents USING hnsw (embedding vector_ip_ops);
```
