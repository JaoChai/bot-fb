---
id: search-002-wrong-results
title: Search Returns Wrong/Irrelevant Results
impact: HIGH
impactDescription: "AI uses wrong context, gives incorrect answers"
category: search
tags: [search, relevance, chunking, reranker]
relatedRules: [rerank-001-filter-too-much, rerank-004-ordering-issues]
---

## Symptom

- Top results not relevant to query
- Obvious matches ranked low
- Similar scores for unrelated content
- AI gives answers from wrong documents

## Root Cause

1. Poor chunking (important context split)
2. No reranker to improve ordering
3. Embedding model not suited for content
4. Query too vague or too long
5. Duplicate content inflating results

## Diagnosis

### Quick Check

```sql
-- Check top results for a query
SELECT id, LEFT(content, 200) as preview,
       1 - (embedding <=> $query_embedding::vector) as similarity
FROM knowledge_base_documents
WHERE bot_id = $bot_id
ORDER BY embedding <=> $query_embedding::vector
LIMIT 10;

-- Manually review: Are these relevant?
```

### Detailed Analysis

```php
// Compare search vs reranked results
$semanticResults = $this->semanticSearch($query, $botId);
$rerankedResults = $this->reranker->rerank($query, $semanticResults);

Log::info('Search comparison', [
    'query' => $query,
    'semantic_order' => $semanticResults->pluck('id')->toArray(),
    'reranked_order' => $rerankedResults->pluck('id')->toArray(),
    'order_changed' => $semanticResults->pluck('id') != $rerankedResults->pluck('id'),
]);
```

## Solution

### Fix Steps

1. **Enable reranker**
```php
// config/rag.php
'reranker' => [
    'enabled' => true,
    'model' => 'jina-reranker-v2-base-multilingual',
],
```

2. **Improve chunking**
```php
// Better chunk settings for context preservation
'chunking' => [
    'chunk_size' => 400,  // words
    'chunk_overlap' => 100, // 25% overlap
    'split_by' => 'paragraph', // Preserve paragraphs
],
```

3. **Add hybrid search**
```php
// Combine semantic + keyword for better relevance
$semanticResults = $this->semanticSearch($query, $botId);
$keywordResults = $this->keywordSearch($query, $botId);
$combined = $this->combineResults($semanticResults, $keywordResults);
```

### Code Fix

```php
// Improved search with multiple signals
class HybridSearchService
{
    public function search(string $query, int $botId): Collection
    {
        // 1. Semantic search
        $semanticResults = $this->semanticSearch->search($query, $botId);

        // 2. Keyword search
        $keywordResults = $this->keywordSearch->search($query, $botId);

        // 3. Combine with RRF (Reciprocal Rank Fusion)
        $combined = $this->reciprocalRankFusion([
            'semantic' => $semanticResults,
            'keyword' => $keywordResults,
        ]);

        // 4. Rerank top candidates
        $reranked = $this->reranker->rerank($query, $combined->take(20));

        return $reranked;
    }

    private function reciprocalRankFusion(array $resultSets, int $k = 60): Collection
    {
        $scores = [];

        foreach ($resultSets as $results) {
            foreach ($results->values() as $rank => $doc) {
                $id = $doc->id;
                $scores[$id] = ($scores[$id] ?? 0) + (1 / ($k + $rank + 1));
            }
        }

        arsort($scores);

        return collect($scores)
            ->keys()
            ->map(fn($id) => KnowledgeBaseDocument::find($id));
    }
}

// Better chunking
class ChunkingService
{
    public function chunk(string $content): array
    {
        $chunks = [];

        // Split by paragraphs first
        $paragraphs = preg_split('/\n\n+/', $content);

        $currentChunk = '';
        $wordCount = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraphWords = str_word_count($paragraph);

            if ($wordCount + $paragraphWords > config('rag.chunking.chunk_size')) {
                if ($currentChunk) {
                    $chunks[] = trim($currentChunk);
                }
                $currentChunk = $paragraph;
                $wordCount = $paragraphWords;
            } else {
                $currentChunk .= "\n\n" . $paragraph;
                $wordCount += $paragraphWords;
            }
        }

        if ($currentChunk) {
            $chunks[] = trim($currentChunk);
        }

        // Add overlap between chunks
        return $this->addOverlap($chunks);
    }
}
```

## Verification

```php
// Test with known query-document pair
$query = "What is your refund policy?";
$expectedDocId = 123; // Known relevant document

$results = $this->searchService->search($query, $botId);
$topIds = $results->take(3)->pluck('id')->toArray();

assert(in_array($expectedDocId, $topIds), 'Expected document not in top 3');
```

## Prevention

- Always use reranker for production
- Test with representative queries
- Review chunking quality periodically
- Monitor relevance feedback
- A/B test different settings

## Project-Specific Notes

**BotFacebook Context:**
- Reranker: Jina (JINA_API_KEY)
- Hybrid search enabled by default
- Chunk settings in `config/rag.php`
- Relevance feedback stored in `search_feedback` table
