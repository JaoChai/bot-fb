<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\RagCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pgvector\Laravel\Vector;

/**
 * Semantic Cache Service for RAG responses.
 *
 * Uses PostgreSQL + pgvector to cache and retrieve similar queries.
 * This reduces API costs and improves response time for repeated/similar questions.
 *
 * How it works:
 * 1. When a query comes in, generate its embedding
 * 2. Search for similar cached queries using cosine similarity
 * 3. If similarity > threshold → return cached response (Cache Hit)
 * 4. If similarity < threshold → return null (Cache Miss) → caller generates new response
 * 5. After generating, save to cache for future queries
 */
class SemanticCacheService
{
    /**
     * Whether semantic cache is enabled.
     */
    protected bool $enabled;

    /**
     * Similarity threshold for cache hit (0.0 - 1.0).
     * Higher = stricter matching, lower hit rate but more accurate.
     * Recommended: 0.92-0.95 for Thai language.
     */
    protected float $similarityThreshold;

    /**
     * Cache TTL in minutes.
     */
    protected int $ttlMinutes;

    /**
     * Whether to use exact match first (faster).
     */
    protected bool $useExactMatch;

    public function __construct(
        protected EmbeddingService $embeddingService
    ) {
        $this->enabled = (bool) config('rag.semantic_cache.enabled', true);
        $this->similarityThreshold = (float) config('rag.semantic_cache.similarity_threshold', 0.92);
        $this->ttlMinutes = (int) config('rag.semantic_cache.ttl_minutes', 60);
        $this->useExactMatch = (bool) config('rag.semantic_cache.exact_match_first', true);
    }

    /**
     * Check if semantic cache is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Try to get a cached response for the given query.
     *
     * @param Bot $bot The bot to search cache for
     * @param string $query The user's query
     * @param string|null $apiKey Optional API key for embedding
     * @return array|null Cached response or null if not found
     */
    public function get(Bot $bot, string $query, ?string $apiKey = null): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            // Step 1: Try exact match first (fast, no API call)
            if ($this->useExactMatch) {
                $exactMatch = $this->getExactMatch($bot, $query);
                if ($exactMatch) {
                    Log::debug('SemanticCache: Exact match hit', [
                        'bot_id' => $bot->id,
                        'cache_id' => $exactMatch->id,
                        'query' => mb_substr($query, 0, 50),
                    ]);

                    return $this->formatCacheHit($exactMatch, 'exact');
                }
            }

            // Step 2: Try semantic match (requires embedding API call)
            $semanticMatch = $this->getSemanticMatch($bot, $query, $apiKey);
            if ($semanticMatch) {
                Log::debug('SemanticCache: Semantic match hit', [
                    'bot_id' => $bot->id,
                    'cache_id' => $semanticMatch['cache']->id,
                    'similarity' => $semanticMatch['similarity'],
                    'query' => mb_substr($query, 0, 50),
                ]);

                return $this->formatCacheHit($semanticMatch['cache'], 'semantic', $semanticMatch['similarity']);
            }

            Log::debug('SemanticCache: Miss', [
                'bot_id' => $bot->id,
                'query' => mb_substr($query, 0, 50),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::warning('SemanticCache: Error during lookup', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Save a response to the cache.
     *
     * @param Bot $bot The bot
     * @param string $query The user's query
     * @param string $response The generated response
     * @param array $metadata Additional metadata (intent, rag info, etc.)
     * @param string|null $apiKey Optional API key for embedding
     * @return RagCache|null The created cache entry
     */
    public function put(
        Bot $bot,
        string $query,
        string $response,
        array $metadata = [],
        ?string $apiKey = null
    ): ?RagCache {
        if (!$this->enabled) {
            return null;
        }

        try {
            // Generate embedding for semantic search
            $embeddingService = $apiKey
                ? $this->embeddingService->withApiKey($apiKey)
                : $this->embeddingService;

            $embedding = $embeddingService->generate($query);

            // Create cache entry
            $cache = RagCache::create([
                'bot_id' => $bot->id,
                'query_text' => $query,
                'query_normalized' => RagCache::normalizeQuery($query),
                'query_embedding' => new Vector($embedding),
                'response' => $response,
                'metadata' => $metadata,
                'created_at' => now(),
                'expires_at' => now()->addMinutes($this->ttlMinutes),
            ]);

            Log::debug('SemanticCache: Saved', [
                'bot_id' => $bot->id,
                'cache_id' => $cache->id,
                'query' => mb_substr($query, 0, 50),
                'ttl_minutes' => $this->ttlMinutes,
            ]);

            return $cache;
        } catch (\Exception $e) {
            Log::warning('SemanticCache: Error saving to cache', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get exact match from cache.
     */
    protected function getExactMatch(Bot $bot, string $query): ?RagCache
    {
        $normalized = RagCache::normalizeQuery($query);

        $cache = RagCache::forBot($bot->id)
            ->notExpired()
            ->where('query_normalized', $normalized)
            ->first();

        if ($cache) {
            $cache->recordHit();
        }

        return $cache;
    }

    /**
     * Get semantic match from cache using vector similarity.
     */
    protected function getSemanticMatch(Bot $bot, string $query, ?string $apiKey = null): ?array
    {
        // Generate embedding for the query
        $embeddingService = $apiKey
            ? $this->embeddingService->withApiKey($apiKey)
            : $this->embeddingService;

        $embedding = $embeddingService->generate($query);
        $vector = new Vector($embedding);

        // Search for similar cached queries
        // Using cosine distance (1 - cosine_similarity)
        // So we need to find where (1 - distance) >= threshold
        // Which means distance <= (1 - threshold)
        $maxDistance = 1 - $this->similarityThreshold;

        $result = DB::table('rag_cache')
            ->select([
                'id',
                'query_text',
                'response',
                'metadata',
                'hit_count',
                DB::raw("(1 - (query_embedding <=> '{$vector}')) as similarity"),
            ])
            ->where('bot_id', $bot->id)
            ->where('expires_at', '>', now())
            ->whereNotNull('query_embedding')
            ->whereRaw("(query_embedding <=> '{$vector}') <= ?", [$maxDistance])
            ->orderByRaw("query_embedding <=> '{$vector}' ASC")
            ->first();

        if (!$result) {
            return null;
        }

        // Get the full model and record hit
        $cache = RagCache::find($result->id);
        if ($cache) {
            $cache->recordHit();
        }

        return [
            'cache' => $cache,
            'similarity' => round($result->similarity, 4),
        ];
    }

    /**
     * Format cache hit response.
     */
    protected function formatCacheHit(RagCache $cache, string $matchType, ?float $similarity = null): array
    {
        return [
            'content' => $cache->response,
            'from_cache' => true,
            'cache_match_type' => $matchType,
            'cache_similarity' => $similarity ?? 1.0,
            'cache_id' => $cache->id,
            'cache_hit_count' => $cache->hit_count,
            'metadata' => $cache->metadata ?? [],
        ];
    }

    /**
     * Clean up expired cache entries.
     */
    public function cleanup(): int
    {
        $deleted = RagCache::where('expires_at', '<', now())->delete();

        Log::info('SemanticCache: Cleanup completed', [
            'deleted_count' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * Clear all cache entries for a specific bot.
     */
    public function clearForBot(int $botId): int
    {
        $deleted = RagCache::where('bot_id', $botId)->delete();

        Log::info('SemanticCache: Cleared cache for bot', [
            'bot_id' => $botId,
            'deleted_count' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * Get cache statistics for a bot.
     */
    public function getStats(int $botId): array
    {
        $total = RagCache::forBot($botId)->count();
        $active = RagCache::forBot($botId)->notExpired()->count();
        $totalHits = RagCache::forBot($botId)->sum('hit_count');

        $topHits = RagCache::forBot($botId)
            ->notExpired()
            ->orderBy('hit_count', 'desc')
            ->take(10)
            ->get(['query_text', 'hit_count', 'last_hit_at']);

        return [
            'enabled' => $this->enabled,
            'total_entries' => $total,
            'active_entries' => $active,
            'expired_entries' => $total - $active,
            'total_hits' => $totalHits,
            'avg_hits_per_entry' => $active > 0 ? round($totalHits / $active, 2) : 0,
            'similarity_threshold' => $this->similarityThreshold,
            'ttl_minutes' => $this->ttlMinutes,
            'top_queries' => $topHits->map(fn ($c) => [
                'query' => mb_substr($c->query_text, 0, 50),
                'hits' => $c->hit_count,
                'last_hit' => $c->last_hit_at?->toISOString(),
            ])->toArray(),
        ];
    }

    /**
     * Enable or disable cache at runtime.
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * Set similarity threshold at runtime.
     */
    public function setSimilarityThreshold(float $threshold): self
    {
        $this->similarityThreshold = max(0.0, min(1.0, $threshold));

        return $this;
    }
}
