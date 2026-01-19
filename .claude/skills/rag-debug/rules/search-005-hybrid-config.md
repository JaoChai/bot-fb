---
id: search-005-hybrid-config
title: Hybrid Search Configuration Issues
impact: MEDIUM
impactDescription: "Missing keyword matches or poor fusion results"
category: search
tags: [search, hybrid, keyword, semantic, fusion]
relatedRules: [search-002-wrong-results, thai-003-keyword-boost]
---

## Symptom

- Exact keyword matches not found
- Semantic finds related but not exact
- Thai terms not matching
- Results don't include obvious matches

## Root Cause

1. Hybrid search not enabled
2. Wrong weight balance
3. Full-text search not configured
4. Language settings incorrect
5. Fusion algorithm issues

## Diagnosis

### Quick Check

```php
// Check hybrid search config
dd(config('rag.search'));
// Should show:
// 'type' => 'hybrid',
// 'semantic_weight' => 0.7,
// 'keyword_weight' => 0.3,

// Check full-text search works
$results = DB::select("
    SELECT id, content
    FROM knowledge_base_documents
    WHERE to_tsvector('simple', content) @@ to_tsquery('simple', 'keyword')
    LIMIT 5
");
```

### Detailed Analysis

```sql
-- Check if full-text index exists
SELECT indexname, indexdef
FROM pg_indexes
WHERE tablename = 'knowledge_base_documents'
  AND indexdef LIKE '%tsvector%' OR indexdef LIKE '%gin%';

-- Test keyword search directly
SELECT id, content,
       ts_rank(to_tsvector('simple', content), to_tsquery('simple', 'test')) as rank
FROM knowledge_base_documents
WHERE to_tsvector('simple', content) @@ to_tsquery('simple', 'test')
ORDER BY rank DESC
LIMIT 5;
```

## Solution

### Fix Steps

1. **Enable hybrid search**
```php
// config/rag.php
'search' => [
    'type' => 'hybrid',
    'semantic_weight' => 0.7,
    'keyword_weight' => 0.3,
    'min_keyword_length' => 2,
],
```

2. **Create full-text index**
```sql
-- Create GIN index for full-text search
CREATE INDEX idx_kb_docs_content_fts
ON knowledge_base_documents
USING gin (to_tsvector('simple', content));

-- For Thai, 'simple' works better than 'english'
```

3. **Configure fusion**
```php
// Reciprocal Rank Fusion for combining results
'fusion' => [
    'algorithm' => 'rrf',  // or 'linear'
    'k' => 60,  // RRF constant
],
```

### Code Fix

```php
// Complete hybrid search implementation
class HybridSearchService
{
    public function search(string $query, int $botId): Collection
    {
        $config = config('rag.search');

        // 1. Semantic search
        $semanticResults = $this->semanticSearch($query, $botId);

        // 2. Keyword search
        $keywordResults = $this->keywordSearch($query, $botId);

        // 3. Combine results
        if ($config['fusion']['algorithm'] === 'rrf') {
            return $this->reciprocalRankFusion(
                $semanticResults,
                $keywordResults,
                $config['fusion']['k']
            );
        }

        return $this->linearCombination(
            $semanticResults,
            $keywordResults,
            $config['semantic_weight'],
            $config['keyword_weight']
        );
    }

    private function keywordSearch(string $query, int $botId): Collection
    {
        // Prepare query for full-text search
        $terms = $this->prepareSearchTerms($query);

        if (empty($terms)) {
            return collect();
        }

        $tsQuery = implode(' | ', array_map(fn($t) => "{$t}:*", $terms));

        return DB::table('knowledge_base_documents')
            ->select([
                'id',
                'content',
                DB::raw("ts_rank(to_tsvector('simple', content), to_tsquery('simple', ?)) as score")
            ])
            ->where('bot_id', $botId)
            ->whereRaw("to_tsvector('simple', content) @@ to_tsquery('simple', ?)", [$tsQuery, $tsQuery])
            ->orderByDesc('score')
            ->limit(50)
            ->get();
    }

    private function prepareSearchTerms(string $query): array
    {
        // Extract meaningful terms
        $terms = preg_split('/\s+/', $query);

        return array_filter($terms, function ($term) {
            return mb_strlen($term) >= config('rag.search.min_keyword_length', 2);
        });
    }

    private function reciprocalRankFusion(
        Collection $semantic,
        Collection $keyword,
        int $k = 60
    ): Collection {
        $scores = [];

        // Score from semantic results
        foreach ($semantic->values() as $rank => $doc) {
            $scores[$doc->id] = ($scores[$doc->id] ?? 0) + (1 / ($k + $rank + 1));
        }

        // Score from keyword results
        foreach ($keyword->values() as $rank => $doc) {
            $scores[$doc->id] = ($scores[$doc->id] ?? 0) + (1 / ($k + $rank + 1));
        }

        // Sort by combined score
        arsort($scores);

        // Retrieve documents in order
        return collect(array_keys($scores))
            ->take(config('rag.max_results', 10))
            ->map(fn($id) => KnowledgeBaseDocument::find($id))
            ->filter();
    }

    private function linearCombination(
        Collection $semantic,
        Collection $keyword,
        float $semanticWeight,
        float $keywordWeight
    ): Collection {
        // Normalize scores and combine
        $scores = [];

        $maxSemantic = $semantic->max('similarity') ?: 1;
        $maxKeyword = $keyword->max('score') ?: 1;

        foreach ($semantic as $doc) {
            $normalized = ($doc->similarity / $maxSemantic);
            $scores[$doc->id] = ($scores[$doc->id] ?? 0) + ($normalized * $semanticWeight);
        }

        foreach ($keyword as $doc) {
            $normalized = ($doc->score / $maxKeyword);
            $scores[$doc->id] = ($scores[$doc->id] ?? 0) + ($normalized * $keywordWeight);
        }

        arsort($scores);

        return collect(array_keys($scores))
            ->take(config('rag.max_results', 10))
            ->map(fn($id) => KnowledgeBaseDocument::find($id))
            ->filter();
    }
}
```

## Verification

```php
// Test hybrid search
$query = "refund policy";
$results = app(HybridSearchService::class)->search($query, $botId);

// Check both semantic and keyword are contributing
Log::info('Hybrid search test', [
    'query' => $query,
    'results' => $results->take(5)->pluck('id')->toArray(),
]);

// Verify keyword exact match appears
$exactMatch = $results->first(fn($r) => str_contains($r->content, 'refund policy'));
assert($exactMatch !== null, 'Exact keyword match should appear');
```

## Prevention

- Always use hybrid for production
- Test with keyword-heavy queries
- Monitor search result diversity
- A/B test weight configurations
- Log both search paths

## Project-Specific Notes

**BotFacebook Context:**
- Default: Hybrid with 0.7/0.3 split
- Thai content: Consider 0.6/0.4 (more keyword weight)
- Service: `HybridSearchService`
- Full-text uses 'simple' config (better for Thai)
