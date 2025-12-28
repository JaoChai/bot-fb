<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Hybrid Search Service
 *
 * Combines semantic (vector) search with keyword (full-text) search
 * using Reciprocal Rank Fusion (RRF) for optimal retrieval quality.
 *
 * Research shows hybrid search improves retrieval by ~48% compared
 * to single-method approaches (Pinecone analysis, 2024).
 */
class HybridSearchService
{
    /**
     * RRF constant (k). Standard value is 60.
     * Higher k = more weight to lower-ranked items.
     */
    protected int $rrfK;

    /**
     * Whether hybrid search is enabled.
     */
    protected bool $enabled;

    /**
     * Whether reranking is enabled.
     */
    protected bool $rerankingEnabled;

    /**
     * Number of candidates to fetch before reranking.
     */
    protected int $rerankCandidates;

    /**
     * Final number of results after reranking.
     */
    protected int $rerankTopN;

    public function __construct(
        protected SemanticSearchService $semanticSearch,
        protected KeywordSearchService $keywordSearch,
        protected ?JinaRerankerService $reranker = null
    ) {
        $this->rrfK = (int) config('rag.hybrid_search.rrf_k', 60);
        $this->enabled = (bool) config('rag.hybrid_search.enabled', true);
        $this->rerankingEnabled = (bool) config('rag.reranking.enabled', false);
        $this->rerankCandidates = (int) config('rag.reranking.candidates', 20);
        $this->rerankTopN = (int) config('rag.reranking.top_n', 5);
    }

    /**
     * Perform hybrid search combining semantic and keyword results.
     *
     * @param int $knowledgeBaseId The knowledge base to search in
     * @param string $query The search query
     * @param int $limit Final number of results to return
     * @param float|null $threshold Minimum similarity threshold for semantic search
     * @return Collection Merged and ranked results
     */
    public function search(
        int $knowledgeBaseId,
        string $query,
        int $limit = 5,
        ?float $threshold = null
    ): Collection {
        // If hybrid search is disabled, fall back to semantic only
        if (!$this->enabled || !$this->keywordSearch->isAvailable()) {
            Log::debug('HybridSearch: Using semantic-only mode', [
                'enabled' => $this->enabled,
                'fts_available' => $this->keywordSearch->isAvailable(),
            ]);
            return $this->semanticSearch->search($knowledgeBaseId, $query, $limit, $threshold);
        }

        // Determine candidate limit based on reranking
        $candidateLimit = $this->rerankingEnabled && $this->isRerankerAvailable()
            ? $this->rerankCandidates
            : $limit * 4; // Default 4x for RRF

        // Run both searches
        $semanticResults = $this->semanticSearch->search(
            $knowledgeBaseId,
            $query,
            $candidateLimit,
            $threshold
        );

        $keywordResults = $this->keywordSearch->search(
            $knowledgeBaseId,
            $query,
            $candidateLimit
        );

        // Apply Reciprocal Rank Fusion
        $fusedLimit = $this->rerankingEnabled && $this->isRerankerAvailable()
            ? $this->rerankCandidates
            : $limit;

        $fusedResults = $this->reciprocalRankFusion(
            $semanticResults,
            $keywordResults,
            $fusedLimit
        );

        Log::debug('HybridSearch: Fusion completed', [
            'kb_id' => $knowledgeBaseId,
            'semantic_count' => $semanticResults->count(),
            'keyword_count' => $keywordResults->count(),
            'fused_count' => $fusedResults->count(),
        ]);

        // Apply reranking if enabled
        if ($this->rerankingEnabled && $this->isRerankerAvailable()) {
            $fusedResults = $this->applyReranking($query, $fusedResults, $limit);
        }

        return $fusedResults;
    }

    /**
     * Search multiple knowledge bases using hybrid approach.
     *
     * @param array $kbConfigs Array of KB configs with per-KB settings
     * @param string $query The search query
     * @param int $totalLimit Maximum total results across all KBs
     * @return Collection Merged results from all KBs
     */
    public function searchMultiple(
        array $kbConfigs,
        string $query,
        int $totalLimit = 10
    ): Collection {
        if (empty($kbConfigs)) {
            return collect([]);
        }

        // If hybrid disabled, delegate to semantic service
        if (!$this->enabled || !$this->keywordSearch->isAvailable()) {
            return $this->semanticSearch->searchMultiple($kbConfigs, $query, $totalLimit);
        }

        $allResults = collect([]);

        foreach ($kbConfigs as $config) {
            $kbId = $config['id'];
            $limit = $config['kb_top_k'] ?? 5;
            $threshold = $config['kb_similarity_threshold'] ?? null;

            $results = $this->search($kbId, $query, $limit, $threshold);

            // Add KB ID to each result
            $results = $results->map(function ($item) use ($kbId) {
                $item['knowledge_base_id'] = $kbId;
                return $item;
            });

            $allResults = $allResults->concat($results);
        }

        // Sort by RRF score and take top N
        return $allResults
            ->sortByDesc('rrf_score')
            ->take($totalLimit)
            ->values();
    }

    /**
     * Apply Reciprocal Rank Fusion to merge two result lists.
     *
     * RRF Formula: score = sum(1 / (k + rank)) for each list
     *
     * This algorithm is robust and doesn't require score normalization
     * between different retrieval methods.
     *
     * @param Collection $semanticResults Results from semantic search
     * @param Collection $keywordResults Results from keyword search
     * @param int $limit Maximum results to return
     * @return Collection Fused and sorted results
     */
    protected function reciprocalRankFusion(
        Collection $semanticResults,
        Collection $keywordResults,
        int $limit
    ): Collection {
        $k = $this->rrfK;
        $scores = [];
        $documents = [];

        // Process semantic results
        foreach ($semanticResults->values() as $rank => $result) {
            $id = $result['id'];
            $rrfScore = 1.0 / ($k + $rank + 1); // rank is 0-indexed

            $scores[$id] = ($scores[$id] ?? 0) + $rrfScore;
            $documents[$id] = $result;
            $documents[$id]['semantic_rank'] = $rank + 1;
            $documents[$id]['semantic_score'] = $result['similarity'] ?? 0;
        }

        // Process keyword results
        foreach ($keywordResults->values() as $rank => $result) {
            $id = $result['id'];
            $rrfScore = 1.0 / ($k + $rank + 1);

            $scores[$id] = ($scores[$id] ?? 0) + $rrfScore;

            // If document not seen in semantic results, add it
            if (!isset($documents[$id])) {
                $documents[$id] = $result;
                $documents[$id]['semantic_rank'] = null;
                $documents[$id]['semantic_score'] = 0;
            }

            $documents[$id]['keyword_rank'] = $rank + 1;
            $documents[$id]['keyword_score'] = $result['keyword_score'] ?? 0;
        }

        // Sort by RRF score descending
        arsort($scores);

        // Build final result list
        $results = collect([]);
        $count = 0;

        foreach ($scores as $id => $rrfScore) {
            if ($count >= $limit) {
                break;
            }

            $doc = $documents[$id];
            $doc['rrf_score'] = round($rrfScore, 6);

            // Provide combined similarity for backward compatibility
            // Use semantic score if available, otherwise estimate from RRF
            $doc['similarity'] = $doc['semantic_score'] ?? $this->estimateSimilarity($rrfScore);

            $results->push($doc);
            $count++;
        }

        return $results;
    }

    /**
     * Estimate a similarity score from RRF score for backward compatibility.
     * This is a rough approximation when semantic score is not available.
     */
    protected function estimateSimilarity(float $rrfScore): float
    {
        // RRF scores typically range from 0 to ~0.033 (1/60 + 1/60)
        // Normalize to 0-1 range, capping at reasonable values
        $maxRrf = 2.0 / ($this->rrfK + 1); // Max possible score (rank 1 in both)
        $normalized = min(1.0, $rrfScore / $maxRrf);
        return round($normalized, 4);
    }

    /**
     * Check if hybrid search is enabled and available.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && $this->keywordSearch->isAvailable();
    }

    /**
     * Enable or disable hybrid search at runtime.
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Check if reranker is available.
     */
    public function isRerankerAvailable(): bool
    {
        return $this->reranker !== null && $this->reranker->isAvailable();
    }

    /**
     * Check if reranking is enabled and available.
     */
    public function isRerankingEnabled(): bool
    {
        return $this->rerankingEnabled && $this->isRerankerAvailable();
    }

    /**
     * Apply reranking to search results using Jina Reranker.
     *
     * @param string $query The search query
     * @param Collection $results Results to rerank
     * @param int $topN Number of top results to return
     * @return Collection Reranked results
     */
    protected function applyReranking(string $query, Collection $results, int $topN): Collection
    {
        if ($results->isEmpty()) {
            return $results;
        }

        // Prepare documents for reranking
        $documents = $results->map(function ($result) {
            return [
                'content' => $result['content'] ?? '',
                'id' => $result['id'],
            ];
        })->toArray();

        Log::debug('HybridSearch: Applying reranking', [
            'query' => substr($query, 0, 100),
            'candidates' => count($documents),
            'top_n' => $topN,
        ]);

        // Call Jina Reranker
        $rerankedDocs = $this->reranker->rerank($query, $documents, $topN);

        // Map back to original results with relevance scores
        $rerankedResults = $rerankedDocs->map(function ($doc) use ($results) {
            // Find original result by ID
            $original = $results->firstWhere('id', $doc['id']);

            if (!$original) {
                return null;
            }

            // Add reranking info
            $original['relevance_score'] = $doc['relevance_score'] ?? 0;
            $original['reranked'] = true;

            return $original;
        })->filter()->values();

        Log::debug('HybridSearch: Reranking completed', [
            'input_count' => $results->count(),
            'output_count' => $rerankedResults->count(),
            'reranked' => $rerankedResults->isNotEmpty(),
        ]);

        return $rerankedResults;
    }

    /**
     * Get reranking status for metadata.
     */
    public function getRerankingStatus(): array
    {
        return [
            'enabled' => $this->rerankingEnabled,
            'available' => $this->isRerankerAvailable(),
            'active' => $this->isRerankingEnabled(),
            'provider' => 'jina',
            'model' => $this->reranker?->getModel() ?? null,
        ];
    }
}
