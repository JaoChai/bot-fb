<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * QueryRewriterService
 *
 * Rewrites user queries to improve retrieval quality.
 * Used by CRAG when initial retrieval is ambiguous.
 *
 * Strategies:
 * - Expansion: Add synonyms and related terms
 * - Decomposition: Break complex queries into simpler parts
 * - Reformulation: Rephrase the query differently
 */
class QueryRewriterService
{
    public function __construct(
        protected OpenRouterService $openRouter
    ) {}

    /**
     * Rewrite a query for better retrieval.
     *
     * @param string $originalQuery The original user query
     * @param array $context Additional context (e.g., conversation history)
     * @param string|null $apiKey API key override
     * @return array{queries: array, strategy: string}
     */
    public function rewrite(
        string $originalQuery,
        array $context = [],
        ?string $apiKey = null
    ): array {
        try {
            $prompt = $this->buildRewritePrompt($originalQuery, $context);

            $response = $this->openRouter->chat(
                messages: [
                    ['role' => 'system', 'content' => $this->getSystemPrompt()],
                    ['role' => 'user', 'content' => $prompt],
                ],
                model: config('rag.query_enhancement.model', 'openai/gpt-4o-mini'),
                temperature: 0.3,
                maxTokens: 300,
                apiKeyOverride: $apiKey
            );

            $content = $response['content'] ?? '';
            $queries = $this->parseQueries($content, $originalQuery);

            Log::debug('QueryRewriter: Rewrote query', [
                'original' => $originalQuery,
                'rewritten' => $queries,
                'count' => count($queries),
            ]);

            return [
                'queries' => $queries,
                'strategy' => 'llm_rewrite',
                'original' => $originalQuery,
            ];

        } catch (\Exception $e) {
            Log::warning('QueryRewriter: Failed to rewrite query', [
                'error' => $e->getMessage(),
                'query' => $originalQuery,
            ]);

            // Fallback: use simple heuristic rewriting
            return $this->heuristicRewrite($originalQuery);
        }
    }

    /**
     * Build the rewrite prompt.
     */
    protected function buildRewritePrompt(string $query, array $context): string
    {
        $contextStr = '';
        if (!empty($context['conversation'])) {
            $contextStr = "\n\nConversation context:\n" . implode("\n", array_slice($context['conversation'], -3));
        }

        return <<<PROMPT
Rewrite this search query to find better matches in a knowledge base.

Original query: "{$query}"{$contextStr}

Generate 2-3 alternative queries that:
1. Use different words with similar meaning (synonyms)
2. Are more specific or focused
3. Capture the core intent differently

Respond with a JSON array of queries only:
["query 1", "query 2", "query 3"]
PROMPT;
    }

    /**
     * Get system prompt for query rewriting.
     */
    protected function getSystemPrompt(): string
    {
        return <<<PROMPT
You are a search query optimizer. Your task is to rewrite user queries to improve search results.

Rules:
- Generate 2-3 alternative queries
- Keep the same language as the original query
- Preserve the core intent
- Use different vocabulary when possible
- Make queries specific and focused
- Respond only with a JSON array of strings
PROMPT;
    }

    /**
     * Parse queries from LLM response.
     */
    protected function parseQueries(string $content, string $originalQuery): array
    {
        // Try to parse JSON array
        if (preg_match('/\[[^\]]+\]/', $content, $matches)) {
            $queries = json_decode($matches[0], true);
            if (is_array($queries)) {
                // Filter valid queries and add original
                $queries = array_filter($queries, fn($q) => is_string($q) && mb_strlen($q) > 2);
                $queries = array_values($queries);

                // Always include original query
                array_unshift($queries, $originalQuery);

                return array_unique($queries);
            }
        }

        // Fallback: return original only
        return [$originalQuery];
    }

    /**
     * Simple heuristic-based query rewriting (no API call).
     */
    protected function heuristicRewrite(string $query): array
    {
        $queries = [$query];

        // Strategy 1: Remove question words for direct search
        $cleanQuery = preg_replace(
            '/(what is|how to|what are|how do|why does|อะไรคือ|ทำอย่างไร|ยังไง)/iu',
            '',
            $query
        );
        $cleanQuery = trim($cleanQuery);
        if ($cleanQuery && $cleanQuery !== $query) {
            $queries[] = $cleanQuery;
        }

        // Strategy 2: Extract key phrases (quoted or emphasized)
        if (preg_match_all('/"([^"]+)"|\'([^\']+)\'/', $query, $matches)) {
            foreach (array_filter(array_merge($matches[1], $matches[2])) as $phrase) {
                $queries[] = $phrase;
            }
        }

        // Strategy 3: If query is long, try first half
        if (mb_strlen($query) > 50) {
            $words = preg_split('/\s+/', $query);
            $halfWords = array_slice($words, 0, (int) ceil(count($words) / 2));
            $queries[] = implode(' ', $halfWords);
        }

        return array_values(array_unique($queries));
    }

    /**
     * Decompose a complex query into simpler sub-queries.
     */
    public function decompose(string $query, ?string $apiKey = null): array
    {
        // Check if query seems complex
        $indicators = [
            '/\b(and|or|versus|vs|compared to|between)\b/i',
            '/[\,\;]/', // Multiple clauses
            '/(และ|หรือ|เทียบกับ|ระหว่าง)/u',
        ];

        $seemsComplex = false;
        foreach ($indicators as $pattern) {
            if (preg_match($pattern, $query)) {
                $seemsComplex = true;
                break;
            }
        }

        if (!$seemsComplex || mb_strlen($query) < 20) {
            return [$query];
        }

        // Split by common separators
        $parts = preg_split('/(\s+and\s+|\s+or\s+|[\,\;]|\s+และ\s+|\s+หรือ\s+)/iu', $query);
        $parts = array_filter(array_map('trim', $parts), fn($p) => mb_strlen($p) > 3);

        if (count($parts) > 1) {
            return array_values($parts);
        }

        return [$query];
    }

    /**
     * Expand query with synonyms (simple version without API).
     */
    public function expandSimple(string $query): string
    {
        // Common synonym mappings
        $synonyms = [
            'ราคา' => 'ราคา ค่าใช้จ่าย cost',
            'วิธี' => 'วิธี ขั้นตอน how to',
            'อะไร' => 'คืออะไร what is',
            'how' => 'how way method',
            'price' => 'price cost fee',
            'buy' => 'buy purchase order',
            'ซื้อ' => 'ซื้อ สั่ง order',
        ];

        $expanded = $query;
        foreach ($synonyms as $word => $expansion) {
            if (mb_stripos($query, $word) !== false) {
                $expanded .= ' ' . $expansion;
                break; // Only expand once
            }
        }

        return $expanded;
    }
}
