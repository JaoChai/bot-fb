---
id: thai-003-keyword-boost
title: Thai Keyword Boosting Not Working
impact: MEDIUM
impactDescription: "Exact Thai keyword matches not prioritized in results"
category: thai
tags: [thai, keyword, boost, hybrid, search]
relatedRules: [search-005-hybrid-config, thai-001-query-normalization]
---

## Symptom

- Exact Thai keyword in document but not in top results
- Semantic search finds related but not exact matches
- User searches "คืนเงิน" but exact match ranks low
- Hybrid search not boosting keyword matches

## Root Cause

1. Keyword boost weight too low
2. Full-text search not configured for Thai
3. Thai tokenization issues
4. Keyword matching too strict
5. Normalization mismatch between query and index

## Diagnosis

### Quick Check

```php
// Test keyword matching
$query = "นโยบายคืนเงิน";

// Check if keyword search finds it
$keywordResults = DB::select("
    SELECT id, content,
           ts_rank(to_tsvector('simple', content),
                   plainto_tsquery('simple', ?)) as rank
    FROM knowledge_base_documents
    WHERE to_tsvector('simple', content) @@ plainto_tsquery('simple', ?)
    ORDER BY rank DESC
    LIMIT 5
", [$query, $query]);

Log::info('Keyword search results', [
    'query' => $query,
    'found' => count($keywordResults),
    'top_rank' => $keywordResults[0]->rank ?? 0,
]);
```

### Detailed Analysis

```php
// Compare semantic vs keyword results
$query = "คืนเงิน";

$semantic = $this->semanticSearch($query, $botId);
$keyword = $this->keywordSearch($query, $botId);

$semanticIds = $semantic->pluck('id')->toArray();
$keywordIds = $keyword->pluck('id')->toArray();

// Find exact matches not in semantic top 5
$exactMatches = $keyword->filter(fn($d) => str_contains($d->content, $query));
$missedInSemantic = $exactMatches->pluck('id')
    ->filter(fn($id) => !in_array($id, array_slice($semanticIds, 0, 5)));

Log::info('Keyword boost analysis', [
    'query' => $query,
    'semantic_top5' => array_slice($semanticIds, 0, 5),
    'keyword_matches' => $keywordIds,
    'exact_matches_count' => $exactMatches->count(),
    'missed_in_semantic_top5' => $missedInSemantic->toArray(),
]);
```

## Solution

### Fix Steps

1. **Increase keyword weight**
```php
// config/rag.php
'search' => [
    'type' => 'hybrid',
    'semantic_weight' => 0.6,  // Was 0.7
    'keyword_weight' => 0.4,   // Was 0.3 - increase for Thai
],
```

2. **Use simple tokenizer**
```sql
-- PostgreSQL simple config works better for Thai
-- Don't use 'english' or 'thai' configs
CREATE INDEX idx_kb_docs_content_fts_simple
ON knowledge_base_documents
USING gin (to_tsvector('simple', content));
```

3. **Add exact match boost**
```php
// Boost exact matches in fusion
private function boostExactMatches(Collection $results, string $query): Collection
{
    return $results->map(function ($doc) use ($query) {
        if (mb_stripos($doc->content, $query) !== false) {
            $doc->boost_score = ($doc->fusion_score ?? 0) * 1.5;
        } else {
            $doc->boost_score = $doc->fusion_score ?? 0;
        }
        return $doc;
    })->sortByDesc('boost_score');
}
```

### Code Fix

```php
// Thai-optimized hybrid search
class ThaiHybridSearchService
{
    private float $semanticWeight = 0.6;
    private float $keywordWeight = 0.4;
    private float $exactMatchBoost = 1.5;

    public function search(string $query, int $botId): Collection
    {
        // Normalize query
        $normalizedQuery = $this->normalizer->normalize($query);

        // 1. Semantic search
        $semantic = $this->semanticSearch($normalizedQuery, $botId);

        // 2. Keyword search with Thai support
        $keyword = $this->keywordSearchThai($normalizedQuery, $botId);

        // 3. Combine with RRF
        $fused = $this->reciprocalRankFusion($semantic, $keyword);

        // 4. Boost exact matches
        $boosted = $this->boostExactMatches($fused, $normalizedQuery);

        return $boosted->take(config('rag.max_results', 10));
    }

    private function keywordSearchThai(string $query, int $botId): Collection
    {
        // Extract Thai words (Thai doesn't use spaces consistently)
        $terms = $this->extractThaiTerms($query);

        if (empty($terms)) {
            return collect();
        }

        // Build OR query for all terms
        $tsQuery = implode(' | ', array_map(fn($t) => $t . ':*', $terms));

        return DB::table('knowledge_base_documents')
            ->select([
                'id',
                'content',
                DB::raw("ts_rank(to_tsvector('simple', content), to_tsquery('simple', ?)) as keyword_score")
            ])
            ->where('bot_id', $botId)
            ->whereRaw("to_tsvector('simple', content) @@ to_tsquery('simple', ?)", [$tsQuery, $tsQuery])
            ->orderByDesc('keyword_score')
            ->limit(30)
            ->get();
    }

    private function extractThaiTerms(string $query): array
    {
        // Simple Thai term extraction
        // Split on whitespace and filter
        $terms = preg_split('/\s+/', $query);

        return array_filter($terms, function ($term) {
            // Keep terms with Thai characters, min 2 chars
            return mb_strlen($term) >= 2 &&
                   preg_match('/[\x{0E00}-\x{0E7F}]/u', $term);
        });
    }

    private function reciprocalRankFusion(
        Collection $semantic,
        Collection $keyword,
        int $k = 60
    ): Collection {
        $scores = [];
        $docs = [];

        // Score from semantic
        foreach ($semantic->values() as $rank => $doc) {
            $id = $doc->id;
            $scores[$id] = ($scores[$id] ?? 0) + ($this->semanticWeight / ($k + $rank + 1));
            $docs[$id] = $doc;
        }

        // Score from keyword
        foreach ($keyword->values() as $rank => $doc) {
            $id = $doc->id;
            $scores[$id] = ($scores[$id] ?? 0) + ($this->keywordWeight / ($k + $rank + 1));
            $docs[$id] = $docs[$id] ?? $doc;
        }

        // Sort and return
        arsort($scores);

        return collect(array_keys($scores))->map(function ($id) use ($docs, $scores) {
            $doc = $docs[$id];
            $doc->fusion_score = $scores[$id];
            return $doc;
        });
    }

    private function boostExactMatches(Collection $results, string $query): Collection
    {
        // Normalize query for matching
        $normalizedQuery = mb_strtolower($query);
        $queryTerms = $this->extractThaiTerms($query);

        return $results->map(function ($doc) use ($normalizedQuery, $queryTerms) {
            $content = mb_strtolower($doc->content);
            $boost = 1.0;

            // Full query match - highest boost
            if (mb_strpos($content, $normalizedQuery) !== false) {
                $boost = $this->exactMatchBoost;
            }
            // Any term match - smaller boost
            else {
                foreach ($queryTerms as $term) {
                    if (mb_strpos($content, mb_strtolower($term)) !== false) {
                        $boost = max($boost, 1.2);
                        break;
                    }
                }
            }

            $doc->final_score = ($doc->fusion_score ?? 0) * $boost;
            $doc->boost_applied = $boost;
            return $doc;
        })->sortByDesc('final_score')->values();
    }
}

// Config for Thai-heavy content
// config/rag.php
return [
    'search' => [
        'type' => 'hybrid',
        'semantic_weight' => 0.6,
        'keyword_weight' => 0.4,
        'exact_match_boost' => 1.5,
        'min_keyword_length' => 2,  // Thai chars are meaningful even short
    ],
];
```

## Verification

```php
// Test keyword boost working
$query = "นโยบายคืนเงิน";

// Create test document with exact match
$testDoc = KnowledgeBaseDocument::create([
    'bot_id' => $botId,
    'content' => "นโยบายคืนเงิน: สามารถคืนได้ภายใน 30 วัน",
    'embedding' => $this->embeddingService->embed("นโยบายคืนเงิน"),
]);

// Search and verify
$results = $this->hybridSearch->search($query, $botId);

// Exact match should be in top 3
$topIds = $results->take(3)->pluck('id')->toArray();
assert(in_array($testDoc->id, $topIds), 'Exact match should be in top 3');

// Check boost was applied
$exactResult = $results->firstWhere('id', $testDoc->id);
assert($exactResult->boost_applied > 1.0, 'Boost should be applied');

Log::info('Keyword boost verification', [
    'query' => $query,
    'exact_match_rank' => $results->pluck('id')->search($testDoc->id),
    'boost_applied' => $exactResult->boost_applied,
]);

// Cleanup
$testDoc->delete();
```

## Prevention

- Test with Thai exact match queries
- Monitor exact match vs semantic ranking
- A/B test keyword weight settings
- Log boost applications
- Regularly audit search quality

## Project-Specific Notes

**BotFacebook Context:**
- Default weights: 60% semantic, 40% keyword
- Exact match boost: 1.5x
- Full-text config: 'simple' (not 'thai')
- Min keyword length: 2 chars for Thai
