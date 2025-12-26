<?php

namespace App\Services;

use App\Models\DocumentChunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Pgvector\Laravel\Distance;

class SemanticSearchService
{
    protected EmbeddingService $embeddingService;
    protected float $relevanceThreshold;

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
        $this->relevanceThreshold = config('services.embeddings.relevance_threshold', 0.7);
    }

    /**
     * Search for relevant document chunks using semantic similarity.
     *
     * @param int $knowledgeBaseId The knowledge base to search in
     * @param string $query The search query
     * @param int $limit Maximum number of results
     * @param float|null $threshold Minimum similarity score (0-1)
     */
    public function search(
        int $knowledgeBaseId,
        string $query,
        int $limit = 5,
        ?float $threshold = null
    ): Collection {
        $threshold = $threshold ?? $this->relevanceThreshold;

        // Generate embedding for the search query
        $queryEmbedding = $this->embeddingService->generate($query);

        // Find nearest neighbors using pgvector
        // The HasNeighbors trait provides nearestNeighbors() method
        // Cosine distance: 0 = identical, 2 = opposite
        // We convert to similarity: 1 - (distance/2) gives 0-1 range
        $results = DocumentChunk::query()
            ->select([
                'document_chunks.*',
                'documents.original_filename',
                'documents.knowledge_base_id',
            ])
            ->join('documents', 'documents.id', '=', 'document_chunks.document_id')
            ->where('documents.knowledge_base_id', $knowledgeBaseId)
            ->where('documents.status', 'completed')
            ->whereNotNull('document_chunks.embedding')
            ->nearestNeighbors('embedding', $queryEmbedding, Distance::Cosine)
            ->take($limit * 2) // Fetch more than needed to filter by threshold
            ->get();

        // Transform results with similarity scores
        return $results
            ->map(function ($chunk) {
                // neighbor_distance is the cosine distance (0-2)
                // Convert to similarity score (0-1)
                $distance = $chunk->neighbor_distance ?? 0;
                $similarity = 1 - ($distance / 2);

                return [
                    'id' => $chunk->id,
                    'document_id' => $chunk->document_id,
                    'document_name' => $chunk->original_filename,
                    'content' => $chunk->content,
                    'chunk_index' => $chunk->chunk_index,
                    'similarity' => round($similarity, 4),
                    'metadata' => $chunk->metadata,
                ];
            })
            ->filter(fn ($item) => $item['similarity'] >= $threshold)
            ->take($limit)
            ->values();
    }

    /**
     * Search and return full chunk models for internal use.
     */
    public function searchChunks(
        int $knowledgeBaseId,
        string $query,
        int $limit = 5,
        ?float $threshold = null
    ): Collection {
        $threshold = $threshold ?? $this->relevanceThreshold;
        $queryEmbedding = $this->embeddingService->generate($query);

        return DocumentChunk::query()
            ->join('documents', 'documents.id', '=', 'document_chunks.document_id')
            ->where('documents.knowledge_base_id', $knowledgeBaseId)
            ->where('documents.status', 'completed')
            ->whereNotNull('document_chunks.embedding')
            ->nearestNeighbors('embedding', $queryEmbedding, Distance::Cosine)
            ->take($limit)
            ->get()
            ->filter(function ($chunk) use ($threshold) {
                $distance = $chunk->neighbor_distance ?? 0;
                return (1 - ($distance / 2)) >= $threshold;
            });
    }

    /**
     * Get context string from search results for AI prompting.
     */
    public function getContextForPrompt(
        int $knowledgeBaseId,
        string $query,
        int $limit = 3,
        int $maxChars = 4000
    ): string {
        $results = $this->search($knowledgeBaseId, $query, $limit);

        if ($results->isEmpty()) {
            return '';
        }

        $context = $results
            ->map(fn ($r) => "--- From: {$r['document_name']} (relevance: " . round($r['similarity'] * 100) . "%) ---\n{$r['content']}")
            ->join("\n\n");

        // Truncate if too long
        if (strlen($context) > $maxChars) {
            $context = substr($context, 0, $maxChars) . '...';
        }

        return $context;
    }

    /**
     * Set the relevance threshold.
     */
    public function setThreshold(float $threshold): self
    {
        $this->relevanceThreshold = max(0, min(1, $threshold));
        return $this;
    }
}
