<?php

namespace App\Services;

use App\Models\Bot;
use Illuminate\Support\Facades\Log;

/**
 * IntentAnalysisService - Unified intent analysis for RAG and Stream
 *
 * Analyzes user messages to determine intent (chat vs knowledge).
 * Consolidates logic from RAGService::analyzeIntent() and StreamController::runDecisionModel().
 */
class IntentAnalysisService
{
    public function __construct(
        protected OpenRouterService $openRouter
    ) {}

    /**
     * Analyze user message intent using Decision Model.
     *
     * @param Bot $bot The bot
     * @param string $userMessage The user's message
     * @param array $options Configuration options:
     *   - validIntents: array of valid intent types (default: ['chat', 'knowledge'])
     *   - includeExamples: whether to include examples in prompt (default: false)
     *   - useFallback: whether to use text fallback on JSON parse failure (default: true)
     *   - apiKey: API key override (default: null, uses bot's key)
     * @return array Intent analysis result with 'intent', 'confidence', 'model_used', 'method'
     */
    public function analyzeIntent(Bot $bot, string $userMessage, array $options = []): array
    {
        $validIntents = $options['validIntents'] ?? ['chat', 'knowledge'];
        $includeExamples = $options['includeExamples'] ?? false;
        $useFallback = $options['useFallback'] ?? true;
        $apiKey = $options['apiKey'] ?? $this->getApiKeyForBot($bot);

        // Get Decision Model configuration
        $decisionModel = $this->getDecisionModelForBot($bot);
        $fallbackDecisionModel = $this->getFallbackDecisionModelForBot($bot);

        // Skip decision model if not configured (use default behavior)
        if (!$decisionModel && !$bot->decision_model) {
            return [
                'intent' => $this->shouldUseKnowledgeBase($bot) ? 'knowledge' : 'chat',
                'confidence' => 1.0,
                'model_used' => null,
                'method' => 'default',
                'skipped' => true,
            ];
        }

        $prompt = $this->buildIntentAnalysisPrompt($bot, $validIntents, $includeExamples);

        try {
            $result = $this->openRouter->chat(
                messages: [
                    ['role' => 'system', 'content' => $prompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                model: $decisionModel,
                temperature: 0.1, // Low temp for consistent decisions
                maxTokens: 150,
                useFallback: true,
                apiKeyOverride: $apiKey,
                fallbackModelOverride: $fallbackDecisionModel
            );

            $parsed = $this->parseIntentResponse(
                $result['content'] ?? '',
                $validIntents,
                $useFallback
            );

            $parsed['model_used'] = $result['model'] ?? $decisionModel;
            $parsed['method'] = 'llm_decision';
            $parsed['usage'] = $result['usage'] ?? null;

            Log::debug('Intent analysis completed via LLM', [
                'bot_id' => $bot->id,
                'intent' => $parsed['intent'],
                'confidence' => $parsed['confidence'],
                'model' => $parsed['model_used'],
            ]);

            return $parsed;
        } catch (\Exception $e) {
            Log::warning('Intent analysis failed, defaulting to fallback', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);

            // Default to knowledge if KB enabled, otherwise chat
            return [
                'intent' => $this->shouldUseKnowledgeBase($bot) ? 'knowledge' : 'chat',
                'confidence' => 0,
                'model_used' => null,
                'method' => 'error_fallback',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build the system prompt for intent analysis.
     *
     * @param Bot $bot The bot
     * @param array $validIntents Valid intent types
     * @param bool $includeExamples Whether to include examples
     * @return string The system prompt
     */
    protected function buildIntentAnalysisPrompt(Bot $bot, array $validIntents, bool $includeExamples): string
    {
        $hasKB = $bot->kb_enabled && ($bot->defaultFlow?->knowledgeBases()->exists() || $bot->knowledgeBase);
        $kbNote = $hasKB ? ' (Knowledge Base available for factual queries)' : '';

        $intentsStr = implode('|', $validIntents);

        $prompt = <<<PROMPT
You are an intent classifier. Analyze the user's message and determine the appropriate intent.
Respond with JSON only: {"intent": "{$intentsStr}", "confidence": 0.0-1.0}

Available intents:
- "chat": General conversation, greetings, opinions, casual talk, or when unsure
- "knowledge": Questions requiring factual information, specific data, or documentation{$kbNote}

Classification rules:
- Use "knowledge" for: questions about facts, how-to queries, data lookups, technical questions
- Use "chat" for: greetings (hi, hello), opinions, casual conversation, follow-up responses
- When uncertain, prefer "chat" (safer default)
PROMPT;

        // Add confidence instruction
        if (!$includeExamples) {
            $prompt .= "\n\nRespond with JSON only, no explanation.";
        } else {
            $prompt .= "\n- Confidence should reflect how certain you are (0.0 = uncertain, 1.0 = very certain)";

            // Add examples for RAG-style prompts
            $prompt .= <<<EXAMPLES


Examples:
User: "สวัสดี" → {"intent": "chat", "confidence": 0.95}
User: "ราคาสินค้า A เท่าไหร่" → {"intent": "knowledge", "confidence": 0.9}
User: "ขอบคุณครับ" → {"intent": "chat", "confidence": 0.95}
User: "วิธีใช้งานระบบ" → {"intent": "knowledge", "confidence": 0.85}

Respond with JSON only, no explanation.
EXAMPLES;
        }

        return $prompt;
    }

    /**
     * Parse the intent analysis response from the LLM.
     *
     * @param string $content The raw response content
     * @param array $validIntents Valid intent types
     * @param bool $useFallback Whether to use text fallback on JSON parse failure
     * @return array Parsed intent with 'intent' and 'confidence'
     */
    protected function parseIntentResponse(string $content, array $validIntents, bool $useFallback): array
    {
        $content = trim($content);

        // Remove markdown code blocks if present (greedy to capture full JSON)
        if (preg_match('/```(?:json)?\s*(\{.+\})\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        // Extract JSON object with proper brace matching
        $content = $this->extractJsonObject($content);

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            $intent = $data['intent'] ?? 'chat';
            $confidence = (float) ($data['confidence'] ?? 0.5);

            // Validate intent value
            if (!in_array($intent, $validIntents)) {
                $intent = 'chat';
            }

            // Clamp confidence to 0-1
            $confidence = max(0, min(1, $confidence));

            return [
                'intent' => $intent,
                'confidence' => $confidence,
            ];
        } catch (\Exception $e) {
            // Use text fallback if enabled
            if ($useFallback) {
                $fallbackResult = $this->fallbackIntentFromText($content, $validIntents);

                Log::warning('Intent JSON parse failed, using text fallback', [
                    'content' => substr($content, 0, 200),
                    'error' => $e->getMessage(),
                    'fallback_result' => $fallbackResult,
                ]);

                return $fallbackResult;
            }

            Log::warning('Failed to parse intent response', [
                'content' => substr($content, 0, 200),
                'error' => $e->getMessage(),
            ]);

            return [
                'intent' => 'chat',
                'confidence' => 0,
            ];
        }
    }

    /**
     * Extract JSON object from string with proper brace matching.
     * Handles nested objects unlike simple regex.
     */
    protected function extractJsonObject(string $content): string
    {
        $start = strpos($content, '{');
        if ($start === false) {
            return $content;
        }

        $depth = 0;
        $length = strlen($content);
        $inString = false;
        $escape = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($char === '\\') {
                $escape = true;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return $content;
    }

    /**
     * Fallback intent detection from raw text when JSON parsing fails.
     * Uses keyword matching to determine intent with reasonable confidence.
     *
     * @param string $content The raw content
     * @param array $validIntents Valid intent types
     * @return array Intent with 'intent' and 'confidence'
     */
    protected function fallbackIntentFromText(string $content, array $validIntents): array
    {
        $content = strtolower($content);

        // Keywords that suggest "knowledge" intent
        $knowledgeKeywords = ['knowledge', 'information', 'factual', 'data', 'lookup', 'search', 'query', 'question'];
        // Keywords that suggest "chat" intent
        $chatKeywords = ['chat', 'greeting', 'casual', 'conversation', 'hello', 'hi', 'general'];

        $knowledgeScore = 0;
        $chatScore = 0;

        foreach ($knowledgeKeywords as $keyword) {
            if (str_contains($content, $keyword)) {
                $knowledgeScore++;
            }
        }

        foreach ($chatKeywords as $keyword) {
            if (str_contains($content, $keyword)) {
                $chatScore++;
            }
        }

        // Default to "chat" with 0.5 confidence when no clear signals
        if ($knowledgeScore === 0 && $chatScore === 0) {
            return ['intent' => 'chat', 'confidence' => 0.5];
        }

        // Determine intent based on keyword matches
        if ($knowledgeScore > $chatScore && in_array('knowledge', $validIntents)) {
            $confidence = min(0.7, 0.5 + ($knowledgeScore * 0.1));
            return ['intent' => 'knowledge', 'confidence' => $confidence];
        }

        $confidence = min(0.7, 0.5 + ($chatScore * 0.1));
        return ['intent' => 'chat', 'confidence' => $confidence];
    }

    /**
     * Check if the bot should use its Knowledge Base.
     */
    public function shouldUseKnowledgeBase(Bot $bot): bool
    {
        if (!$bot->kb_enabled) {
            return false;
        }

        // Check for default flow's KBs (primary pattern)
        $defaultFlow = $bot->defaultFlow;
        if ($defaultFlow && $defaultFlow->knowledgeBases()->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Get the decision model for a bot.
     */
    protected function getDecisionModelForBot(Bot $bot): ?string
    {
        // Priority 1: Bot-specific decision model
        if ($bot->decision_model) {
            return $bot->decision_model;
        }

        // Priority 2: Fall back to primary chat model
        if ($bot->primary_chat_model) {
            return $bot->primary_chat_model;
        }

        // Priority 3: User Settings (centralized model)
        $user = $bot->user;
        if ($user && $user->settings && $user->settings->openrouter_model) {
            return $user->settings->openrouter_model;
        }

        // Priority 4: Bot legacy model
        return $bot->llm_model;
    }

    /**
     * Get the fallback decision model for a bot.
     */
    protected function getFallbackDecisionModelForBot(Bot $bot): ?string
    {
        // Priority 1: Bot-specific fallback decision model
        if ($bot->fallback_decision_model) {
            return $bot->fallback_decision_model;
        }

        // Priority 2: Fall back to fallback chat model
        if ($bot->fallback_chat_model) {
            return $bot->fallback_chat_model;
        }

        // Priority 3: Bot legacy fallback
        return $bot->llm_fallback_model;
    }

    /**
     * Get the API key to use for a bot.
     */
    protected function getApiKeyForBot(Bot $bot): ?string
    {
        return $bot->user?->settings?->getOpenRouterApiKey()
            ?? config('services.openrouter.api_key');
    }
}
