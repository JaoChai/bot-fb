<?php

namespace App\Services;

use App\Models\Bot;
use Illuminate\Support\Facades\Log;

/**
 * ConfidenceCascadeService
 *
 * Implements cost-effective LLM routing by trying cheaper models first
 * and only escalating to expensive models when confidence is low.
 *
 * Based on RouteLLM research: https://lmsys.org/blog/2024-07-01-routellm/
 * Can reduce costs by 50-85% while maintaining 95% quality.
 */
class ConfidenceCascadeService
{
    /**
     * Uncertainty phrases that indicate low confidence.
     */
    protected array $uncertaintyPhrases = [
        // English
        "i'm not sure",
        "i don't know",
        "i'm uncertain",
        "i cannot",
        "it's unclear",
        "i'm not able",
        "i'm not certain",
        "i can't",
        "i am not sure",
        "i do not know",
        // Thai
        'ไม่แน่ใจ',
        'ไม่ทราบ',
        'ไม่รู้',
        'ไม่สามารถ',
        'ไม่ชัดเจน',
        'ไม่ค่อยแน่ใจ',
        'อาจจะไม่',
        'คงไม่',
    ];

    /**
     * Hedging patterns that suggest uncertainty.
     */
    protected array $hedgingPatterns = [
        '/\b(maybe|perhaps|possibly|might|could be|probably)\b/i',
        '/\b(อาจจะ|บางที|น่าจะ|คงจะ|เป็นไปได้ว่า)\b/u',
    ];

    public function __construct(
        protected OpenRouterService $openRouter
    ) {}

    /**
     * Generate response with confidence-based model cascade.
     *
     * @param Bot $bot The bot
     * @param string $userMessage User's message
     * @param string $systemPrompt System prompt
     * @param array $conversationHistory Conversation history
     * @param string|null $apiKey API key override
     * @return array Response with cascade metadata
     */
    public function generateWithCascade(
        Bot $bot,
        string $userMessage,
        string $systemPrompt,
        array $conversationHistory,
        ?string $apiKey = null
    ): array {
        // If cascade disabled, use normal generation
        if (!$bot->use_confidence_cascade) {
            return $this->generateNormal($bot, $userMessage, $systemPrompt, $conversationHistory, $apiKey);
        }

        $cheapModel = $bot->cascade_cheap_model ?? config('rag.confidence_cascade.cheap_model', 'openai/gpt-4o-mini');
        $expensiveModel = $bot->cascade_expensive_model ?? $bot->primary_chat_model ?? config('rag.confidence_cascade.expensive_model', 'openai/gpt-4o');
        $threshold = $bot->cascade_confidence_threshold ?? config('rag.confidence_cascade.threshold', 0.7);

        $startTime = microtime(true);

        // Step 1: Try cheap model first
        try {
            $cheapResult = $this->openRouter->generateBotResponse(
                userMessage: $userMessage,
                systemPrompt: $systemPrompt,
                conversationHistory: $conversationHistory,
                model: $cheapModel,
                fallbackModel: null, // Don't use fallback here
                temperature: $bot->llm_temperature ?? 0.7,
                maxTokens: $bot->llm_max_tokens ?? 2048,
                apiKeyOverride: $apiKey
            );
        } catch (\Exception $e) {
            // If cheap model fails, go straight to expensive model
            Log::warning('ConfidenceCascade: Cheap model failed, using expensive model', [
                'bot_id' => $bot->id,
                'cheap_model' => $cheapModel,
                'error' => $e->getMessage(),
            ]);

            return $this->escalateToExpensive(
                $bot,
                $userMessage,
                $systemPrompt,
                $conversationHistory,
                $apiKey,
                $expensiveModel,
                'cheap_model_error'
            );
        }

        // Step 2: Assess confidence
        $confidence = $this->assessConfidence($cheapResult['content'] ?? '', $userMessage);
        $cheapTimeMs = round((microtime(true) - $startTime) * 1000);

        // Step 3: If confidence is high enough, return cheap result
        if ($confidence >= $threshold) {
            Log::debug('ConfidenceCascade: Using cheap model response', [
                'bot_id' => $bot->id,
                'model' => $cheapModel,
                'confidence' => $confidence,
                'threshold' => $threshold,
                'time_ms' => $cheapTimeMs,
            ]);

            $cheapResult['cascade'] = [
                'used_cheap_model' => true,
                'escalated' => false,
                'confidence' => $confidence,
                'threshold' => $threshold,
                'model_used' => $cheapModel,
                'time_ms' => $cheapTimeMs,
            ];

            return $cheapResult;
        }

        // Step 4: Escalate to expensive model
        Log::info('ConfidenceCascade: Escalating to expensive model', [
            'bot_id' => $bot->id,
            'cheap_model' => $cheapModel,
            'expensive_model' => $expensiveModel,
            'confidence' => $confidence,
            'threshold' => $threshold,
        ]);

        return $this->escalateToExpensive(
            $bot,
            $userMessage,
            $systemPrompt,
            $conversationHistory,
            $apiKey,
            $expensiveModel,
            'low_confidence',
            [
                'cheap_confidence' => $confidence,
                'cheap_model' => $cheapModel,
                'cheap_time_ms' => $cheapTimeMs,
            ]
        );
    }

    /**
     * Escalate to expensive model.
     */
    protected function escalateToExpensive(
        Bot $bot,
        string $userMessage,
        string $systemPrompt,
        array $conversationHistory,
        ?string $apiKey,
        string $expensiveModel,
        string $reason,
        array $extraMeta = []
    ): array {
        $startTime = microtime(true);

        $expensiveResult = $this->openRouter->generateBotResponse(
            userMessage: $userMessage,
            systemPrompt: $systemPrompt,
            conversationHistory: $conversationHistory,
            model: $expensiveModel,
            fallbackModel: $bot->fallback_chat_model,
            temperature: $bot->llm_temperature ?? 0.7,
            maxTokens: $bot->llm_max_tokens ?? 2048,
            apiKeyOverride: $apiKey
        );

        $expensiveTimeMs = round((microtime(true) - $startTime) * 1000);

        $expensiveResult['cascade'] = array_merge([
            'used_cheap_model' => $reason !== 'cheap_model_error',
            'escalated' => true,
            'escalation_reason' => $reason,
            'model_used' => $expensiveModel,
            'expensive_time_ms' => $expensiveTimeMs,
        ], $extraMeta);

        return $expensiveResult;
    }

    /**
     * Generate response without cascade (normal mode).
     */
    protected function generateNormal(
        Bot $bot,
        string $userMessage,
        string $systemPrompt,
        array $conversationHistory,
        ?string $apiKey
    ): array {
        $model = $bot->primary_chat_model ?? $bot->llm_model ?? config('services.openrouter.default_model');

        $result = $this->openRouter->generateBotResponse(
            userMessage: $userMessage,
            systemPrompt: $systemPrompt,
            conversationHistory: $conversationHistory,
            model: $model,
            fallbackModel: $bot->fallback_chat_model ?? $bot->llm_fallback_model,
            temperature: $bot->llm_temperature ?? 0.7,
            maxTokens: $bot->llm_max_tokens ?? 2048,
            apiKeyOverride: $apiKey
        );

        $result['cascade'] = [
            'enabled' => false,
            'model_used' => $result['model'] ?? $model,
        ];

        return $result;
    }

    /**
     * Assess confidence of a response.
     *
     * @param string $response The LLM response
     * @param string $question The original question
     * @return float Confidence score (0.0 - 1.0)
     */
    public function assessConfidence(string $response, string $question): float
    {
        $score = 1.0;
        $reasons = [];

        // Factor 1: Response length (too short = uncertain)
        $responseLength = mb_strlen($response);
        $questionLength = mb_strlen($question);

        if ($responseLength < 20) {
            $score -= 0.3;
            $reasons[] = 'very_short_response';
        } elseif ($responseLength < $questionLength * 0.5) {
            $score -= 0.15;
            $reasons[] = 'short_response';
        }

        // Factor 2: Uncertainty phrases
        $responseLower = mb_strtolower($response);
        foreach ($this->uncertaintyPhrases as $phrase) {
            if (mb_strpos($responseLower, $phrase) !== false) {
                $score -= 0.25;
                $reasons[] = 'uncertainty_phrase';
                break; // Only count once
            }
        }

        // Factor 3: Question marks in response (asking back instead of answering)
        $questionMarks = substr_count($response, '?');
        if ($questionMarks > 1) {
            $score -= 0.15;
            $reasons[] = 'multiple_questions';
        }

        // Factor 4: Hedging language
        foreach ($this->hedgingPatterns as $pattern) {
            if (preg_match($pattern, $response)) {
                $score -= 0.1;
                $reasons[] = 'hedging_language';
                break;
            }
        }

        // Factor 5: Empty or very generic responses
        $genericResponses = [
            'i understand',
            'okay',
            'sure',
            'ได้ครับ',
            'ได้ค่ะ',
            'เข้าใจครับ',
            'เข้าใจค่ะ',
        ];
        foreach ($genericResponses as $generic) {
            if (mb_strtolower(trim($response)) === $generic) {
                $score -= 0.2;
                $reasons[] = 'generic_response';
                break;
            }
        }

        Log::debug('ConfidenceCascade: Assessed confidence', [
            'score' => max(0, min(1, $score)),
            'reasons' => $reasons,
            'response_length' => $responseLength,
            'question_length' => $questionLength,
        ]);

        return max(0, min(1, $score));
    }

    /**
     * Check if cascade is enabled for a bot.
     */
    public function isEnabled(Bot $bot): bool
    {
        return $bot->use_confidence_cascade ?? false;
    }

    /**
     * Get cascade configuration for a bot.
     */
    public function getConfig(Bot $bot): array
    {
        return [
            'enabled' => $bot->use_confidence_cascade ?? false,
            'threshold' => $bot->cascade_confidence_threshold ?? config('rag.confidence_cascade.threshold', 0.7),
            'cheap_model' => $bot->cascade_cheap_model ?? config('rag.confidence_cascade.cheap_model'),
            'expensive_model' => $bot->cascade_expensive_model ?? $bot->primary_chat_model,
        ];
    }
}
