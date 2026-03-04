<?php

namespace App\Services;

use App\Models\Bot;
use Illuminate\Support\Facades\Log;

/**
 * IntentAnalysisService - Unified intent analysis for RAG and Stream
 *
 * Analyzes user messages to determine intent (chat vs knowledge).
 * Consolidates logic from RAGService::analyzeIntent() and StreamController::runDecisionModel().
 *
 * Enhanced Decision (reasoning models):
 * When the Decision Model is a reasoning model (e.g., gpt-5-mini), it extracts
 * additional data: entities, search_query, and complexity assessment.
 * This enables Smart Chat Routing and improved KB search accuracy.
 */
class IntentAnalysisService
{
    public function __construct(
        protected OpenRouterService $openRouter
    ) {}

    /**
     * Analyze user message intent using Decision Model.
     *
     * @param  Bot  $bot  The bot
     * @param  string  $userMessage  The user's message
     * @param  array  $options  Configuration options:
     *                          - validIntents: array of valid intent types (default: ['chat', 'knowledge'])
     *                          - includeExamples: whether to include examples in prompt (default: false)
     *                          - useFallback: whether to use text fallback on JSON parse failure (default: true)
     *                          - apiKey: API key override (default: null, uses bot's key)
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
        if (! $decisionModel && ! $bot->decision_model) {
            return [
                'intent' => $this->shouldUseKnowledgeBase($bot) ? 'knowledge' : 'chat',
                'confidence' => 1.0,
                'model_used' => null,
                'method' => 'default',
                'skipped' => true,
            ];
        }

        // Determine if we should use enhanced prompt (reasoning models extract more data)
        $useEnhanced = $this->isReasoningModel($decisionModel);
        $prompt = $useEnhanced
            ? $this->buildEnhancedIntentPrompt($bot, $validIntents)
            : $this->buildIntentAnalysisPrompt($bot, $validIntents, $includeExamples);

        // Build chat parameters based on model capabilities
        $chatParams = [
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'model' => $decisionModel,
            'temperature' => 0.1, // Low temp for consistent decisions
            'maxTokens' => $useEnhanced ? 300 : 150,
            'useFallback' => true,
            'apiKeyOverride' => $apiKey,
            'fallbackModelOverride' => $fallbackDecisionModel,
        ];

        // Use structured output (JSON mode) for models that support it
        if ($this->openRouter->supportsStructuredOutput($decisionModel)) {
            $chatParams['responseFormat'] = ['type' => 'json_object'];
        }

        // Use low reasoning effort for non-mandatory reasoning models (save tokens)
        if ($this->isReasoningModel($decisionModel) && ! $this->isMandatoryReasoningModel($decisionModel)) {
            $chatParams['reasoning'] = ['effort' => 'low'];
        }

        try {
            $result = $this->openRouter->chat(...$chatParams);

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
     * @param  Bot  $bot  The bot
     * @param  array  $validIntents  Valid intent types
     * @param  bool  $includeExamples  Whether to include examples
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
        if (! $includeExamples) {
            $prompt .= "\n\nRespond with JSON only, no explanation.";
        } else {
            $prompt .= "\n- Confidence should reflect how certain you are (0.0 = uncertain, 1.0 = very certain)";

            // Add examples for RAG-style prompts
            $prompt .= <<<'EXAMPLES'


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
     * Build an enhanced system prompt for reasoning models.
     *
     * Reasoning models (gpt-5-mini, o1, etc.) can extract additional data
     * beyond simple intent classification, making their thinking tokens productive.
     *
     * Enhanced response includes:
     * - intent & confidence (same as basic)
     * - entities: key entities/topics mentioned
     * - search_query: optimized query for KB search
     * - complexity: "simple" or "complex" for Smart Chat Routing
     *
     * @param  Bot  $bot  The bot
     * @param  array  $validIntents  Valid intent types
     * @return string The enhanced system prompt
     */
    protected function buildEnhancedIntentPrompt(Bot $bot, array $validIntents): string
    {
        $hasKB = $bot->kb_enabled && ($bot->defaultFlow?->knowledgeBases()->exists() || $bot->knowledgeBase);
        $kbNote = $hasKB ? ' (Knowledge Base available for factual queries)' : '';

        $intentsStr = implode('|', $validIntents);

        return <<<PROMPT
You are an intent classifier and message analyzer. Analyze the user's message and respond with JSON only.

Required JSON format:
{
  "intent": "{$intentsStr}",
  "confidence": 0.0-1.0,
  "entities": ["entity1", "entity2"],
  "search_query": "optimized search query or null",
  "complexity": "simple|complex"
}

Field definitions:
- "intent": Message classification
  - "chat": Greetings, casual talk, opinions, acknowledgments, follow-ups
  - "knowledge": Questions about facts, products, pricing, how-to, documentation{$kbNote}
- "confidence": How certain you are (0.0 = uncertain, 1.0 = very certain)
- "entities": Key topics, product names, or concepts mentioned (empty array if none)
- "search_query": An optimized search query for knowledge base lookup. Extract core keywords, remove filler words. Set to null for chat intent.
- "complexity": Message complexity assessment
  - "simple": Greetings, yes/no questions, single-topic queries, acknowledgments
  - "complex": Multi-part questions, comparisons, calculations, reasoning required, detailed explanations

Rules:
- When uncertain about intent, prefer "chat"
- For Thai messages, extract entities in Thai
- search_query should be concise keywords, not the full message
- Respond with JSON only, no explanation
PROMPT;
    }

    /**
     * Parse the intent analysis response from the LLM.
     *
     * @param  string  $content  The raw response content
     * @param  array  $validIntents  Valid intent types
     * @param  bool  $useFallback  Whether to use text fallback on JSON parse failure
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
            if (! in_array($intent, $validIntents)) {
                $intent = 'chat';
            }

            // Clamp confidence to 0-1
            $confidence = max(0, min(1, $confidence));

            $result = [
                'intent' => $intent,
                'confidence' => $confidence,
            ];

            // Extract enhanced fields if present (from reasoning models)
            if (isset($data['entities']) && is_array($data['entities'])) {
                $result['entities'] = $data['entities'];
            }
            if (isset($data['search_query']) && is_string($data['search_query']) && $data['search_query'] !== '') {
                $result['search_query'] = $data['search_query'];
            }
            if (isset($data['complexity']) && in_array($data['complexity'], ['simple', 'complex'])) {
                $result['complexity'] = $data['complexity'];
            }

            return $result;
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
                $inString = ! $inString;

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
     * @param  string  $content  The raw content
     * @param  array  $validIntents  Valid intent types
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
        if (! $bot->kb_enabled) {
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
        // Priority 1: Bot's decision model (from Connection Settings UI)
        if ($bot->decision_model) {
            return $bot->decision_model;
        }

        // Priority 2: Fall back to primary chat model
        if ($bot->primary_chat_model) {
            return $bot->primary_chat_model;
        }

        // Priority 3: Fall back to fallback chat model
        return $bot->fallback_chat_model;
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
     * Check if a model supports reasoning (thinking capability).
     */
    protected function isReasoningModel(string $model): bool
    {
        return $this->openRouter->supportsReasoning($model);
    }

    /**
     * Check if a model has mandatory reasoning that cannot be disabled.
     */
    protected function isMandatoryReasoningModel(string $model): bool
    {
        return $this->openRouter->isMandatoryReasoning($model);
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
