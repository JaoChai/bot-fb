---
id: vector-004-similarity-threshold
title: Similarity Threshold Tuning
impact: HIGH
impactDescription: "Wrong threshold returns too many irrelevant or too few relevant results"
category: vector
tags: [vector, similarity, threshold, tuning]
relatedRules: [vector-005-distance-functions]
---

## Why This Matters

The similarity threshold filters results by relevance. Too high (0.9) misses good matches. Too low (0.5) includes irrelevant results. The right threshold depends on your data, model, and use case.

## Bad Example

```php
// Problem: Fixed threshold without testing
$results = DB::select("
    SELECT * FROM knowledge_chunks
    WHERE 1 - (embedding <=> ?) > 0.9  -- Too strict!
    LIMIT 10
", [$queryEmbedding]);
// Returns empty even for relevant queries

// Or too loose
WHERE 1 - (embedding <=> ?) > 0.3  -- Too permissive!
// Returns lots of irrelevant results
```

**Why it's wrong:**
- No empirical tuning
- Fixed threshold ignores data characteristics
- Poor user experience

## Good Example

```php
// Configurable threshold with sensible default
class SemanticSearchService
{
    public function search(
        string $query,
        int $knowledgeBaseId,
        ?float $threshold = null
    ): Collection {
        $threshold = $threshold ?? config('rag.similarity_threshold', 0.7);

        return DB::select("
            SELECT id, content,
                   1 - (embedding <=> ?) as similarity
            FROM knowledge_chunks
            WHERE knowledge_base_id = ?
              AND 1 - (embedding <=> ?) > ?
            ORDER BY embedding <=> ?
            LIMIT 10
        ", [
            $this->embedQuery($query),
            $knowledgeBaseId,
            $this->embedQuery($query),
            $threshold,
            $this->embedQuery($query),
        ]);
    }
}

// Dynamic threshold based on result quality
public function searchWithFallback(string $query, int $kbId): Collection
{
    // Start strict
    $results = $this->search($query, $kbId, threshold: 0.8);

    // Fallback to looser threshold if no results
    if ($results->isEmpty()) {
        $results = $this->search($query, $kbId, threshold: 0.6);
    }

    return $results;
}
```

**Why it's better:**
- Configurable threshold
- Fallback for edge cases
- Can tune per use case

## Project-Specific Notes

**BotFacebook Threshold Guidelines:**

| Use Case | Threshold | Reason |
|----------|-----------|--------|
| FAQ matching | 0.8-0.85 | High precision needed |
| Document search | 0.65-0.75 | Balance precision/recall |
| Fallback search | 0.5-0.6 | Better than nothing |

**Config:**
```php
// config/rag.php
return [
    'similarity_threshold' => env('RAG_SIMILARITY_THRESHOLD', 0.7),
    'fallback_threshold' => env('RAG_FALLBACK_THRESHOLD', 0.5),
];
```

**Testing Thresholds:**
```sql
-- Analyze similarity distribution
SELECT
    CASE
        WHEN similarity >= 0.9 THEN '0.9+'
        WHEN similarity >= 0.8 THEN '0.8-0.9'
        WHEN similarity >= 0.7 THEN '0.7-0.8'
        WHEN similarity >= 0.6 THEN '0.6-0.7'
        ELSE '<0.6'
    END as range,
    COUNT(*) as count
FROM (
    SELECT 1 - (embedding <=> ?) as similarity
    FROM knowledge_chunks
) sub
GROUP BY range;
```
