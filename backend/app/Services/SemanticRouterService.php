<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\SemanticRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pgvector\Laravel\Distance;

/**
 * SemanticRouterService
 *
 * Provides fast intent classification using vector similarity instead of LLM calls.
 * Reduces decision latency from 500-2000ms to 50-100ms.
 *
 * Usage:
 *   $result = $semanticRouter->classifyIntent($bot, "สวัสดีครับ");
 *   // Returns: ['intent' => 'chat', 'confidence' => 0.92, 'method' => 'semantic_router']
 */
class SemanticRouterService
{
    public function __construct(
        protected EmbeddingService $embeddingService
    ) {}

    /**
     * Classify intent using semantic similarity.
     *
     * @param Bot $bot The bot to classify for
     * @param string $message User's message
     * @param string|null $apiKey Optional API key override
     * @return array{intent: string, confidence: float, method: string, use_fallback?: bool}
     */
    public function classifyIntent(
        Bot $bot,
        string $message,
        ?string $apiKey = null
    ): array {
        // Check if semantic router is enabled for this bot
        if (!$bot->use_semantic_router) {
            return ['use_fallback' => true, 'reason' => 'semantic_router_disabled'];
        }

        $startTime = microtime(true);

        try {
            // Generate embedding for the user message
            $embeddingService = $apiKey
                ? $this->embeddingService->withApiKey($apiKey)
                : $this->embeddingService;

            $messageEmbedding = $embeddingService->generate($message);

            // Find nearest semantic routes using pgvector
            $routes = SemanticRoute::query()
                ->forBot($bot->id)
                ->nearestNeighbors('embedding', $messageEmbedding, Distance::Cosine)
                ->take(5)
                ->get();

            if ($routes->isEmpty()) {
                Log::debug('SemanticRouter: No routes found', ['bot_id' => $bot->id]);
                return $this->handleNoRoutes($bot);
            }

            // Calculate weighted scores by intent
            $intentScores = $this->aggregateIntentScores($routes);

            // Select best intent
            $bestIntent = $this->selectBestIntent($intentScores, $bot->semantic_router_threshold);

            $timeMs = round((microtime(true) - $startTime) * 1000);

            // If confidence too low, use fallback
            if ($bestIntent['confidence'] < $bot->semantic_router_threshold) {
                Log::debug('SemanticRouter: Confidence below threshold', [
                    'bot_id' => $bot->id,
                    'confidence' => $bestIntent['confidence'],
                    'threshold' => $bot->semantic_router_threshold,
                    'time_ms' => $timeMs,
                ]);

                if ($bot->semantic_router_fallback === 'llm') {
                    return [
                        'use_fallback' => true,
                        'reason' => 'low_confidence',
                        'semantic_score' => $bestIntent['confidence'],
                        'time_ms' => $timeMs,
                    ];
                }

                // Default to 'chat' if not using LLM fallback
                return [
                    'intent' => 'chat',
                    'confidence' => $bestIntent['confidence'],
                    'method' => 'semantic_default',
                    'time_ms' => $timeMs,
                ];
            }

            Log::debug('SemanticRouter: Classification successful', [
                'bot_id' => $bot->id,
                'intent' => $bestIntent['intent'],
                'confidence' => $bestIntent['confidence'],
                'time_ms' => $timeMs,
            ]);

            return [
                'intent' => $bestIntent['intent'],
                'confidence' => $bestIntent['confidence'],
                'method' => 'semantic_router',
                'top_matches' => $bestIntent['matches'],
                'time_ms' => $timeMs,
            ];
        } catch (\Exception $e) {
            Log::error('SemanticRouter: Classification failed', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);

            // Fall back to LLM or default
            return [
                'use_fallback' => true,
                'reason' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Aggregate similarity scores by intent with weighting.
     */
    protected function aggregateIntentScores(Collection $routes): array
    {
        $intentScores = [];

        foreach ($routes as $route) {
            $intent = $route->intent;
            // pgvector returns distance, convert to similarity (1 - distance/2 for cosine)
            $distance = $route->neighbor_distance ?? 0;
            $similarity = 1 - ($distance / 2);
            $weightedScore = $similarity * $route->weight;

            if (!isset($intentScores[$intent])) {
                $intentScores[$intent] = [
                    'total_score' => 0,
                    'count' => 0,
                    'max_score' => 0,
                    'matches' => [],
                ];
            }

            $intentScores[$intent]['total_score'] += $weightedScore;
            $intentScores[$intent]['count']++;
            $intentScores[$intent]['max_score'] = max($intentScores[$intent]['max_score'], $similarity);
            $intentScores[$intent]['matches'][] = [
                'phrase' => $route->example_phrase,
                'similarity' => round($similarity, 4),
                'language' => $route->language,
            ];
        }

        // Calculate average score for each intent
        foreach ($intentScores as $intent => &$data) {
            $data['avg_score'] = $data['total_score'] / $data['count'];
        }

        return $intentScores;
    }

    /**
     * Select the best intent based on aggregated scores.
     */
    protected function selectBestIntent(array $intentScores, float $threshold): array
    {
        if (empty($intentScores)) {
            return [
                'intent' => 'chat',
                'confidence' => 0,
                'matches' => [],
            ];
        }

        // Sort by max score (best single match wins)
        uasort($intentScores, fn($a, $b) => $b['max_score'] <=> $a['max_score']);

        $bestIntent = array_key_first($intentScores);
        $bestData = $intentScores[$bestIntent];

        return [
            'intent' => $bestIntent,
            'confidence' => $bestData['max_score'],
            'avg_confidence' => $bestData['avg_score'],
            'matches' => array_slice($bestData['matches'], 0, 3),
        ];
    }

    /**
     * Handle case when no routes are found.
     */
    protected function handleNoRoutes(Bot $bot): array
    {
        if ($bot->semantic_router_fallback === 'llm') {
            return ['use_fallback' => true, 'reason' => 'no_routes'];
        }

        // Default to 'chat' if no routes and not using LLM fallback
        return [
            'intent' => 'chat',
            'confidence' => 0,
            'method' => 'semantic_default',
            'reason' => 'no_routes',
        ];
    }

    /**
     * Create a semantic route with embedding.
     */
    public function createRoute(
        ?int $botId,
        string $intent,
        string $phrase,
        string $language = 'th',
        float $weight = 1.0,
        ?string $apiKey = null
    ): SemanticRoute {
        $embeddingService = $apiKey
            ? $this->embeddingService->withApiKey($apiKey)
            : $this->embeddingService;

        $embedding = $embeddingService->generate($phrase);

        return SemanticRoute::create([
            'bot_id' => $botId,
            'intent' => $intent,
            'language' => $language,
            'example_phrase' => $phrase,
            'embedding' => $embedding,
            'weight' => $weight,
            'is_active' => true,
        ]);
    }

    /**
     * Seed default routes for a bot (or globally if botId is null).
     */
    public function seedDefaultRoutes(?int $botId = null, ?string $apiKey = null): int
    {
        $defaults = [
            // Thai chat examples
            ['intent' => 'chat', 'language' => 'th', 'phrase' => 'สวัสดี'],
            ['intent' => 'chat', 'language' => 'th', 'phrase' => 'สวัสดีครับ'],
            ['intent' => 'chat', 'language' => 'th', 'phrase' => 'สวัสดีค่ะ'],
            ['intent' => 'chat', 'language' => 'th', 'phrase' => 'ขอบคุณครับ'],
            ['intent' => 'chat', 'language' => 'th', 'phrase' => 'ขอบคุณค่ะ'],
            ['intent' => 'chat', 'language' => 'th', 'phrase' => 'ไง'],
            ['intent' => 'chat', 'language' => 'th', 'phrase' => 'หวัดดี'],
            ['intent' => 'chat', 'language' => 'th', 'phrase' => 'ดีจ้า'],
            ['intent' => 'chat', 'language' => 'th', 'phrase' => 'บาย'],
            ['intent' => 'chat', 'language' => 'th', 'phrase' => 'ลาก่อน'],

            // English chat examples
            ['intent' => 'chat', 'language' => 'en', 'phrase' => 'hi'],
            ['intent' => 'chat', 'language' => 'en', 'phrase' => 'hello'],
            ['intent' => 'chat', 'language' => 'en', 'phrase' => 'hey'],
            ['intent' => 'chat', 'language' => 'en', 'phrase' => 'thanks'],
            ['intent' => 'chat', 'language' => 'en', 'phrase' => 'thank you'],
            ['intent' => 'chat', 'language' => 'en', 'phrase' => 'bye'],
            ['intent' => 'chat', 'language' => 'en', 'phrase' => 'goodbye'],

            // Thai knowledge examples
            ['intent' => 'knowledge', 'language' => 'th', 'phrase' => 'ราคาเท่าไหร่'],
            ['intent' => 'knowledge', 'language' => 'th', 'phrase' => 'ราคาสินค้า'],
            ['intent' => 'knowledge', 'language' => 'th', 'phrase' => 'วิธีใช้งาน'],
            ['intent' => 'knowledge', 'language' => 'th', 'phrase' => 'วิธีการสั่งซื้อ'],
            ['intent' => 'knowledge', 'language' => 'th', 'phrase' => 'มีอะไรบ้าง'],
            ['intent' => 'knowledge', 'language' => 'th', 'phrase' => 'รายละเอียดสินค้า'],
            ['intent' => 'knowledge', 'language' => 'th', 'phrase' => 'อธิบายเกี่ยวกับ'],
            ['intent' => 'knowledge', 'language' => 'th', 'phrase' => 'ข้อมูลเพิ่มเติม'],
            ['intent' => 'knowledge', 'language' => 'th', 'phrase' => 'คุณสมบัติ'],
            ['intent' => 'knowledge', 'language' => 'th', 'phrase' => 'สเปค'],

            // English knowledge examples
            ['intent' => 'knowledge', 'language' => 'en', 'phrase' => 'how to'],
            ['intent' => 'knowledge', 'language' => 'en', 'phrase' => 'what is'],
            ['intent' => 'knowledge', 'language' => 'en', 'phrase' => 'how much'],
            ['intent' => 'knowledge', 'language' => 'en', 'phrase' => 'price of'],
            ['intent' => 'knowledge', 'language' => 'en', 'phrase' => 'tell me about'],
            ['intent' => 'knowledge', 'language' => 'en', 'phrase' => 'explain'],
            ['intent' => 'knowledge', 'language' => 'en', 'phrase' => 'information about'],
            ['intent' => 'knowledge', 'language' => 'en', 'phrase' => 'specifications'],
        ];

        $created = 0;
        foreach ($defaults as $route) {
            try {
                $this->createRoute(
                    $botId,
                    $route['intent'],
                    $route['phrase'],
                    $route['language'],
                    1.0,
                    $apiKey
                );
                $created++;
            } catch (\Exception $e) {
                Log::warning('Failed to create default route', [
                    'phrase' => $route['phrase'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('SemanticRouter: Default routes seeded', [
            'bot_id' => $botId,
            'created' => $created,
            'total' => count($defaults),
        ]);

        return $created;
    }

    /**
     * Delete all routes for a bot.
     */
    public function deleteRoutesForBot(int $botId): int
    {
        return SemanticRoute::where('bot_id', $botId)->delete();
    }

    /**
     * Get route statistics for a bot.
     */
    public function getRouteStats(?int $botId): array
    {
        $query = SemanticRoute::query()->forBot($botId);

        return [
            'total' => $query->count(),
            'by_intent' => $query->selectRaw('intent, count(*) as count')
                ->groupBy('intent')
                ->pluck('count', 'intent')
                ->toArray(),
            'by_language' => $query->selectRaw('language, count(*) as count')
                ->groupBy('language')
                ->pluck('count', 'language')
                ->toArray(),
        ];
    }
}
