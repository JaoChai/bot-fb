<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Jina AI Reranker Service
 *
 * Uses Jina's jina-reranker-v2-base-multilingual model to rerank
 * search results for better relevance. Supports 100+ languages
 * including Thai.
 *
 * Free tier: 10 million tokens
 * Performance: 96.30 Recall@20 (better than Cohere's 96.10)
 *
 * @see https://jina.ai/reranker
 */
class JinaRerankerService
{
    protected string $apiKey;

    protected string $baseUrl;

    protected string $model;

    protected int $timeout;

    protected bool $enabled;

    public function __construct()
    {
        $this->apiKey = config('services.jina.api_key') ?? '';
        $this->baseUrl = config('services.jina.base_url') ?? 'https://api.jina.ai/v1';
        $this->model = config('services.jina.rerank_model') ?? 'jina-reranker-v2-base-multilingual';
        $this->timeout = (int) config('services.jina.timeout', 30);
        $this->enabled = (bool) config('rag.reranking.enabled', false);
    }

    /**
     * Rerank documents based on relevance to the query.
     *
     * @param  string  $query  The search query
     * @param  array  $documents  Array of document contents (strings) or objects with 'content' key
     * @param  int  $topN  Number of top results to return
     * @return Collection Reranked documents with relevance_score
     */
    public function rerank(string $query, array $documents, int $topN = 5): Collection
    {
        if (! $this->isAvailable()) {
            Log::debug('JinaReranker: Not available, returning original order');

            return $this->formatAsCollection($documents, $topN);
        }

        if (empty($documents)) {
            return collect([]);
        }

        // Extract document contents for the API
        $docTexts = $this->extractDocumentTexts($documents);

        if (empty($docTexts)) {
            return $this->formatAsCollection($documents, $topN);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->post("{$this->baseUrl}/rerank", [
                    'model' => $this->model,
                    'query' => $query,
                    'documents' => $docTexts,
                    'top_n' => min($topN, count($documents)),
                ]);

            if ($response->failed()) {
                $error = $response->json('detail', $response->body());
                Log::error('JinaReranker: API request failed', [
                    'status' => $response->status(),
                    'error' => $error,
                ]);

                return $this->formatAsCollection($documents, $topN);
            }

            $results = $response->json('results', []);

            if (empty($results)) {
                Log::warning('JinaReranker: Empty results from API');

                return $this->formatAsCollection($documents, $topN);
            }

            // Map back to original documents with relevance scores
            return $this->mapResultsToDocuments($results, $documents);

        } catch (\Exception $e) {
            Log::error('JinaReranker: Exception during reranking', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100),
            ]);

            return $this->formatAsCollection($documents, $topN);
        }
    }

    /**
     * Check if the reranker is available and enabled.
     */
    public function isAvailable(): bool
    {
        return $this->enabled && ! empty($this->apiKey);
    }

    /**
     * Check if reranking is enabled in config.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable or disable reranking at runtime.
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * Extract text content from documents.
     * Handles both string arrays and object arrays.
     */
    protected function extractDocumentTexts(array $documents): array
    {
        $texts = [];

        foreach ($documents as $doc) {
            if (is_string($doc)) {
                $texts[] = $doc;
            } elseif (is_array($doc) && isset($doc['content'])) {
                $texts[] = $doc['content'];
            } elseif (is_object($doc) && isset($doc->content)) {
                $texts[] = $doc->content;
            }
        }

        return $texts;
    }

    /**
     * Map Jina API results back to original document objects.
     */
    protected function mapResultsToDocuments(array $results, array $originalDocs): Collection
    {
        $reranked = [];

        foreach ($results as $result) {
            $index = $result['index'] ?? null;
            $relevanceScore = $result['relevance_score'] ?? 0;

            if ($index === null || ! isset($originalDocs[$index])) {
                continue;
            }

            $doc = $originalDocs[$index];

            // If it's an array, add relevance_score
            if (is_array($doc)) {
                $doc['relevance_score'] = round($relevanceScore, 4);
                $doc['reranked'] = true;
            }

            $reranked[] = $doc;
        }

        return collect($reranked);
    }

    /**
     * Format documents as collection without reranking (fallback).
     */
    protected function formatAsCollection(array $documents, int $topN): Collection
    {
        return collect($documents)
            ->take($topN)
            ->map(function ($doc) {
                if (is_array($doc)) {
                    $doc['reranked'] = false;
                }

                return $doc;
            })
            ->values();
    }

    /**
     * Get the current model being used.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Test the reranker with a simple query.
     */
    public function test(): array
    {
        $testQuery = 'What is machine learning?';
        $testDocs = [
            'Machine learning is a subset of artificial intelligence.',
            'The weather today is sunny.',
            'Deep learning uses neural networks for complex tasks.',
        ];

        $results = $this->rerank($testQuery, $testDocs, 3);

        return [
            'available' => $this->isAvailable(),
            'enabled' => $this->enabled,
            'model' => $this->model,
            'test_query' => $testQuery,
            'results' => $results->toArray(),
        ];
    }
}
