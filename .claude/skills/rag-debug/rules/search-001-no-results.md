---
id: search-001-no-results
title: Search Returns No Results
impact: HIGH
impactDescription: "Knowledge base appears empty to users"
category: search
tags: [search, semantic, threshold, empty]
relatedRules: [embed-001-null-embedding, thresh-001-semantic-threshold]
---

## Symptom

- "No relevant information found" message
- Empty search results array
- Knowledge base has documents but search finds nothing

## Root Cause

1. Semantic threshold too high
2. No documents with embeddings
3. Query embedding failed
4. Index not built
5. Bot ID mismatch

## Diagnosis

### Quick Check

```sql
-- Check if documents exist
SELECT COUNT(*) as total,
       COUNT(embedding) as with_embedding
FROM knowledge_base_documents
WHERE bot_id = $bot_id;

-- Run direct similarity search (bypass threshold)
SELECT id, LEFT(content, 100) as preview,
       1 - (embedding <=> $query_embedding::vector) as similarity
FROM knowledge_base_documents
WHERE bot_id = $bot_id
  AND embedding IS NOT NULL
ORDER BY embedding <=> $query_embedding::vector
LIMIT 5;
```

### Detailed Analysis

```php
// Debug search pipeline
$debugResult = $this->semanticSearch->searchWithDebug($query, $botId);

Log::info('Search debug', [
    'query' => $query,
    'query_embedding_generated' => !empty($debugResult['query_embedding']),
    'candidates_before_threshold' => $debugResult['candidates_before_threshold'],
    'candidates_after_threshold' => $debugResult['candidates_after_threshold'],
    'threshold' => $debugResult['threshold'],
    'max_similarity' => $debugResult['max_similarity'],
]);
```

## Solution

### Fix Steps

1. **Lower semantic threshold**
```php
// config/rag.php
'semantic_threshold' => 0.6,  // Was 0.7, try 0.6 or 0.5
```

2. **Check embeddings exist**
```sql
-- Regenerate if needed
UPDATE knowledge_base_documents
SET embedding = NULL
WHERE bot_id = $bot_id AND embedding IS NULL;

-- Then run: php artisan rag:regenerate-embeddings
```

3. **Debug with no threshold**
```php
// Temporarily disable threshold to see what's available
$results = $this->search($query, $botId, threshold: 0.0);
Log::info('Results without threshold', ['count' => count($results)]);
```

### Code Fix

```php
// Add fallback search strategy
class SemanticSearchService
{
    public function search(string $query, int $botId, ?float $threshold = null): Collection
    {
        $threshold ??= config('rag.semantic_threshold', 0.7);

        // Generate query embedding
        $queryEmbedding = $this->embeddingService->generate($query);

        // First attempt with normal threshold
        $results = $this->performSearch($queryEmbedding, $botId, $threshold);

        if ($results->isEmpty()) {
            // Log for debugging
            Log::info('No results at threshold', [
                'query' => $query,
                'threshold' => $threshold,
            ]);

            // Try lower threshold
            $fallbackThreshold = max($threshold - 0.2, 0.3);
            $results = $this->performSearch($queryEmbedding, $botId, $fallbackThreshold);

            if ($results->isNotEmpty()) {
                Log::info('Found results at lower threshold', [
                    'fallback_threshold' => $fallbackThreshold,
                    'count' => $results->count(),
                ]);
            }
        }

        return $results;
    }

    private function performSearch(array $embedding, int $botId, float $threshold): Collection
    {
        $embeddingVector = json_encode($embedding);

        return DB::select("
            SELECT id, content,
                   1 - (embedding <=> ?::vector) as similarity
            FROM knowledge_base_documents
            WHERE bot_id = ?
              AND embedding IS NOT NULL
              AND 1 - (embedding <=> ?::vector) >= ?
            ORDER BY embedding <=> ?::vector
            LIMIT 10
        ", [$embeddingVector, $botId, $embeddingVector, $threshold, $embeddingVector]);
    }
}
```

## Verification

```sql
-- Verify search returns results
SELECT COUNT(*) as result_count
FROM knowledge_base_documents
WHERE bot_id = $bot_id
  AND embedding IS NOT NULL
  AND 1 - (embedding <=> $query_embedding::vector) >= 0.6;
-- Should return > 0

-- Check similarity distribution
SELECT
    CASE
        WHEN 1 - (embedding <=> $query_embedding::vector) >= 0.8 THEN '0.8+'
        WHEN 1 - (embedding <=> $query_embedding::vector) >= 0.7 THEN '0.7-0.8'
        WHEN 1 - (embedding <=> $query_embedding::vector) >= 0.6 THEN '0.6-0.7'
        WHEN 1 - (embedding <=> $query_embedding::vector) >= 0.5 THEN '0.5-0.6'
        ELSE '<0.5'
    END as similarity_range,
    COUNT(*) as count
FROM knowledge_base_documents
WHERE bot_id = $bot_id
GROUP BY 1
ORDER BY 1 DESC;
```

## Prevention

- Start with lower threshold (0.6) and increase
- Log similarity scores for analysis
- Monitor "no results" rate
- Test with sample queries during indexing

## Project-Specific Notes

**BotFacebook Context:**
- Default threshold: 0.7 (may be too high for Thai)
- Config: `config/rag.php`
- For Thai content, recommend 0.6-0.65
- Debug command: `php artisan rag:debug-search --query="test" --bot-id=1`
