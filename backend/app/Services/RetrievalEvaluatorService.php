<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * RetrievalEvaluatorService
 *
 * Implements Corrective RAG (CRAG) retrieval evaluation.
 * Evaluates the quality of retrieved chunks and classifies them as:
 * - Correct: Relevant and useful (use directly)
 * - Ambiguous: Partially relevant (rewrite query and retry)
 * - Incorrect: Not relevant (skip KB, use general knowledge)
 *
 * Based on: "Corrective Retrieval Augmented Generation" (2024)
 */
class RetrievalEvaluatorService
{
    /**
     * Evaluation thresholds
     */
    protected float $correctThreshold = 0.7;
    protected float $ambiguousThreshold = 0.3;

    /**
     * Relevance signals for heuristic evaluation
     */
    protected array $positiveSignals = [
        'exact_match' => 0.3,
        'semantic_similarity' => 0.25,
        'keyword_overlap' => 0.2,
        'entity_match' => 0.15,
        'length_ratio' => 0.1,
    ];

    public function __construct(
        protected ?OpenRouterService $openRouter = null
    ) {}

    /**
     * Evaluate retrieval results quality.
     *
     * @param string $query Original user query
     * @param Collection $results Retrieved chunks
     * @param string $mode Evaluation mode: 'heuristics', 'llm', 'hybrid'
     * @param string|null $apiKey API key for LLM evaluation
     * @return array{grade: string, score: float, action: string, details: array}
     */
    public function evaluate(
        string $query,
        Collection $results,
        string $mode = 'heuristics',
        ?string $apiKey = null
    ): array {
        if ($results->isEmpty()) {
            return [
                'grade' => 'incorrect',
                'score' => 0,
                'action' => 'skip_kb',
                'reason' => 'no_results',
                'details' => [],
            ];
        }

        $score = match ($mode) {
            'llm' => $this->evaluateWithLLM($query, $results, $apiKey),
            'hybrid' => $this->evaluateHybrid($query, $results, $apiKey),
            default => $this->evaluateWithHeuristics($query, $results),
        };

        $grade = $this->scoreToGrade($score);
        $action = $this->gradeToAction($grade);

        Log::debug('RetrievalEvaluator: Evaluation complete', [
            'mode' => $mode,
            'score' => $score,
            'grade' => $grade,
            'action' => $action,
            'results_count' => $results->count(),
        ]);

        return [
            'grade' => $grade,
            'score' => $score,
            'action' => $action,
            'details' => [
                'mode' => $mode,
                'results_evaluated' => $results->count(),
                'top_similarity' => $results->max('similarity'),
                'avg_similarity' => $results->avg('similarity'),
            ],
        ];
    }

    /**
     * Evaluate using heuristics (fast, no API call).
     */
    protected function evaluateWithHeuristics(string $query, Collection $results): float
    {
        $scores = [];

        foreach ($results as $result) {
            $chunkScore = 0;
            $content = $result['content'] ?? '';
            $similarity = $result['similarity'] ?? 0;

            // Factor 1: Semantic similarity from vector search
            $chunkScore += $similarity * $this->positiveSignals['semantic_similarity'] * 2;

            // Factor 2: Keyword overlap
            $keywordScore = $this->calculateKeywordOverlap($query, $content);
            $chunkScore += $keywordScore * $this->positiveSignals['keyword_overlap'];

            // Factor 3: Length ratio (too short = less useful)
            $lengthRatio = min(1, mb_strlen($content) / 200);
            $chunkScore += $lengthRatio * $this->positiveSignals['length_ratio'];

            // Factor 4: Question word matching
            if ($this->hasQuestionWordMatch($query, $content)) {
                $chunkScore += 0.1;
            }

            $scores[] = min(1, $chunkScore);
        }

        // Aggregate: use weighted average favoring top results
        if (empty($scores)) {
            return 0;
        }

        // Top result gets 50% weight, rest divided equally
        $topScore = array_shift($scores);
        $restAvg = !empty($scores) ? array_sum($scores) / count($scores) : 0;

        return ($topScore * 0.6) + ($restAvg * 0.4);
    }

    /**
     * Evaluate using LLM (more accurate, costs API call).
     */
    protected function evaluateWithLLM(
        string $query,
        Collection $results,
        ?string $apiKey = null
    ): float {
        if (!$this->openRouter) {
            Log::warning('RetrievalEvaluator: OpenRouter not available, falling back to heuristics');
            return $this->evaluateWithHeuristics($query, $results);
        }

        // Prepare context from top results
        $context = $results->take(3)->map(function ($r, $i) {
            return "[Document " . ($i + 1) . "]: " . mb_substr($r['content'], 0, 500);
        })->join("\n\n");

        $prompt = <<<PROMPT
Evaluate how relevant the following documents are for answering the user's question.

User Question: {$query}

Retrieved Documents:
{$context}

Rate the relevance on a scale of 0.0 to 1.0:
- 1.0: Highly relevant, directly answers the question
- 0.7-0.9: Relevant, contains useful information
- 0.3-0.6: Partially relevant, might help but needs more context
- 0.0-0.2: Not relevant, doesn't help answer the question

Respond with ONLY a JSON object: {"score": 0.X, "reasoning": "brief explanation"}
PROMPT;

        try {
            $response = $this->openRouter->chat(
                messages: [
                    ['role' => 'system', 'content' => 'You are a relevance evaluator. Respond only with JSON.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                model: config('rag.crag.evaluation_model', 'openai/gpt-4o-mini'),
                temperature: 0.1,
                maxTokens: 100,
                apiKeyOverride: $apiKey
            );

            $content = $response['content'] ?? '';

            // Parse JSON response
            if (preg_match('/\{[^}]+\}/', $content, $matches)) {
                $data = json_decode($matches[0], true);
                return (float) ($data['score'] ?? 0.5);
            }
        } catch (\Exception $e) {
            Log::warning('RetrievalEvaluator: LLM evaluation failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to heuristics if LLM fails
        return $this->evaluateWithHeuristics($query, $results);
    }

    /**
     * Hybrid evaluation: heuristics first, LLM for borderline cases.
     */
    protected function evaluateHybrid(
        string $query,
        Collection $results,
        ?string $apiKey = null
    ): float {
        $heuristicScore = $this->evaluateWithHeuristics($query, $results);

        // Only use LLM for borderline cases
        if ($heuristicScore >= 0.75 || $heuristicScore <= 0.25) {
            return $heuristicScore;
        }

        // Borderline: use LLM for more accurate evaluation
        return $this->evaluateWithLLM($query, $results, $apiKey);
    }

    /**
     * Convert score to grade.
     */
    protected function scoreToGrade(float $score): string
    {
        if ($score >= $this->correctThreshold) {
            return 'correct';
        }
        if ($score >= $this->ambiguousThreshold) {
            return 'ambiguous';
        }
        return 'incorrect';
    }

    /**
     * Convert grade to recommended action.
     */
    protected function gradeToAction(string $grade): string
    {
        return match ($grade) {
            'correct' => 'use_results',
            'ambiguous' => 'rewrite_query',
            'incorrect' => 'skip_kb',
        };
    }

    /**
     * Calculate keyword overlap between query and content.
     */
    protected function calculateKeywordOverlap(string $query, string $content): float
    {
        // Extract meaningful words (3+ chars)
        $queryWords = $this->extractKeywords($query);
        $contentWords = $this->extractKeywords($content);

        if (empty($queryWords)) {
            return 0;
        }

        $matches = count(array_intersect($queryWords, $contentWords));
        return $matches / count($queryWords);
    }

    /**
     * Extract keywords from text.
     */
    protected function extractKeywords(string $text): array
    {
        // Convert to lowercase and split by non-word characters
        $text = mb_strtolower($text);

        // Handle Thai text (no spaces between words)
        // For Thai, we'll use character n-grams as a simple approximation
        $words = preg_split('/[\s\p{P}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Filter short words and stopwords
        $stopwords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'and', 'or', 'of', 'to',
            'ที่', 'และ', 'หรือ', 'ใน', 'ของ', 'มี', 'เป็น', 'จะ', 'ได้', 'ให้'];

        return array_values(array_filter($words, function ($word) use ($stopwords) {
            return mb_strlen($word) >= 2 && !in_array($word, $stopwords);
        }));
    }

    /**
     * Check if content contains answer to question words.
     */
    protected function hasQuestionWordMatch(string $query, string $content): bool
    {
        $questionPatterns = [
            // English
            '/\b(what|how|why|when|where|who|which)\b/i',
            // Thai
            '/(อะไร|ยังไง|ทำไม|เมื่อไหร่|ที่ไหน|ใคร|อันไหน)/u',
        ];

        $hasQuestion = false;
        foreach ($questionPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                $hasQuestion = true;
                break;
            }
        }

        if (!$hasQuestion) {
            return false;
        }

        // Check if content has informative markers
        $answerPatterns = [
            '/\d+/', // Contains numbers
            '/[\$฿€£]/', // Contains currency
            '/(คือ|หมายถึง|ได้แก่|ประกอบด้วย)/u', // Thai definition markers
            '/(is|are|means|refers to|includes)/i', // English definition markers
        ];

        foreach ($answerPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set evaluation thresholds.
     */
    public function setThresholds(float $correct, float $ambiguous): self
    {
        $this->correctThreshold = $correct;
        $this->ambiguousThreshold = $ambiguous;
        return $this;
    }
}
