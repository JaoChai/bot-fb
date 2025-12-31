<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

/**
 * RAG (Retrieval Augmented Generation) Service
 *
 * Integrates Knowledge Base search into bot responses using hybrid search
 * (semantic + keyword) with Reciprocal Rank Fusion for optimal retrieval.
 *
 * When a user sends a message, the service:
 * 1. Analyzes intent using Decision Model
 * 2. Searches the bot's KB using hybrid search (vector + full-text)
 * 3. Builds context from matching chunks
 * 4. Enhances the system prompt with KB context
 * 5. Generates an informed response via the LLM
 */
class RAGService
{
    public function __construct(
        protected SemanticSearchService $semanticSearchService,
        protected HybridSearchService $hybridSearchService,
        protected OpenRouterService $openRouter,
        protected ?QueryEnhancementService $queryEnhancement = null
    ) {}

    /**
     * Generate a response using multi-model architecture.
     *
     * Flow:
     * 1. Analyze intent using Decision Model
     * 2. Detect question complexity for Chain-of-Thought
     * 3. Get KB context if intent is 'knowledge' and KB enabled
     * 4. Generate response using Chat Model (with CoT if complex)
     *
     * @param Bot $bot The bot to respond as
     * @param string $userMessage The user's message
     * @param array $conversationHistory Previous messages for context
     * @return array Response with content, usage stats, intent, and RAG metadata
     */
    public function generateResponse(
        Bot $bot,
        string $userMessage,
        array $conversationHistory = [],
        ?Flow $flow = null,
        ?string $apiKeyOverride = null
    ): array {
        // Get API key first (used for both decision and chat models)
        $apiKey = $apiKeyOverride ?? $this->getApiKeyForBot($bot);

        // Step 1: Analyze intent using Decision Model
        $intent = $this->analyzeIntent($bot, $userMessage, $apiKey);

        // Step 2: Detect complexity for Chain-of-Thought
        $complexity = $this->detectComplexity($userMessage);

        // Step 3: Initialize KB metadata
        $kbContext = '';
        $kbMetadata = [
            'enabled' => false,
            'results_count' => 0,
            'chunks_used' => [],
        ];

        // Step 4: Get KB context if intent is 'knowledge' and KB enabled
        // Also get KB context if intent was skipped and KB is enabled (backward compatibility)
        $shouldUseKB = ($intent['intent'] === 'knowledge' || isset($intent['skipped']))
            && $this->shouldUseKnowledgeBase($bot);

        if ($shouldUseKB) {
            $kbContext = $this->getKnowledgeBaseContext(
                $bot,
                $userMessage,
                $kbMetadata
            );
        }

        // Step 5: Build enhanced system prompt with KB context and multiple bubbles
        // Priority: Bot system_prompt > Flow system_prompt > Default
        $systemPrompt = $this->buildEnhancedPrompt(
            $this->getSystemPromptForBot($bot),
            $kbContext,
            $bot
        );

        // Step 6: Add Chain-of-Thought instruction if question is complex
        if ($complexity['is_complex'] && config('rag.chain_of_thought.enabled', true)) {
            $language = $this->detectLanguage($userMessage);
            $systemPrompt .= $this->buildChainOfThoughtInstruction($language);

            Log::debug('Chain-of-Thought activated', [
                'bot_id' => $bot->id,
                'complexity_score' => $complexity['score'],
                'reasons' => $complexity['reasons'],
                'language' => $language,
            ]);
        }

        // Step 7: Get chat models
        $chatModel = $this->getChatModelForBot($bot);
        $fallbackChatModel = $this->getFallbackChatModelForBot($bot);

        // Step 8: Calculate max tokens (increase for complex questions)
        $maxTokens = $bot->llm_max_tokens;
        if ($complexity['is_complex']) {
            $multiplier = config('rag.chain_of_thought.max_tokens_multiplier', 1.5);
            $maxTokens = (int) min($maxTokens * $multiplier, 4096);
        }

        // Step 9: Generate response using Chat Model
        $result = $this->openRouter->generateBotResponse(
            userMessage: $userMessage,
            systemPrompt: $systemPrompt,
            conversationHistory: $conversationHistory,
            model: $chatModel,
            fallbackModel: $fallbackChatModel,
            temperature: $bot->llm_temperature,
            maxTokens: $maxTokens,
            apiKeyOverride: $apiKey
        );

        // Add metadata to result
        $result['intent'] = $intent;
        $result['rag'] = $kbMetadata;
        $result['complexity'] = $complexity;
        $result['models_used'] = [
            'decision' => $intent['model_used'] ?? null,
            'chat' => $result['model'] ?? $chatModel,
        ];

        return $result;
    }

    /**
     * Check if the bot should use its Knowledge Base.
     */
    protected function shouldUseKnowledgeBase(Bot $bot): bool
    {
        // Must have KB enabled
        if (!$bot->kb_enabled) {
            return false;
        }

        // Must have a knowledge base associated
        $kb = $bot->knowledgeBase;
        if (!$kb) {
            Log::debug('Bot has KB enabled but no knowledge base', [
                'bot_id' => $bot->id,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Get context from Knowledge Base for the given query.
     *
     * Uses hybrid search (semantic + keyword) with RRF for better retrieval.
     * Optionally enhances queries with LLM-based expansion for better recall.
     */
    protected function getKnowledgeBaseContext(
        Bot $bot,
        string $query,
        array &$metadata
    ): string {
        $kb = $bot->knowledgeBase;

        try {
            // Step 1: Enhance query if enabled
            $queryVariations = [$query];
            $enhancementMetadata = null;

            if ($this->queryEnhancement?->isEnabled()) {
                $enhanced = $this->queryEnhancement->enhance($query, [
                    'bot_name' => $bot->name,
                    'kb_topics' => $kb->description ?? '',
                ]);

                $queryVariations = $enhanced['variations'];
                $enhancementMetadata = [
                    'was_enhanced' => $enhanced['was_enhanced'],
                    'variations_count' => count($enhanced['variations']),
                    'reasoning' => $enhanced['reasoning'],
                ];
            }

            // Step 2: Search with all query variations
            $allResults = collect([]);
            $limit = $bot->kb_max_results ?? config('rag.max_results', 3);
            $threshold = $bot->kb_relevance_threshold ?? config('rag.default_threshold', 0.7);

            // Get API key: User Settings > ENV
            $apiKey = $this->getApiKeyForBot($bot);

            foreach ($queryVariations as $variation) {
                $results = $this->hybridSearchService->search(
                    knowledgeBaseId: $kb->id,
                    query: $variation,
                    limit: $limit,
                    threshold: $threshold,
                    apiKey: $apiKey
                );

                // Add results, will deduplicate later
                $allResults = $allResults->concat($results);
            }

            // Step 3: Deduplicate and rank by best score
            $dedupedResults = $allResults
                ->groupBy('id')
                ->map(function ($group) {
                    // Keep the result with the highest similarity/relevance score
                    return $group->sortByDesc(fn($r) =>
                        $r['relevance_score'] ?? $r['similarity'] ?? 0
                    )->first();
                })
                ->values()
                ->sortByDesc(fn($r) => $r['relevance_score'] ?? $r['similarity'] ?? 0)
                ->take($limit);

            if ($dedupedResults->isEmpty()) {
                Log::debug('No relevant KB results found', [
                    'bot_id' => $bot->id,
                    'kb_id' => $kb->id,
                    'query' => substr($query, 0, 100),
                    'query_variations' => count($queryVariations),
                    'search_mode' => $this->hybridSearchService->isEnabled() ? 'hybrid' : 'semantic',
                ]);
                return '';
            }

            // Update metadata with search info
            $metadata['enabled'] = true;
            $metadata['results_count'] = $dedupedResults->count();
            $metadata['search_mode'] = $this->hybridSearchService->isEnabled() ? 'hybrid' : 'semantic';
            $metadata['query_enhancement'] = $enhancementMetadata;
            $metadata['chunks_used'] = $dedupedResults->map(fn ($r) => [
                'document' => $r['document_name'],
                'similarity' => $r['similarity'],
                'relevance_score' => $r['relevance_score'] ?? null,
                'rrf_score' => $r['rrf_score'] ?? null,
                'reranked' => $r['reranked'] ?? false,
            ])->toArray();

            // Format context for prompt
            return $this->formatKnowledgeBaseContext($dedupedResults);
        } catch (\Exception $e) {
            Log::error('KB search failed', [
                'bot_id' => $bot->id,
                'kb_id' => $kb->id,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Format KB search results into context for the prompt.
     */
    protected function formatKnowledgeBaseContext($results): string
    {
        if ($results->isEmpty()) {
            return '';
        }

        $template = config('rag.context_template', 'thai');

        if ($template === 'thai') {
            return $this->formatThaiContext($results);
        }

        return $this->formatEnglishContext($results);
    }

    /**
     * Format context in Thai.
     */
    protected function formatThaiContext($results): string
    {
        $context = "## ข้อมูลอ้างอิงจาก Knowledge Base:\n\n";

        foreach ($results as $i => $result) {
            $relevance = round($result['similarity'] * 100);
            $context .= "### แหล่งที่ " . ($i + 1) . " (ความเกี่ยวข้อง {$relevance}%)\n";
            $context .= "📄 {$result['document_name']}\n\n";
            $context .= $result['content'] . "\n\n";
        }

        $context .= "---\n";
        $context .= "📌 **คำแนะนำ**: ใช้ข้อมูลด้านบนในการตอบคำถาม ";
        $context .= "หากข้อมูลไม่เกี่ยวข้องหรือไม่เพียงพอ ให้ตอบตามความรู้ทั่วไปและแจ้งผู้ใช้ว่าไม่พบข้อมูลในระบบ\n";

        return $context;
    }

    /**
     * Format context in English.
     */
    protected function formatEnglishContext($results): string
    {
        $context = "## Reference Information from Knowledge Base:\n\n";

        foreach ($results as $i => $result) {
            $relevance = round($result['similarity'] * 100);
            $context .= "### Source " . ($i + 1) . " (Relevance: {$relevance}%)\n";
            $context .= "Document: {$result['document_name']}\n\n";
            $context .= $result['content'] . "\n\n";
        }

        $context .= "---\n";
        $context .= "**Instructions**: Use the information above to answer the user's question. ";
        $context .= "If the information is not relevant or insufficient, respond using general knowledge and inform the user.\n";

        return $context;
    }

    /**
     * Build enhanced system prompt with KB context and multiple bubbles instruction.
     */
    protected function buildEnhancedPrompt(string $basePrompt, string $kbContext, ?Bot $bot = null): string
    {
        $prompt = $basePrompt;

        // Append KB context if available
        if (!empty($kbContext)) {
            $prompt .= "\n\n" . $kbContext;
        }

        // Append multiple bubbles instruction if enabled
        if ($bot) {
            $bubblesService = app(MultipleBubblesService::class);
            $instruction = $bubblesService->buildPromptInstruction($bot);
            if (!empty($instruction)) {
                $prompt .= "\n" . $instruction;
            }
        }

        return $prompt;
    }

    /**
     * Get the primary chat model to use for a bot.
     *
     * Priority:
     * 1. Bot-specific primary_chat_model (new multi-model)
     * 2. User Settings model (centralized)
     * 3. Bot-specific llm_model (legacy)
     * 4. Config default model
     */
    protected function getChatModelForBot(Bot $bot): ?string
    {
        // Priority 1: Bot-specific primary chat model (new)
        if ($bot->primary_chat_model) {
            return $bot->primary_chat_model;
        }

        // Priority 2: User Settings (centralized model)
        $user = $bot->user;
        if ($user && $user->settings && $user->settings->openrouter_model) {
            return $user->settings->openrouter_model;
        }

        // Priority 3: Bot-specific model (legacy support)
        if ($bot->llm_model) {
            return $bot->llm_model;
        }

        // Priority 4: Config default (handled by OpenRouterService)
        return null;
    }

    /**
     * Get the fallback chat model for a bot.
     *
     * Priority:
     * 1. Bot-specific fallback_chat_model (new)
     * 2. Bot-specific llm_fallback_model (legacy)
     * 3. Config fallback (handled by OpenRouterService)
     */
    protected function getFallbackChatModelForBot(Bot $bot): ?string
    {
        // Priority 1: Bot-specific fallback chat model (new)
        if ($bot->fallback_chat_model) {
            return $bot->fallback_chat_model;
        }

        // Priority 2: Bot legacy fallback
        if ($bot->llm_fallback_model) {
            return $bot->llm_fallback_model;
        }

        // Priority 3: Config fallback (handled by OpenRouterService)
        return null;
    }

    /**
     * Get the decision model for a bot (for intent analysis).
     *
     * Priority:
     * 1. Bot-specific decision_model
     * 2. Falls back to chat model if not set
     */
    protected function getDecisionModelForBot(Bot $bot): ?string
    {
        // Priority 1: Bot-specific decision model
        if ($bot->decision_model) {
            return $bot->decision_model;
        }

        // Priority 2: Fall back to chat model
        return $this->getChatModelForBot($bot);
    }

    /**
     * Get the fallback decision model for a bot.
     *
     * Priority:
     * 1. Bot-specific fallback_decision_model
     * 2. Falls back to fallback chat model
     */
    protected function getFallbackDecisionModelForBot(Bot $bot): ?string
    {
        // Priority 1: Bot-specific fallback decision model
        if ($bot->fallback_decision_model) {
            return $bot->fallback_decision_model;
        }

        // Priority 2: Fall back to fallback chat model
        return $this->getFallbackChatModelForBot($bot);
    }

    /**
     * @deprecated Use getChatModelForBot() instead
     */
    protected function getModelForBot(Bot $bot): ?string
    {
        return $this->getChatModelForBot($bot);
    }

    /**
     * Get the API key to use for a bot.
     *
     * Priority:
     * 1. User's API key from Settings page
     * 2. Config/env fallback
     */
    protected function getApiKeyForBot(Bot $bot): ?string
    {
        return $bot->user?->settings?->getOpenRouterApiKey()
            ?? config('services.openrouter.api_key');
    }

    /**
     * Analyze user message intent using Decision Model.
     *
     * Flow:
     * 1. If Decision Model configured, use it
     * 2. If no Decision Model configured, use default behavior
     *
     * @param Bot $bot The bot
     * @param string $userMessage The user's message
     * @param string|null $apiKey API key override
     * @return array Intent analysis result with 'intent' and 'confidence'
     */
    protected function analyzeIntent(Bot $bot, string $userMessage, ?string $apiKey): array
    {
        // Get Decision Model configuration
        $decisionModel = $this->getDecisionModelForBot($bot);
        $fallbackDecision = $this->getFallbackDecisionModelForBot($bot);

        // Skip decision model if not configured (use default behavior)
        if (!$decisionModel && !$bot->decision_model) {
            // Default: use knowledge if KB enabled, otherwise chat
            return [
                'intent' => $this->shouldUseKnowledgeBase($bot) ? 'knowledge' : 'chat',
                'confidence' => 1.0,
                'model_used' => null,
                'method' => 'default',
                'skipped' => true,
            ];
        }

        $prompt = $this->buildIntentAnalysisPrompt($bot);

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
                fallbackModelOverride: $fallbackDecision
            );

            $parsed = $this->parseIntentResponse($result['content']);
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
            Log::warning('Intent analysis failed, defaulting to chat', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);

            // Default to knowledge if KB enabled, otherwise chat
            return [
                'intent' => $this->shouldUseKnowledgeBase($bot) ? 'knowledge' : 'chat',
                'confidence' => 0,
                'method' => 'error_fallback',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build the system prompt for intent analysis.
     */
    protected function buildIntentAnalysisPrompt(Bot $bot): string
    {
        $hasKB = $bot->kb_enabled && $bot->knowledgeBase;
        $kbNote = $hasKB ? ' (Knowledge Base available for factual queries)' : '';

        return <<<PROMPT
You are an intent classifier. Analyze the user's message and determine the appropriate intent.
Respond with JSON only: {"intent": "chat|knowledge", "confidence": 0.0-1.0}

Available intents:
- "chat": General conversation, greetings, opinions, casual talk, or when unsure
- "knowledge": Questions requiring factual information, specific data, or documentation{$kbNote}

Classification rules:
- Use "knowledge" for: questions about facts, how-to queries, data lookups, technical questions
- Use "chat" for: greetings (hi, hello), opinions, casual conversation, follow-up responses
- When uncertain, prefer "chat" (safer default)
- Confidence should reflect how certain you are (0.0 = uncertain, 1.0 = very certain)

Examples:
User: "สวัสดี" → {"intent": "chat", "confidence": 0.95}
User: "ราคาสินค้า A เท่าไหร่" → {"intent": "knowledge", "confidence": 0.9}
User: "ขอบคุณครับ" → {"intent": "chat", "confidence": 0.95}
User: "วิธีใช้งานระบบ" → {"intent": "knowledge", "confidence": 0.85}

Respond with JSON only, no explanation.
PROMPT;
    }

    /**
     * Parse the intent analysis response from the LLM.
     */
    protected function parseIntentResponse(string $content): array
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
            if (!in_array($intent, ['chat', 'knowledge', 'flow'])) {
                $intent = 'chat';
            }

            // Clamp confidence to 0-1
            $confidence = max(0, min(1, $confidence));

            return [
                'intent' => $intent,
                'confidence' => $confidence,
            ];
        } catch (\Exception $e) {
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
     * Get system prompt for a bot with fallback chain:
     * 1. Bot's own system_prompt (if set)
     * 2. Default Flow's system_prompt (if bot has default_flow_id)
     * 3. Default system prompt
     */
    protected function getSystemPromptForBot(Bot $bot): string
    {
        // 1. Use bot's own system_prompt if set
        if (!empty($bot->system_prompt)) {
            return $bot->system_prompt;
        }

        // 2. Use default flow's system_prompt if available
        if ($bot->default_flow_id) {
            $flow = Flow::find($bot->default_flow_id);
            if ($flow && !empty($flow->system_prompt)) {
                Log::debug('Using system_prompt from Flow', [
                    'bot_id' => $bot->id,
                    'flow_id' => $flow->id,
                    'flow_name' => $flow->name,
                ]);
                return $flow->system_prompt;
            }
        }

        // 3. Fallback to default
        return $this->getDefaultSystemPrompt($bot);
    }

    protected function getDefaultSystemPrompt(Bot $bot): string
    {
        return <<<PROMPT
You are a helpful AI assistant for {$bot->name}.
Be friendly, professional, and helpful.
Respond in the same language as the user's message.
If you don't know something, be honest about it.
Keep responses concise but informative.
PROMPT;
    }

    /**
     * Get context from a Flow's Knowledge Bases (Many-to-Many).
     * Searches all attached KBs using hybrid search and merges results.
     */
    public function getFlowKnowledgeBaseContext(
        Flow $flow,
        string $query,
        array &$metadata
    ): string {
        $knowledgeBases = $flow->knowledgeBases;

        if ($knowledgeBases->isEmpty()) {
            return '';
        }

        try {
            // Build KB configs from pivot data
            $kbConfigs = $knowledgeBases->map(fn ($kb) => [
                'id' => $kb->id,
                'name' => $kb->name,
                'kb_top_k' => $kb->pivot->kb_top_k ?? 5,
                'kb_similarity_threshold' => $kb->pivot->kb_similarity_threshold ?? 0.7,
            ])->toArray();

            // Get API key: User Settings > ENV
            $apiKey = $flow->bot ? $this->getApiKeyForBot($flow->bot) : config('services.openrouter.api_key');

            // Search all KBs using hybrid search and merge results
            $results = $this->hybridSearchService->searchMultiple(
                kbConfigs: $kbConfigs,
                query: $query,
                totalLimit: config('rag.max_results', 5),
                apiKey: $apiKey
            );

            if ($results->isEmpty()) {
                Log::debug('No relevant results from Flow KBs', [
                    'flow_id' => $flow->id,
                    'kb_count' => count($kbConfigs),
                    'query' => substr($query, 0, 100),
                    'search_mode' => $this->hybridSearchService->isEnabled() ? 'hybrid' : 'semantic',
                ]);
                return '';
            }

            // Update metadata with hybrid search info
            $metadata['enabled'] = true;
            $metadata['results_count'] = $results->count();
            $metadata['kb_count'] = $knowledgeBases->count();
            $metadata['search_mode'] = $this->hybridSearchService->isEnabled() ? 'hybrid' : 'semantic';
            $metadata['chunks_used'] = $results->map(fn ($r) => [
                'document' => $r['document_name'],
                'knowledge_base_id' => $r['knowledge_base_id'],
                'similarity' => $r['similarity'],
                'rrf_score' => $r['rrf_score'] ?? null,
            ])->toArray();

            // Format context for prompt
            return $this->formatKnowledgeBaseContext($results);
        } catch (\Exception $e) {
            Log::error('Flow KB search failed', [
                'flow_id' => $flow->id,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Check if a Flow has Knowledge Bases attached.
     */
    public function flowHasKnowledgeBases(Flow $flow): bool
    {
        return $flow->knowledgeBases()->exists();
    }

    /**
     * Test RAG for a bot with a sample query.
     */
    public function testRAG(Bot $bot, string $testQuery): array
    {
        $metadata = [
            'enabled' => false,
            'results_count' => 0,
            'chunks_used' => [],
            'search_mode' => 'none',
            'query_enhancement' => null,
        ];

        $context = '';
        if ($this->shouldUseKnowledgeBase($bot)) {
            $context = $this->getKnowledgeBaseContext($bot, $testQuery, $metadata);
        }

        return [
            'bot_id' => $bot->id,
            'kb_enabled' => $bot->kb_enabled,
            'has_knowledge_base' => $bot->knowledgeBase !== null,
            'test_query' => $testQuery,
            'context_generated' => !empty($context),
            'context_preview' => substr($context, 0, 500) . (strlen($context) > 500 ? '...' : ''),
            'metadata' => $metadata,
            'hybrid_search_enabled' => $this->hybridSearchService->isEnabled(),
            'query_enhancement_enabled' => $this->queryEnhancement?->isEnabled() ?? false,
            'reranking_enabled' => $this->hybridSearchService->isRerankingEnabled(),
        ];
    }

    // =========================================================================
    // Chain-of-Thought (CoT) Methods
    // =========================================================================

    /**
     * Detect if a user message requires complex reasoning (Chain-of-Thought).
     *
     * Uses heuristics-based detection to avoid additional LLM calls.
     * Returns complexity score and reasons for activation.
     *
     * @param string $userMessage The user's message
     * @return array{is_complex: bool, score: int, reasons: array}
     */
    protected function detectComplexity(string $userMessage): array
    {
        $score = 0;
        $reasons = [];
        $threshold = config('rag.chain_of_thought.complexity_threshold', 2);

        // 1. Message length > 100 characters (indicates detailed question)
        if (mb_strlen($userMessage) > 100) {
            $score += 1;
            $reasons[] = 'long_message';
        }

        // 2. Multiple questions (multiple question marks)
        $questionMarkCount = substr_count($userMessage, '?');
        if ($questionMarkCount > 1) {
            $score += 2;
            $reasons[] = 'multiple_questions';
        }

        // 3. Reasoning keywords that require step-by-step thinking
        $reasoningKeywords = [
            // English
            'compare', 'comparison', 'versus', 'vs',
            'analyze', 'analysis', 'evaluate', 'assessment',
            'why', 'how come', 'reason',
            'explain', 'elaborate', 'describe in detail',
            'pros and cons', 'advantages and disadvantages',
            'step by step', 'steps to', 'process',
            'calculate', 'compute', 'solve',
            'if', 'assuming', 'suppose', 'what if',
            'difference between', 'similarities',
            'best', 'recommend', 'suggest', 'which one',
            // Thai
            'เปรียบเทียบ', 'เทียบกับ',
            'วิเคราะห์', 'ประเมิน',
            'ทำไม', 'เพราะอะไร', 'สาเหตุ',
            'อธิบาย', 'ขยายความ',
            'ข้อดีข้อเสีย', 'ข้อดี', 'ข้อเสีย',
            'ทีละขั้นตอน', 'ขั้นตอน', 'วิธีการ',
            'คำนวณ', 'หาค่า',
            'ถ้า', 'สมมติ', 'หาก',
            'ความแตกต่าง', 'ต่างกันยังไง',
            'ดีที่สุด', 'แนะนำ', 'เลือกอันไหน',
        ];

        $lowerMessage = mb_strtolower($userMessage);
        foreach ($reasoningKeywords as $keyword) {
            if (mb_stripos($lowerMessage, $keyword) !== false) {
                $score += 2;
                $reasons[] = "keyword:{$keyword}";
                break; // Only count once for keywords
            }
        }

        // 4. Contains numbers with operations (likely calculation)
        if (preg_match('/\d+\s*[\+\-\*\/\%]\s*\d+/', $userMessage)) {
            $score += 1;
            $reasons[] = 'contains_calculation';
        }

        // 5. Contains list indicators (enumeration questions)
        if (preg_match('/\d+[\.\)]\s|\b(first|second|third|firstly|secondly|อันดับ|ประการ)\b/i', $userMessage)) {
            $score += 1;
            $reasons[] = 'enumeration';
        }

        return [
            'is_complex' => $score >= $threshold,
            'score' => $score,
            'reasons' => $reasons,
        ];
    }

    /**
     * Build Chain-of-Thought instruction to append to system prompt.
     *
     * Instructs the LLM to think step-by-step for complex questions.
     *
     * @param string $language 'thai' or 'english'
     * @return string The CoT instruction to append
     */
    protected function buildChainOfThoughtInstruction(string $language = 'thai'): string
    {
        if ($language === 'thai') {
            return <<<PROMPT


## คำแนะนำสำหรับคำถามซับซ้อน
คำถามนี้ต้องการการวิเคราะห์อย่างละเอียด กรุณา:
1. **แยกประเด็น**: ระบุประเด็นสำคัญที่ต้องพิจารณา
2. **วิเคราะห์ทีละขั้น**: อธิบายเหตุผลหรือขั้นตอนอย่างชัดเจน
3. **สรุปคำตอบ**: ให้คำตอบที่ชัดเจนและครบถ้วน

PROMPT;
        }

        return <<<PROMPT


## Instructions for Complex Questions
This question requires detailed analysis. Please:
1. **Identify Key Points**: Break down the important aspects to consider
2. **Analyze Step by Step**: Explain your reasoning or process clearly
3. **Provide Conclusion**: Give a clear and comprehensive answer

PROMPT;
    }

    /**
     * Detect the primary language of a message.
     *
     * Simple detection based on Thai character presence.
     *
     * @param string $message The message to analyze
     * @return string 'thai' or 'english'
     */
    protected function detectLanguage(string $message): string
    {
        // Count Thai characters (Unicode range: \x{0E00}-\x{0E7F})
        $thaiCharCount = preg_match_all('/[\x{0E00}-\x{0E7F}]/u', $message);

        // If more than 20% Thai characters, consider it Thai
        $totalChars = mb_strlen($message);
        if ($totalChars > 0 && ($thaiCharCount / $totalChars) > 0.2) {
            return 'thai';
        }

        return 'english';
    }
}
