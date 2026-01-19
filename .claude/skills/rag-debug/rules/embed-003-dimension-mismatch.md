---
id: embed-003-dimension-mismatch
title: Embedding Dimension Mismatch
impact: CRITICAL
impactDescription: "Vector operations fail completely"
category: embed
tags: [embedding, dimensions, vector, pgvector]
relatedRules: [embed-002-model-consistency, search-001-no-results]
---

## Symptom

- SQL error: "different vector dimensions"
- Query fails with pgvector error
- Search throws exception
- Index rebuild fails

## Root Cause

1. Model changed to different dimension output
2. Column defined with wrong dimension
3. Mixing documents from different models
4. Migration incomplete

## Diagnosis

### Quick Check

```sql
-- Check column dimension
SELECT
    attname,
    format_type(atttypid, atttypmod) as type
FROM pg_attribute
WHERE attrelid = 'knowledge_base_documents'::regclass
AND attname = 'embedding';

-- Check actual embedding dimensions
SELECT
    array_length(embedding::real[], 1) as dimensions,
    COUNT(*) as count
FROM knowledge_base_documents
WHERE embedding IS NOT NULL
GROUP BY array_length(embedding::real[], 1);
```

### Detailed Analysis

```php
// Check model dimensions
$testEmbedding = $this->embeddingService->generate('test');
Log::info('Current model dimensions', [
    'dimensions' => count($testEmbedding),
    'model' => config('rag.embedding.model'),
]);

// text-embedding-3-small: 1536
// text-embedding-3-large: 3072
// text-embedding-ada-002: 1536
```

## Solution

### Fix Steps

1. **Check current configuration**
```sql
-- Current column type
\d knowledge_base_documents
-- Look for: embedding vector(1536) or vector(3072)
```

2. **Alter column if needed**
```sql
-- Change dimension (requires re-embedding all documents!)
ALTER TABLE knowledge_base_documents
ALTER COLUMN embedding TYPE vector(3072);
```

3. **Re-embed all documents**
```bash
php artisan rag:reindex --bot-id=$bot_id --force
```

### Code Fix

```php
// Migration to change dimensions
return new class extends Migration
{
    public function up(): void
    {
        // Drop existing index
        DB::statement('DROP INDEX IF EXISTS idx_kb_docs_embedding');

        // Change column dimension
        DB::statement('
            ALTER TABLE knowledge_base_documents
            ALTER COLUMN embedding TYPE vector(3072)
        ');

        // Clear existing embeddings (must regenerate)
        DB::table('knowledge_base_documents')->update(['embedding' => null]);

        // Index will be recreated after re-embedding
    }
};

// Validation in EmbeddingService
class EmbeddingService
{
    private int $expectedDimensions;

    public function __construct()
    {
        $this->expectedDimensions = config('rag.embedding.dimensions', 3072);
    }

    public function generate(string $text): array
    {
        $embedding = $this->callOpenAI($text);

        if (count($embedding) !== $this->expectedDimensions) {
            throw new DimensionMismatchException(
                "Expected {$this->expectedDimensions} dimensions, got " . count($embedding)
            );
        }

        return $embedding;
    }

    public function validateDocument(KnowledgeBaseDocument $doc): bool
    {
        if (!$doc->embedding) {
            return false;
        }

        $dimensions = count($doc->embedding);
        return $dimensions === $this->expectedDimensions;
    }
}
```

## Verification

```sql
-- Verify all embeddings have correct dimensions
SELECT
    array_length(embedding::real[], 1) as dimensions,
    COUNT(*) as count
FROM knowledge_base_documents
WHERE embedding IS NOT NULL
GROUP BY array_length(embedding::real[], 1);
-- Should return single row with expected dimension

-- Test vector operation works
SELECT id,
       1 - (embedding <=> $query_embedding::vector) as similarity
FROM knowledge_base_documents
WHERE bot_id = $bot_id
LIMIT 1;
-- Should not throw dimension error
```

## Prevention

- Define dimension in config and validate
- Add check constraint on column
- Validate embeddings before insert
- Log dimension mismatches as errors

## Project-Specific Notes

**BotFacebook Context:**
- Default: 3072 dimensions (text-embedding-3-large)
- Column: `embedding vector(3072)`
- Config: `config/rag.php` → `embedding.dimensions`
- Validation in `EmbeddingService::generate()`
