<?php

namespace App\Services;

use App\Models\DocumentChunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keyword-based search using PostgreSQL Full-Text Search.
 *
 * Complements SemanticSearchService by providing exact keyword matching
 * using PostgreSQL's tsvector/tsquery functionality.
 *
 * Uses 'simple' configuration for multilingual support (Thai + English).
 */
class KeywordSearchService
{
    /**
     * Search for document chunks using keyword matching.
     *
     * @param int $knowledgeBaseId The knowledge base to search in
     * @param string $query The search query
     * @param int $limit Maximum number of results
     * @return Collection Collection of search results with ts_rank scores
     */
    public function search(
        int $knowledgeBaseId,
        string $query,
        int $limit = 10
    ): Collection {
        // Skip if not PostgreSQL
        if (DB::connection()->getDriverName() !== 'pgsql') {
            Log::warning('KeywordSearchService: Full-text search requires PostgreSQL');
            return collect([]);
        }

        // Sanitize and prepare query for tsquery
        $sanitizedQuery = $this->sanitizeQuery($query);

        if (empty($sanitizedQuery)) {
            return collect([]);
        }

        try {
            // Use plainto_tsquery for simple word matching (handles Thai + English)
            // ts_rank_cd uses cover density ranking for better relevance
            $results = DocumentChunk::query()
                ->select([
                    'document_chunks.id',
                    'document_chunks.document_id',
                    'document_chunks.content',
                    'document_chunks.chunk_index',
                    'document_chunks.metadata',
                    'documents.original_filename',
                    'documents.knowledge_base_id',
                    DB::raw("ts_rank_cd(to_tsvector('simple', document_chunks.content), plainto_tsquery('simple', ?)) as rank_score"),
                ])
                ->join('documents', 'documents.id', '=', 'document_chunks.document_id')
                ->where('documents.knowledge_base_id', $knowledgeBaseId)
                ->where('documents.status', 'completed')
                ->whereRaw("to_tsvector('simple', document_chunks.content) @@ plainto_tsquery('simple', ?)", [$sanitizedQuery])
                ->orderByDesc('rank_score')
                ->limit($limit)
                ->setBindings([$sanitizedQuery, $sanitizedQuery])
                ->get();

            return $results->map(function ($chunk) {
                // Normalize rank_score to 0-1 range (ts_rank_cd typically returns 0-1)
                $score = min(1.0, max(0.0, (float) $chunk->rank_score));

                return [
                    'id' => $chunk->id,
                    'document_id' => $chunk->document_id,
                    'document_name' => $chunk->original_filename,
                    'content' => $chunk->content,
                    'chunk_index' => $chunk->chunk_index,
                    'keyword_score' => round($score, 4),
                    'metadata' => $chunk->metadata,
                ];
            });
        } catch (\Exception $e) {
            Log::error('KeywordSearchService: Search failed', [
                'kb_id' => $knowledgeBaseId,
                'query' => substr($query, 0, 100),
                'error' => $e->getMessage(),
            ]);
            return collect([]);
        }
    }

    /**
     * Search multiple knowledge bases using keywords.
     *
     * @param array $kbConfigs Array of KB configs: [['id' => int, 'kb_top_k' => int], ...]
     * @param string $query The search query
     * @param int $totalLimit Maximum total results across all KBs
     * @return Collection Merged results sorted by keyword score
     */
    public function searchMultiple(
        array $kbConfigs,
        string $query,
        int $totalLimit = 20
    ): Collection {
        if (empty($kbConfigs)) {
            return collect([]);
        }

        $allResults = collect([]);

        foreach ($kbConfigs as $config) {
            $kbId = $config['id'];
            $limit = $config['kb_top_k'] ?? 10;

            $results = $this->search($kbId, $query, $limit);

            // Add KB ID to each result
            $results = $results->map(function ($item) use ($kbId) {
                $item['knowledge_base_id'] = $kbId;
                return $item;
            });

            $allResults = $allResults->concat($results);
        }

        // Sort by keyword_score and take top N
        return $allResults
            ->sortByDesc('keyword_score')
            ->take($totalLimit)
            ->values();
    }

    /**
     * Sanitize query string for PostgreSQL tsquery.
     *
     * Removes special characters that could break the query.
     */
    protected function sanitizeQuery(string $query): string
    {
        // Remove tsquery operators that could cause issues
        $query = preg_replace('/[&|!:*()\'"]/', ' ', $query);

        // Normalize whitespace
        $query = preg_replace('/\s+/', ' ', trim($query));

        return $query;
    }

    /**
     * Check if full-text search is available (PostgreSQL only).
     */
    public function isAvailable(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
