---
id: embed-002-model-consistency
title: Embedding Model Mismatch Between Index and Query
impact: CRITICAL
impactDescription: "Search returns completely wrong results due to incompatible embeddings"
category: embed
tags: [embedding, model, consistency, vector]
relatedRules: [embed-003-dimension-mismatch, search-002-wrong-results]
---

## Symptom

- Search returns seemingly random results
- Similarity scores near 0 for obviously related content
- Recently indexed documents not found
- Old documents found but new ones aren't

## Root Cause

Different embedding models were used for:
1. Indexing existing documents
2. Querying new searches

Models produce incompatible vector spaces even with same dimensions.

## Diagnosis

### Quick Check

```php
// Check current model
Log::info('Current embedding model', [
    'index_model' => config('rag.embedding.index_model'),
    'query_model' => config('rag.embedding.query_model'),
]);

// Check document metadata for model used
```

```sql
-- If storing model in metadata
SELECT
    metadata->>'embedding_model' as model,
    COUNT(*) as count
FROM knowledge_base_documents
WHERE bot_id = $bot_id
GROUP BY metadata->>'embedding_model';
```

### Detailed Analysis

```php
// Compare random document similarity
$doc1 = KnowledgeBaseDocument::whereNotNull('embedding')->first();
$doc2 = KnowledgeBaseDocument::whereNotNull('embedding')->skip(1)->first();

// Generate fresh embedding for doc1 content
$freshEmbedding = $this->embeddingService->generate($doc1->content);

// Compare stored vs fresh
$storedVsFresh = cosineSimilarity($doc1->embedding, $freshEmbedding);
Log::info('Stored vs Fresh embedding', ['similarity' => $storedVsFresh]);
// If < 0.9, models are different
```

## Solution

### Fix Steps

1. **Verify model configuration**
```php
// config/rag.php
return [
    'embedding' => [
        'model' => env('EMBEDDING_MODEL', 'text-embedding-3-large'),
        // Use same model for both indexing and querying
    ],
];
```

2. **Store model with document**
```php
// In document creation
$document = KnowledgeBaseDocument::create([
    'content' => $content,
    'embedding' => $embedding,
    'metadata' => [
        'embedding_model' => config('rag.embedding.model'),
        'embedding_dimensions' => count($embedding),
        'indexed_at' => now()->toISOString(),
    ],
]);
```

3. **Re-index with correct model**
```php
// Artisan command
php artisan rag:reindex --bot-id=$bot_id --model=text-embedding-3-large
```

### Code Fix

```php
// Ensure consistent model usage
class EmbeddingService
{
    private string $model;

    public function __construct()
    {
        $this->model = config('rag.embedding.model');
    }

    public function generate(string $text): array
    {
        $response = OpenAI::embeddings()->create([
            'model' => $this->model,
            'input' => $text,
        ]);

        return $response->embeddings[0]->embedding;
    }

    public function getModel(): string
    {
        return $this->model;
    }
}

// Validation before search
class SemanticSearchService
{
    public function search(string $query, int $botId): Collection
    {
        // Check if documents use same model
        $documentModel = KnowledgeBaseDocument::where('bot_id', $botId)
            ->whereNotNull('embedding')
            ->value('metadata->embedding_model');

        if ($documentModel && $documentModel !== $this->embeddingService->getModel()) {
            Log::warning('Model mismatch detected', [
                'document_model' => $documentModel,
                'query_model' => $this->embeddingService->getModel(),
            ]);
            // Either throw or use appropriate model
        }

        // Proceed with search
        $queryEmbedding = $this->embeddingService->generate($query);
        return $this->performSearch($queryEmbedding, $botId);
    }
}
```

## Verification

```sql
-- Check all documents use same model
SELECT DISTINCT metadata->>'embedding_model' as models
FROM knowledge_base_documents
WHERE bot_id = $bot_id;
-- Should return exactly 1 row

-- Test search quality
SELECT id, content,
       1 - (embedding <=> $query_embedding::vector) as similarity
FROM knowledge_base_documents
WHERE bot_id = $bot_id
ORDER BY embedding <=> $query_embedding::vector
LIMIT 5;
-- Top result similarity should be > 0.7 for related content
```

## Prevention

- Always store embedding model in metadata
- Add validation check before search
- Create migration script for model changes
- Document embedding model version

## Project-Specific Notes

**BotFacebook Context:**
- Default model: `text-embedding-3-large` (supports Thai)
- Model config: `config/rag.php`
- Migration command: `php artisan rag:migrate-embeddings`
- Metadata stored in `metadata` JSONB column
