---
id: embed-001-null-embedding
title: Embedding is NULL in Database
impact: CRITICAL
impactDescription: "Document cannot be found by semantic search at all"
category: embed
tags: [embedding, null, database, generation]
relatedRules: [embed-004-generation-failure, search-001-no-results]
---

## Symptom

- Document exists but never found by search
- Search returns 0 results for exact content match
- `embedding` column is NULL for some records

## Root Cause

1. Embedding generation failed silently
2. API rate limit during batch processing
3. Document content empty or invalid
4. Job processing error

## Diagnosis

### Quick Check

```sql
-- Find documents with NULL embeddings
SELECT id, title, LEFT(content, 50) as preview, created_at
FROM knowledge_base_documents
WHERE bot_id = $bot_id
  AND embedding IS NULL
ORDER BY created_at DESC
LIMIT 10;

-- Count NULL vs non-NULL
SELECT
    COUNT(*) FILTER (WHERE embedding IS NULL) as null_count,
    COUNT(*) FILTER (WHERE embedding IS NOT NULL) as has_embedding,
    ROUND(COUNT(*) FILTER (WHERE embedding IS NULL)::numeric / NULLIF(COUNT(*), 0) * 100, 2) as null_percentage
FROM knowledge_base_documents
WHERE bot_id = $bot_id;
```

### Detailed Analysis

```php
// Check embedding generation logs
Log::channel('rag')->debug('Check for embedding failures');

// In EmbeddingService
$documents = KnowledgeBaseDocument::whereNull('embedding')->get();

foreach ($documents as $doc) {
    Log::info('Document missing embedding', [
        'id' => $doc->id,
        'content_length' => strlen($doc->content),
        'created_at' => $doc->created_at,
    ]);
}
```

## Solution

### Fix Steps

1. **Identify affected documents**
```sql
SELECT id FROM knowledge_base_documents
WHERE bot_id = $bot_id AND embedding IS NULL;
```

2. **Regenerate embeddings**
```php
// Using artisan command
php artisan rag:regenerate-embeddings --bot-id=$bot_id --null-only
```

3. **Fix batch processing**
```php
// Process in smaller batches with retry
public function generateEmbeddingsForBot(int $botId): void
{
    KnowledgeBaseDocument::where('bot_id', $botId)
        ->whereNull('embedding')
        ->chunkById(10, function ($documents) {
            foreach ($documents as $doc) {
                try {
                    $embedding = $this->embeddingService->generate($doc->content);
                    $doc->update(['embedding' => $embedding]);
                } catch (\Exception $e) {
                    Log::error('Embedding generation failed', [
                        'doc_id' => $doc->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with next document
                }
            }

            // Rate limit protection
            usleep(100000); // 100ms between batches
        });
}
```

### Code Fix

```php
// In document creation observer
class KnowledgeBaseDocumentObserver
{
    public function created(KnowledgeBaseDocument $document): void
    {
        // Generate embedding synchronously or queue
        if (strlen($document->content) > 0) {
            dispatch(new GenerateEmbedding($document))->afterCommit();
        }
    }
}

// In job with proper error handling
class GenerateEmbedding implements ShouldQueue
{
    public int $tries = 3;
    public array $backoff = [10, 30, 60];

    public function handle(EmbeddingService $service): void
    {
        if (empty($this->document->content)) {
            Log::warning('Cannot generate embedding for empty content', [
                'doc_id' => $this->document->id,
            ]);
            return;
        }

        $embedding = $service->generate($this->document->content);

        $this->document->update(['embedding' => $embedding]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Embedding generation permanently failed', [
            'doc_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);

        // Mark for manual review
        $this->document->update(['embedding_error' => $exception->getMessage()]);
    }
}
```

## Verification

```sql
-- Verify no more NULL embeddings
SELECT COUNT(*) as null_count
FROM knowledge_base_documents
WHERE bot_id = $bot_id AND embedding IS NULL;

-- Should return 0

-- Test search now works
SELECT id, content,
       1 - (embedding <=> $query_embedding::vector) as similarity
FROM knowledge_base_documents
WHERE bot_id = $bot_id
ORDER BY embedding <=> $query_embedding::vector
LIMIT 5;
```

## Prevention

- Add NOT NULL constraint with default handling
- Monitor NULL embedding count daily
- Alert when NULL percentage > 1%
- Add pre-commit validation

## Project-Specific Notes

**BotFacebook Context:**
- Table: `knowledge_base_documents`
- Observer: `KnowledgeBaseDocumentObserver`
- Job: `GenerateEmbedding`
- Command: `php artisan rag:regenerate-embeddings`
