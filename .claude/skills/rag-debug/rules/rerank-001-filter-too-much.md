---
id: rerank-001-filter-too-much
title: Reranker Filtering Too Many Results
impact: HIGH
impactDescription: "Good results removed, insufficient context for AI"
category: rerank
tags: [reranker, threshold, filtering, jina]
relatedRules: [thresh-002-rerank-threshold, search-001-no-results]
---

## Symptom

- Semantic search finds results but reranker returns empty
- Context has fewer chunks than expected
- AI says "no information available" despite documents existing

## Root Cause

1. Rerank threshold too high
2. Reranker model not suited for content
3. Query-document mismatch in reranker
4. Too few candidates sent to reranker

## Diagnosis

### Quick Check

```php
// Compare before/after reranker
$semanticResults = $this->semanticSearch($query, $botId);
Log::info('Before rerank', ['count' => $semanticResults->count()]);

$reranked = $this->reranker->rerank($query, $semanticResults);
Log::info('After rerank', ['count' => $reranked->count()]);

// If reranked is empty but semantic had results, threshold is too high
```

### Detailed Analysis

```php
// Log reranker scores
$results = $this->reranker->rerankWithScores($query, $semanticResults);

foreach ($results as $result) {
    Log::info('Rerank score', [
        'doc_id' => $result->id,
        'content_preview' => substr($result->content, 0, 100),
        'rerank_score' => $result->rerank_score,
        'passes_threshold' => $result->rerank_score >= config('rag.rerank_threshold'),
    ]);
}
```

## Solution

### Fix Steps

1. **Lower rerank threshold**
```php
// config/rag.php
'rerank_threshold' => 0.3,  // Was 0.5, try 0.3
```

2. **Increase candidates**
```php
// Send more candidates to reranker
'reranker' => [
    'enabled' => true,
    'max_candidates' => 50,  // Was 20, send more
    'return_top' => 10,
],
```

3. **Try without reranker**
```php
// Disable to see if results improve
'reranker' => [
    'enabled' => false,
],
```

### Code Fix

```php
// Reranker with fallback
class JinaRerankerService
{
    public function rerank(string $query, Collection $documents): Collection
    {
        if ($documents->isEmpty()) {
            return collect();
        }

        $threshold = config('rag.rerank_threshold', 0.3);

        try {
            $scored = $this->callJinaAPI($query, $documents);

            // Filter by threshold
            $filtered = $scored->filter(fn($d) => $d->rerank_score >= $threshold);

            // If all filtered out, return top N without threshold
            if ($filtered->isEmpty() && $scored->isNotEmpty()) {
                Log::warning('All results filtered by reranker', [
                    'query' => $query,
                    'threshold' => $threshold,
                    'max_score' => $scored->max('rerank_score'),
                ]);

                // Fallback: return top 3 regardless of threshold
                return $scored->take(3);
            }

            return $filtered
                ->sortByDesc('rerank_score')
                ->take(config('rag.reranker.return_top', 10));

        } catch (\Exception $e) {
            Log::error('Reranker failed', ['error' => $e->getMessage()]);
            // Fallback to semantic ordering
            return $documents->take(10);
        }
    }

    private function callJinaAPI(string $query, Collection $documents): Collection
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.jina.api_key'),
        ])->post('https://api.jina.ai/v1/rerank', [
            'model' => 'jina-reranker-v2-base-multilingual',
            'query' => $query,
            'documents' => $documents->pluck('content')->toArray(),
            'top_n' => min($documents->count(), 50),
        ]);

        if (!$response->successful()) {
            throw new JinaAPIException($response->body());
        }

        $results = $response->json('results');

        return $documents->map(function ($doc, $index) use ($results) {
            $doc->rerank_score = $results[$index]['relevance_score'] ?? 0;
            return $doc;
        });
    }
}
```

## Verification

```php
// Test reranker not over-filtering
$query = "What is the refund policy?";
$semantic = $this->semanticSearch($query, $botId);
$reranked = $this->reranker->rerank($query, $semantic);

Log::info('Reranker verification', [
    'semantic_count' => $semantic->count(),
    'reranked_count' => $reranked->count(),
    'ratio' => $reranked->count() / max($semantic->count(), 1),
]);

// Ratio should be > 0.2 (keeping at least 20%)
assert($reranked->count() > 0 || $semantic->isEmpty());
```

## Prevention

- Start with low threshold (0.3) and increase
- Log reranker filter ratio
- Alert when ratio < 10%
- Test with sample queries
- A/B test threshold values

## Project-Specific Notes

**BotFacebook Context:**
- Reranker: Jina v2 multilingual
- Default threshold: 0.3
- API key: `JINA_API_KEY`
- Config: `config/rag.php`
