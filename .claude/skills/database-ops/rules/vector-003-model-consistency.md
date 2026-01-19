---
id: vector-003-model-consistency
title: Use Same Embedding Model for Index and Query
impact: HIGH
impactDescription: "Different models produce incompatible embeddings - searches fail silently"
category: vector
tags: [vector, embedding, model, consistency]
relatedRules: [vector-002-choose-dimension]
---

## Why This Matters

Embeddings from different models are NOT comparable. If you index documents with Model A and search with Model B, similarity scores are meaningless. The search will return results, but they'll be wrong - a silent, hard-to-debug failure.

## Bad Example

```php
// Indexing with one model
$embedding = $this->openAI->embed($document, model: 'text-embedding-ada-002');
KnowledgeChunk::create([
    'content' => $document,
    'embedding' => $embedding,
]);

// Searching with different model
$queryEmbedding = $this->openAI->embed($query, model: 'text-embedding-3-small');
$results = KnowledgeChunk::similarTo($queryEmbedding)->get();
// Results are garbage - models are incompatible!
```

**Why it's wrong:**
- Different embedding spaces
- No error - silent failure
- Results look plausible but wrong

## Good Example

```php
class EmbeddingService
{
    // Single source of truth for model
    private string $model;

    public function __construct()
    {
        $this->model = config('rag.embedding_model');
    }

    public function embed(string $text): array
    {
        return $this->openAI->embed($text, model: $this->model);
    }

    public function getModel(): string
    {
        return $this->model;
    }
}

// Store model with embedding for validation
Schema::create('knowledge_chunks', function (Blueprint $table) {
    $table->vector('embedding', 1536);
    $table->string('embedding_model', 50); // Track which model created it
});

// On search, validate model matches
public function search(string $query): Collection
{
    $queryEmbedding = $this->embeddingService->embed($query);
    $currentModel = $this->embeddingService->getModel();

    return KnowledgeChunk::query()
        ->where('embedding_model', $currentModel) // Only search compatible embeddings
        ->similarTo($queryEmbedding)
        ->get();
}
```

**Why it's better:**
- Single model configuration
- Model tracked with embeddings
- Incompatible embeddings filtered

## Project-Specific Notes

**BotFacebook Re-embedding on Model Change:**

```php
// When embedding model changes, re-embed all documents
class ReembedDocumentsJob implements ShouldQueue
{
    public function handle(EmbeddingService $service): void
    {
        KnowledgeChunk::query()
            ->where('embedding_model', '!=', $service->getModel())
            ->chunkById(100, function ($chunks) use ($service) {
                foreach ($chunks as $chunk) {
                    $chunk->update([
                        'embedding' => $service->embed($chunk->content),
                        'embedding_model' => $service->getModel(),
                    ]);
                }
            });
    }
}
```
