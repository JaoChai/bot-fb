<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Corrective RAG (CRAG) Service
 *
 * Evaluates retrieval quality and takes corrective action when results
 * are ambiguous or incorrect. Uses heuristics-based evaluation (similarity
 * scores) to avoid additional LLM calls in most cases.
 *
 * Grades:
 * - "correct": top similarity > threshold → use results directly
 * - "ambiguous": mid-range similarity → rewrite query and re-search
 * - "incorrect": low similarity → skip KB, use general knowledge
 *
 * Based on: "Corrective Retrieval Augmented Generation" (2024)
 */
class CRAGService
{
    public const GRADE_CORRECT = 'correct';

    public const GRADE_AMBIGUOUS = 'ambiguous';

    public const GRADE_INCORRECT = 'incorrect';

    protected bool $enabled;

    protected string $evaluationMode;

    protected string $evaluationModel;

    protected float $correctThreshold;

    protected float $ambiguousThreshold;

    protected int $maxRewriteAttempts;

    protected string $incorrectAction;

    public function __construct(
        protected OpenRouterService $openRouter
    ) {
        $this->enabled = (bool) config('rag.crag.enabled', false);
        $this->evaluationMode = config('rag.crag.evaluation_mode', 'heuristics');
        $this->evaluationModel = config('rag.crag.evaluation_model', 'openai/gpt-4o-mini');
        $this->correctThreshold = (float) config('rag.crag.correct_threshold', 0.7);
        $this->ambiguousThreshold = (float) config('rag.crag.ambiguous_threshold', 0.3);
        $this->maxRewriteAttempts = (int) config('rag.crag.max_rewrite_attempts', 2);
        $this->incorrectAction = config('rag.crag.incorrect_action', 'skip_kb');
    }

    /**
     * Check if CRAG is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Evaluate retrieval quality using heuristics (similarity scores).
     *
     * @param  Collection  $results  Search results with 'similarity' scores
     * @param  string  $query  The original search query
     * @return array{grade: string, top_similarity: float, reason: string}
     */
    public function evaluate(Collection $results, string $query = ''): array
    {
        if ($results->isEmpty()) {
            return [
                'grade' => self::GRADE_INCORRECT,
                'top_similarity' => 0.0,
                'reason' => 'no_results',
            ];
        }

        $topSimilarity = $results->max('similarity') ?? 0.0;

        if ($topSimilarity >= $this->correctThreshold) {
            return [
                'grade' => self::GRADE_CORRECT,
                'top_similarity' => $topSimilarity,
                'reason' => 'high_similarity',
            ];
        }

        if ($topSimilarity >= $this->ambiguousThreshold) {
            return [
                'grade' => self::GRADE_AMBIGUOUS,
                'top_similarity' => $topSimilarity,
                'reason' => 'mid_similarity',
            ];
        }

        return [
            'grade' => self::GRADE_INCORRECT,
            'top_similarity' => $topSimilarity,
            'reason' => 'low_similarity',
        ];
    }

    /**
     * Rewrite a query using LLM when retrieval results are ambiguous.
     *
     * @param  string  $originalQuery  The original user query
     * @param  Collection  $failedResults  Results that didn't meet threshold
     * @param  string|null  $apiKey  Optional API key override
     * @return string The rewritten query
     */
    public function rewriteQuery(string $originalQuery, Collection $failedResults, ?string $apiKey = null): string
    {
        try {
            $topChunks = $failedResults->take(3)->pluck('content')->implode("\n---\n");

            $systemPrompt = <<<'PROMPT'
You are a search query optimizer. The user's original query did not find good results in the knowledge base.

Given the original query and the top (but low-relevance) results, rewrite the query to better match the knowledge base content.

Rules:
1. Keep the same language as the original query
2. Make the query more specific or use different terminology
3. Return ONLY the rewritten query, nothing else
4. Keep it concise (under 100 characters)
PROMPT;

            $userPrompt = "Original query: {$originalQuery}\n\nTop results found (low relevance):\n{$topChunks}\n\nRewritten query:";

            $response = $this->openRouter->chat(
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                model: $this->evaluationModel,
                temperature: 0.3,
                maxTokens: 100,
                useFallback: false,
                apiKeyOverride: $apiKey
            );

            $rewritten = trim($response['content'] ?? '');

            if (empty($rewritten) || $rewritten === $originalQuery) {
                return $originalQuery;
            }

            Log::debug('CRAG: Query rewritten', [
                'original' => $originalQuery,
                'rewritten' => $rewritten,
            ]);

            return $rewritten;
        } catch (\Exception $e) {
            Log::warning('CRAG: Query rewrite failed, using original', [
                'error' => $e->getMessage(),
                'query' => substr($originalQuery, 0, 50),
            ]);

            return $originalQuery;
        }
    }

    /**
     * Get the maximum number of rewrite attempts.
     */
    public function getMaxRewriteAttempts(): int
    {
        return $this->maxRewriteAttempts;
    }

    /**
     * Get the action to take when grade is "incorrect".
     */
    public function getIncorrectAction(): string
    {
        return $this->incorrectAction;
    }
}
