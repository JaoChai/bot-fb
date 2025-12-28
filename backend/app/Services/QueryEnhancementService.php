<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Query Enhancement Service
 *
 * Uses LLM to expand and rewrite user queries for better retrieval.
 * Generates multiple search variations from a single query to improve recall.
 *
 * Research shows multi-query approaches can improve recall by 20-48%
 * (Haystack, Microsoft Azure AI Search, 2024)
 */
class QueryEnhancementService
{
    protected bool $enabled;
    protected string $model;
    protected int $maxVariations;
    protected int $minQueryLength;
    protected int $timeout;

    public function __construct(
        protected OpenRouterService $openRouter
    ) {
        $this->enabled = (bool) config('rag.query_enhancement.enabled', false);
        $this->model = config('rag.query_enhancement.model', 'openai/gpt-4o-mini');
        $this->maxVariations = (int) config('rag.query_enhancement.max_variations', 3);
        $this->minQueryLength = (int) config('rag.query_enhancement.min_query_length', 2);
        $this->timeout = (int) config('rag.query_enhancement.timeout', 5);
    }

    /**
     * Enhance a query by generating search variations.
     *
     * @param string $query The original user query
     * @param array|null $context Optional context (bot_name, kb_topics)
     * @return array Enhanced query with variations
     */
    public function enhance(string $query, ?array $context = null): array
    {
        $result = [
            'original' => $query,
            'variations' => [$query], // Always include original
            'was_enhanced' => false,
            'reasoning' => null,
        ];

        // Skip if disabled or query too short
        if (!$this->isEnabled() || !$this->shouldEnhance($query)) {
            Log::debug('QueryEnhancement: Skipped', [
                'enabled' => $this->enabled,
                'query_length' => mb_strlen($query),
                'min_length' => $this->minQueryLength,
            ]);
            return $result;
        }

        try {
            $systemPrompt = $this->buildSystemPrompt($context);
            $userPrompt = $this->buildUserPrompt($query);

            $response = $this->openRouter->chat(
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                model: $this->model,
                temperature: 0.3, // Lower temp for more consistent output
                maxTokens: 200,
                useFallback: false // Don't waste time on fallback for enhancement
            );

            $parsed = $this->parseResponse($response['content'] ?? '');

            if (!empty($parsed['variations'])) {
                // Merge original with LLM variations, deduplicate
                $allVariations = array_unique(array_merge(
                    [$query],
                    array_slice($parsed['variations'], 0, $this->maxVariations)
                ));

                $result['variations'] = array_values($allVariations);
                $result['was_enhanced'] = true;
                $result['reasoning'] = $parsed['reasoning'] ?? null;

                Log::debug('QueryEnhancement: Success', [
                    'original' => $query,
                    'variations_count' => count($result['variations']),
                    'reasoning' => $result['reasoning'],
                ]);
            }

        } catch (\Exception $e) {
            Log::warning('QueryEnhancement: Failed, using original query', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 50),
            ]);
            // Return original query on any error
        }

        return $result;
    }

    /**
     * Check if enhancement is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Determine if a query should be enhanced.
     * Uses heuristics to decide.
     */
    public function shouldEnhance(string $query): bool
    {
        $length = mb_strlen(trim($query));

        // Too short - probably incomplete
        if ($length < $this->minQueryLength) {
            return false;
        }

        // Very long queries are usually specific enough
        if ($length > 100) {
            return false;
        }

        return true;
    }

    /**
     * Build the system prompt for query enhancement.
     */
    protected function buildSystemPrompt(?array $context): string
    {
        $botName = $context['bot_name'] ?? 'AI Assistant';
        $kbTopics = $context['kb_topics'] ?? '';

        return <<<PROMPT
You are a search query optimizer for a Thai/English knowledge base.

Given the user query, generate 2-3 search variations that would find relevant documents.

Rules:
1. Keep original language (Thai stays Thai, English stays English)
2. Expand abbreviations (e.g., "สค." → "สิงหาคม", "รร." → "โรงเรียน")
3. Add synonyms for key terms
4. If query is vague, make it more specific
5. If query is a question, include statement form
6. Include both Thai and English variations if applicable

Bot context: {$botName}
Knowledge base topics: {$kbTopics}

Return ONLY valid JSON in this exact format:
{"variations": ["query1", "query2", "query3"], "reasoning": "Brief explanation"}
PROMPT;
    }

    /**
     * Build the user prompt with the query.
     */
    protected function buildUserPrompt(string $query): string
    {
        return "User Query: {$query}";
    }

    /**
     * Parse the LLM response to extract variations.
     */
    protected function parseResponse(string $content): array
    {
        $result = [
            'variations' => [],
            'reasoning' => null,
        ];

        // Try to find JSON in the response
        $content = trim($content);

        // Handle markdown code blocks
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        // Try to extract JSON object
        if (preg_match('/\{[^{}]*"variations"[^{}]*\}/s', $content, $matches)) {
            $content = $matches[0];
        }

        try {
            $decoded = json_decode($content, true);

            if (is_array($decoded)) {
                if (isset($decoded['variations']) && is_array($decoded['variations'])) {
                    $result['variations'] = array_filter($decoded['variations'], fn($v) =>
                        is_string($v) && !empty(trim($v))
                    );
                }
                if (isset($decoded['reasoning']) && is_string($decoded['reasoning'])) {
                    $result['reasoning'] = $decoded['reasoning'];
                }
            }
        } catch (\Exception $e) {
            Log::debug('QueryEnhancement: JSON parse failed', [
                'content' => substr($content, 0, 200),
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Enable or disable enhancement at runtime.
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Get enhancement status for metadata.
     */
    public function getStatus(): array
    {
        return [
            'enabled' => $this->enabled,
            'model' => $this->model,
            'max_variations' => $this->maxVariations,
        ];
    }

    /**
     * Test the enhancement service.
     */
    public function test(): array
    {
        $testQuery = "ราคา";
        $result = $this->enhance($testQuery, [
            'bot_name' => 'Test Bot',
            'kb_topics' => 'products, services, pricing',
        ]);

        return [
            'enabled' => $this->enabled,
            'model' => $this->model,
            'test_query' => $testQuery,
            'result' => $result,
        ];
    }
}
