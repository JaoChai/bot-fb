---
id: vector-007-hybrid-search
title: Hybrid Search (Semantic + Keyword)
impact: MEDIUM
impactDescription: "Combining semantic and keyword search improves result quality"
category: vector
tags: [vector, hybrid, search, keyword]
relatedRules: [vector-004-similarity-threshold]
---

## Why This Matters

Semantic search finds conceptually similar content but may miss exact keyword matches. Keyword search finds exact terms but misses synonyms. Combining both gives better results than either alone.

## Bad Example

```php
// Semantic only - misses exact matches
$results = DB::select("
    SELECT * FROM documents
    ORDER BY embedding <=> ?
    LIMIT 10
", [$queryEmbedding]);

// Keyword only - misses similar concepts
$results = DB::select("
    SELECT * FROM documents
    WHERE content ILIKE ?
", ["%{$query}%"]);
```

**Why it's wrong:**
- Semantic misses "API key" when user types exact phrase
- Keyword misses "authentication token" for "API key" query

## Good Example

```php
public function hybridSearch(string $query, int $kbId): Collection
{
    $embedding = $this->embedQuery($query);

    return DB::select("
        WITH semantic AS (
            SELECT id, content,
                   1 - (embedding <=> ?) as semantic_score
            FROM knowledge_chunks
            WHERE knowledge_base_id = ?
            ORDER BY embedding <=> ?
            LIMIT 50
        ),
        keyword AS (
            SELECT id,
                   ts_rank(
                       to_tsvector('simple', content),
                       plainto_tsquery('simple', ?)
                   ) as keyword_score
            FROM knowledge_chunks
            WHERE knowledge_base_id = ?
              AND to_tsvector('simple', content) @@ plainto_tsquery('simple', ?)
        )
        SELECT s.id, s.content,
               s.semantic_score,
               COALESCE(k.keyword_score, 0) as keyword_score,
               (0.7 * s.semantic_score + 0.3 * COALESCE(k.keyword_score, 0)) as combined_score
        FROM semantic s
        LEFT JOIN keyword k ON s.id = k.id
        ORDER BY combined_score DESC
        LIMIT 10
    ", [
        $this->vectorToString($embedding), $kbId, $this->vectorToString($embedding),
        $query, $kbId, $query
    ]);
}
```

**Why it's better:**
- Combines both approaches
- Weighted scoring (70% semantic, 30% keyword)
- Better recall and precision

## Project-Specific Notes

**BotFacebook Hybrid Weights:**

| Content Type | Semantic | Keyword |
|--------------|----------|---------|
| FAQ | 0.5 | 0.5 |
| Documentation | 0.7 | 0.3 |
| Code | 0.4 | 0.6 |

```php
// config/rag.php
return [
    'hybrid_weights' => [
        'semantic' => 0.7,
        'keyword' => 0.3,
    ],
];
```
