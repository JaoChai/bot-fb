<?php

namespace App\Services;

use App\Models\DocumentChunk;
use Illuminate\Support\Collection;
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
     * @param  int  $knowledgeBaseId  The knowledge base to search in
     * @param  string  $query  The search query
     * @param  int  $limit  Maximum number of results
     * @param  float|null  $threshold  Minimum similarity score (0-1)
     * @param  string|null  $apiKey  Optional API key to use (from user settings)
     */
    public function search(
        int $knowledgeBaseId,
        string $query,
        int $limit = 5,
        ?float $threshold = null,
        ?string $apiKey = null,
        ?array $precomputedEmbedding = null
    ): Collection {
        $threshold = $threshold ?? $this->relevanceThreshold;

        // Use precomputed embedding or generate new one
        if ($precomputedEmbedding) {
            $queryEmbedding = $precomputedEmbedding;
        } else {
            // Use user's API key if provided, otherwise use default
            $embeddingService = $apiKey
                ? $this->embeddingService->withApiKey($apiKey)
                : $this->embeddingService;

            // Generate embedding for the search query
            $queryEmbedding = $embeddingService->generate($query);
        }

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
                    'has_context' => ! empty($chunk->context_text),
                ];
            })
            ->filter(fn ($item) => $item['similarity'] >= $threshold)
            ->take($limit)
            ->values();
    }

    /**
     * Search multiple knowledge bases and merge results by similarity score.
     *
     * @param  array  $kbConfigs  Array of KB configs: [['id' => int, 'kb_top_k' => int, 'kb_similarity_threshold' => float], ...]
     * @param  string  $query  The search query
     * @param  int  $totalLimit  Maximum total results to return across all KBs
     * @param  string|null  $apiKey  Optional API key to use (from user settings)
     */
    public function searchMultiple(
        array $kbConfigs,
        string $query,
        int $totalLimit = 10,
        ?string $apiKey = null
    ): Collection {
        if (empty($kbConfigs)) {
            return collect([]);
        }

        // Use user's API key if provided, otherwise use default
        $embeddingService = $apiKey
            ? $this->embeddingService->withApiKey($apiKey)
            : $this->embeddingService;

        // Generate embedding once for all searches
        $queryEmbedding = $embeddingService->generate($query);

        $allResults = collect([]);

        foreach ($kbConfigs as $config) {
            $kbId = $config['id'];
            $limit = $config['kb_top_k'] ?? 5;
            $threshold = $config['kb_similarity_threshold'] ?? $this->relevanceThreshold;

            $results = DocumentChunk::query()
                ->select([
                    'document_chunks.*',
                    'documents.original_filename',
                    'documents.knowledge_base_id',
                ])
                ->join('documents', 'documents.id', '=', 'document_chunks.document_id')
                ->where('documents.knowledge_base_id', $kbId)
                ->where('documents.status', 'completed')
                ->whereNotNull('document_chunks.embedding')
                ->nearestNeighbors('embedding', $queryEmbedding, Distance::Cosine)
                ->take($limit * 2)
                ->get()
                ->map(function ($chunk) use ($kbId) {
                    $distance = $chunk->neighbor_distance ?? 0;
                    $similarity = 1 - ($distance / 2);

                    return [
                        'id' => $chunk->id,
                        'document_id' => $chunk->document_id,
                        'knowledge_base_id' => $kbId,
                        'document_name' => $chunk->original_filename,
                        'content' => $chunk->content,
                        'chunk_index' => $chunk->chunk_index,
                        'similarity' => round($similarity, 4),
                        'metadata' => $chunk->metadata,
                        'has_context' => ! empty($chunk->context_text),
                    ];
                })
                ->filter(fn ($item) => $item['similarity'] >= $threshold)
                ->take($limit);

            $allResults = $allResults->concat($results);
        }

        // Sort all results by similarity (descending) and take top N
        return $allResults
            ->sortByDesc('similarity')
            ->take($totalLimit)
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
            ->map(fn ($r) => "--- From: {$r['document_name']} (relevance: ".round($r['similarity'] * 100)."%) ---\n{$r['content']}")
            ->join("\n\n");

        // Truncate if too long
        if (strlen($context) > $maxChars) {
            $context = substr($context, 0, $maxChars).'...';
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
