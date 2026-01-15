# RAG Pipeline Debug Guide

## Pipeline Overview

```
User Query
    ↓
Query Understanding (intent, language)
    ↓
Query Embedding (OpenAI/Cohere)
    ↓
Vector Search (pgvector)
    ↓
Reranking (optional)
    ↓
Context Assembly
    ↓
LLM Generation
    ↓
Response
```

## Debug Steps

### 1. Query Understanding

```php
// Check what query was processed
Log::info('RAG Query', [
    'original' => $query,
    'normalized' => $this->normalizeQuery($query),
    'language' => $this->detectLanguage($query),
]);
```

**Common Issues:**
- Query too short → Expand with synonyms
- Mixed language → Normalize to single language
- Typos → Apply spell correction

### 2. Embedding Generation

```php
// Debug embedding
$embedding = $this->embeddingService->embed($query);

Log::info('Query Embedding', [
    'dimension' => count($embedding),
    'magnitude' => sqrt(array_sum(array_map(fn($x) => $x * $x, $embedding))),
    'model' => config('services.openai.embedding_model'),
]);
```

**Common Issues:**
- Wrong dimension → Check model consistency
- Zero/NaN values → API error, retry
- Different model → Mismatch with stored embeddings

### 3. Vector Search

```php
// Debug search results
$results = $this->search($embedding, $kbId);

Log::info('Vector Search Results', [
    'total_found' => count($results),
    'top_similarity' => $results[0]?->similarity ?? 0,
    'similarity_range' => [
        'min' => collect($results)->min('similarity'),
        'max' => collect($results)->max('similarity'),
    ],
]);
```

**SQL for debugging:**
```sql
-- Check similarity distribution
SELECT
    COUNT(*) as count,
    MIN(1 - (embedding <=> $1)) as min_sim,
    MAX(1 - (embedding <=> $1)) as max_sim,
    AVG(1 - (embedding <=> $1)) as avg_sim
FROM knowledge_chunks
WHERE knowledge_base_id = $2;
```

**Common Issues:**
- All low similarity → Wrong embedding model or bad data
- No results → Threshold too high
- Irrelevant results → Bad chunking strategy

### 4. Reranking

```php
// Debug reranker
$reranked = $this->reranker->rerank($query, $results);

Log::info('Reranking Results', [
    'before_count' => count($results),
    'after_count' => count($reranked),
    'score_changes' => collect($results)->map(fn($r, $i) => [
        'id' => $r->id,
        'vector_rank' => $i,
        'rerank_rank' => collect($reranked)->search(fn($rr) => $rr->id === $r->id),
    ]),
]);
```

**Common Issues:**
- Reranker filtering too much → Lower threshold
- Order not improving → Check reranker model
- Timeout → Reduce batch size

### 5. Context Assembly

```php
// Debug context
$context = $this->assembleContext($reranked);

Log::info('Context Assembly', [
    'total_tokens' => $this->countTokens($context),
    'chunk_count' => count($reranked),
    'truncated' => strlen($context) > $this->maxContextLength,
]);
```

**Common Issues:**
- Context too long → Reduce chunks or summarize
- Missing relevant info → Chunks too small
- Duplicate content → Deduplicate before assembly

### 6. LLM Generation

```php
// Debug LLM call
$response = $this->llm->generate($prompt, $context);

Log::info('LLM Generation', [
    'prompt_tokens' => $response['usage']['prompt_tokens'],
    'completion_tokens' => $response['usage']['completion_tokens'],
    'model' => $response['model'],
    'finish_reason' => $response['choices'][0]['finish_reason'],
]);
```

**Common Issues:**
- Response ignores context → Improve prompt template
- Hallucination → Add citation requirement
- Incomplete answer → Increase max_tokens

## Diagnostic Queries

### Check Knowledge Base Health

```sql
-- Chunk distribution
SELECT
    knowledge_base_id,
    COUNT(*) as chunk_count,
    AVG(LENGTH(content)) as avg_length,
    MIN(LENGTH(content)) as min_length,
    MAX(LENGTH(content)) as max_length
FROM knowledge_chunks
GROUP BY knowledge_base_id;
```

### Find Similar Chunks

```sql
-- Debug why specific chunk wasn't found
SELECT id, content,
       1 - (embedding <=> $1) as similarity
FROM knowledge_chunks
WHERE knowledge_base_id = $2
ORDER BY embedding <=> $1
LIMIT 20;
```

### Embedding Consistency Check

```sql
-- Check embedding dimensions
SELECT
    COUNT(*) as total,
    COUNT(*) FILTER (WHERE vector_dims(embedding) = 1536) as correct_dim,
    COUNT(*) FILTER (WHERE vector_dims(embedding) != 1536) as wrong_dim
FROM knowledge_chunks;
```

## Debug Commands

```bash
# Test search with specific query
php artisan rag:test-search "ราคาสินค้า" --kb=1 --limit=10

# Check embedding model
php artisan rag:check-embeddings --kb=1

# Rebuild embeddings
php artisan rag:rebuild-embeddings --kb=1 --batch=100
```

## Logging Configuration

```php
// config/logging.php
'channels' => [
    'rag' => [
        'driver' => 'daily',
        'path' => storage_path('logs/rag.log'),
        'level' => 'debug',
        'days' => 14,
    ],
],
```

## Metrics to Track

| Metric | Target | Alert Threshold |
|--------|--------|-----------------|
| Search latency | < 200ms | > 500ms |
| Top-1 similarity | > 0.8 | < 0.6 |
| Reranker pass rate | > 50% | < 20% |
| Context token usage | < 4000 | > 6000 |
| Response relevance | > 0.85 | < 0.7 |

## Debug Checklist

- [ ] Query is properly normalized
- [ ] Embedding model matches stored data
- [ ] Vector index is being used (EXPLAIN)
- [ ] Similarity threshold is appropriate
- [ ] Reranker isn't filtering too aggressively
- [ ] Context fits within token limit
- [ ] LLM prompt includes proper instructions
- [ ] Response cites retrieved context
