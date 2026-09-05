<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Services\RAG\RAGIntentDetector;
use App\Services\RAG\RAGKnowledgeBase;
use App\Services\RAG\RAGPromptBuilder;
use Illuminate\Support\Facades\Log;

/**
 * RAG (Retrieval Augmented Generation) orchestrator.
 *
 * Owns generateResponse()/testRAG() and the model/effort/cache decisions;
 * delegates to App\Services\RAG\RAGIntentDetector (message classification),
 * RAGKnowledgeBase (KB retrieval, formatting, CRAG) and RAGPromptBuilder
 * (system-prompt assembly). Public wrappers below keep the pre-split call
 * surface for AIService, StreamingResponseOrchestrator, FlowController and
 * PromptEvalRunner.
 */
class RAGService
{
    private RAGIntentDetector $intentDetector;

    private RAGPromptBuilder $promptBuilder;

    private RAGKnowledgeBase $knowledgeBase;

    public function __construct(
        protected SemanticSearchService $semanticSearchService,
        protected HybridSearchService $hybridSearchService,
        protected OpenRouterService $openRouter,
        protected IntentAnalysisService $intentAnalysis,
        protected FlowCacheService $flowCacheService,
        protected ?QueryEnhancementService $queryEnhancement = null,
        protected ?SemanticCacheService $semanticCache = null,
        protected ?CRAGService $cragService = null,
        protected StockInjectionService $stockInjectionService = new StockInjectionService
    ) {
        // Collaborators snapshot these deps at construction — swapping a protected
        // property via reflection afterwards will not reach them.
        $this->intentDetector = new RAGIntentDetector;
        $this->promptBuilder = new RAGPromptBuilder($this->stockInjectionService);
        $this->knowledgeBase = new RAGKnowledgeBase($this->hybridSearchService, $this->flowCacheService, $this->cragService);
    }

    /**
     * Generate a response using multi-model architecture.
     *
     * Flow:
     * 1. Analyze intent using Decision Model
     * 2. Detect question complexity for Chain-of-Thought
     * 3. Get KB context if intent is 'knowledge' and KB enabled
     * 4. Generate response using Chat Model (with CoT if complex)
     *
     * @param  Bot  $bot  The bot to respond as
     * @param  string  $userMessage  The user's message
     * @param  array  $conversationHistory  Previous messages for context
     * @return array Response with content, usage stats, intent, and RAG metadata
     */
    public function generateResponse(
        Bot $bot,
        string $userMessage,
        array $conversationHistory = [],
        ?Conversation $conversation = null,
        ?Flow $flow = null,
        ?string $apiKeyOverride = null
    ): array {
        // Get API key first (used for both decision and chat models)
        $apiKey = $apiKeyOverride ?? $this->getApiKeyForBot($bot);

        $bot->loadMissing(['defaultFlow.knowledgeBases']);

        // ดึงประวัติการซื้อครั้งเดียวต่อการตอบ 1 ครั้ง แล้วส่งต่อทั้ง Step 0 และ Step 6
        // ⚠️ ห้ามเก็บไว้ใน property ของ service — RAGService เป็น singleton (AppServiceProvider:62)
        //    queue worker รันหลาย job ต่อ process ข้อมูลลูกค้าจะรั่วข้ามกัน
        $purchaseHistoryBlock = $this->buildPurchaseHistoryBlock($conversation);

        // Step 0: Check Semantic Cache first (fastest path)
        // Skip cache for context-dependent messages to prevent cross-conversation contamination
        $skipCache = $this->shouldSkipCache(
            $userMessage,
            $conversation,
            $conversationHistory,
            $purchaseHistoryBlock !== ''
        );

        if (! $skipCache && $this->semanticCache?->isEnabled()) {
            $cachedResponse = $this->semanticCache->get($bot, $userMessage, $apiKey);
            if ($cachedResponse) {
                Log::debug('RAGService: Cache hit, returning cached response', [
                    'bot_id' => $bot->id,
                    'cache_match_type' => $cachedResponse['cache_match_type'],
                    'cache_similarity' => $cachedResponse['cache_similarity'] ?? null,
                ]);

                return [
                    'content' => $cachedResponse['content'],
                    'from_cache' => true,
                    'cache_match_type' => $cachedResponse['cache_match_type'],
                    'cache_similarity' => $cachedResponse['cache_similarity'],
                    'intent' => $cachedResponse['metadata']['intent'] ?? ['intent' => 'cached', 'confidence' => 1.0],
                    'rag' => $cachedResponse['metadata']['rag'] ?? [],
                    'complexity' => $cachedResponse['metadata']['complexity'] ?? [],
                    'models_used' => $cachedResponse['metadata']['models_used'] ?? [],
                    'model' => $cachedResponse['metadata']['models_used']['chat'] ?? 'cached',
                    'usage' => [
                        'prompt_tokens' => 0,
                        'completion_tokens' => 0,
                        'total_tokens' => 0,
                    ],
                ];
            }
        }

        // Step 1: Analyze intent using Decision Model.
        // Skip the decision-model round-trip for trivial greetings/acknowledgements —
        // they never need the KB and always resolve to 'chat'. Saves one ~300-800ms LLM hop.
        $isSimpleMessage = $this->isSimpleMessage($userMessage);

        if ($isSimpleMessage) {
            $intent = [
                'intent' => 'chat',
                'confidence' => 1.0,
                'model_used' => null,
                'method' => 'simple_message_skip',
                'skipped' => true,
                'usage' => null,
            ];
        } else {
            $intent = $this->intentAnalysis->analyzeIntent($bot, $userMessage, [
                'validIntents' => ['chat', 'knowledge', 'flow'],
                'includeExamples' => true,
                'apiKey' => $apiKey,
            ]);
        }

        // Step 2: Detect complexity for Chain-of-Thought
        $complexity = $this->detectComplexity($userMessage);

        // Step 3: Initialize KB metadata
        $kbContext = '';
        $kbMetadata = [
            'enabled' => false,
            'results_count' => 0,
            'chunks_used' => [],
        ];

        // Step 4: Get KB context if intent is 'knowledge' and KB enabled.
        // The isset('skipped') branch covers analyzeIntent's default-skip (no decision
        // model configured) — NOT the simple_message_skip greeting path, which the
        // leading ! $isSimpleMessage guard excludes from the KB entirely.
        $shouldUseKB = ! $isSimpleMessage
            && ($intent['intent'] === 'knowledge' || isset($intent['skipped']))
            && $this->knowledgeBase->shouldUseKnowledgeBase($bot);

        if ($shouldUseKB) {
            // Use enhanced search_query from reasoning model if available (more accurate KB search)
            $searchQuery = $intent['search_query'] ?? $userMessage;

            $kbContext = $this->knowledgeBase->getKnowledgeBaseContext(
                $bot,
                $searchQuery,
                $kbMetadata
            );
        }

        // Step 5: Extract memory notes (type='memory' only) from conversation
        $memoryNotes = [];
        if ($conversation) {
            $memoryNotes = collect($conversation->memory_notes ?? [])
                ->where('type', 'memory')
                ->pluck('content')
                ->all();
        }

        // Step 6: Build enhanced system prompt with memory notes, KB context, and multiple bubbles
        // Priority: Bot system_prompt > Flow system_prompt > Default
        $systemPrompt = $this->buildEnhancedPrompt(
            $this->promptBuilder->getSystemPromptForBot($bot),
            $kbContext,
            $bot,
            $memoryNotes,
            $purchaseHistoryBlock
        );

        // Step 7: Add Chain-of-Thought instruction if question is complex
        if ($complexity['is_complex'] && config('rag.chain_of_thought.enabled', true)) {
            $language = $this->intentDetector->detectLanguage($userMessage);
            $systemPrompt .= $this->promptBuilder->buildChainOfThoughtInstruction($language);

            Log::debug('Chain-of-Thought activated', [
                'bot_id' => $bot->id,
                'complexity_score' => $complexity['score'],
                'reasons' => $complexity['reasons'],
                'language' => $language,
            ]);
        }

        // Step 8: Get chat models
        $chatModel = $this->getChatModelForBot($bot);
        $fallbackChatModel = $this->getFallbackChatModelForBot($bot);

        // Step 9: Resolve flow (used for agentic check + LLM params)
        $resolvedFlow = $flow ?? $this->flowCacheService->getDefaultFlow($bot->id);

        // Step 9b: Calculate max tokens — Flow takes priority, Bot as fallback
        $maxTokens = $resolvedFlow?->max_tokens ?? $bot->llm_max_tokens;
        if ($complexity['is_complex']) {
            $multiplier = config('rag.chain_of_thought.max_tokens_multiplier', 1.5);
            $maxTokens = (int) min($maxTokens * $multiplier, 4096);
        }

        // Step 9c: Adaptive temperature based on intent
        $baseTemp = $resolvedFlow?->temperature ?? $bot->llm_temperature;
        $tempConfig = config('rag.adaptive_temperature');
        if ($tempConfig['enabled'] ?? true) {
            $temperature = match ($intent['intent']) {
                'knowledge' => min($baseTemp, $tempConfig['knowledge_max'] ?? 0.3),
                'chat' => max($baseTemp, $tempConfig['chat_min'] ?? 0.6),
                default => $baseTemp,
            };
        } else {
            $temperature = $baseTemp;
        }

        // Step 9d: Reasoning effort (ต่อบอท, adaptive) → request timeout + token headroom
        $botEffort = $bot->reasoning_effort ?: 'medium';
        $effort = $this->resolveReasoningEffort($botEffort, $complexity['is_complex']);
        $effortTimeouts = config('services.openrouter.effort_timeouts', []);
        $requestTimeout = $effortTimeouts[$effort] ?? config('services.openrouter.timeout', 45);
        // token headroom เฉพาะ high + โมเดล reasoning จริง (กัน API 400 / override ค่าที่เจ้าของตั้งต่ำ)
        if ($effort === 'high' && $chatModel && $this->openRouter->supportsReasoning($chatModel)) {
            $maxTokens = max($maxTokens, config('services.openrouter.high_effort_max_tokens', 8000));
        }

        // Step 10: Generate response via standard LLM call
        $result = $this->openRouter->generateBotResponse(
            userMessage: $userMessage,
            systemPrompt: $systemPrompt,
            conversationHistory: $conversationHistory,
            model: $chatModel,
            fallbackModel: $fallbackChatModel,
            temperature: $temperature,
            maxTokens: $maxTokens,
            apiKeyOverride: $apiKey,
            reasoning: ['effort' => $effort],
            timeout: $requestTimeout,
        );

        // Add metadata to result
        $result['intent'] = $intent;
        $result['rag'] = $kbMetadata;
        $result['complexity'] = $complexity;
        $result['models_used'] = [
            'decision' => $intent['model_used'] ?? null,
            'chat' => $result['model'] ?? $chatModel,
        ];
        $result['from_cache'] = false;

        // Step 10: Save to Semantic Cache for future similar queries
        // Skip saving context-dependent responses to prevent cross-conversation contamination
        if (! $skipCache && $this->semanticCache?->isEnabled() && ! empty($result['content'])) {
            try {
                $this->semanticCache->put(
                    $bot,
                    $userMessage,
                    $result['content'],
                    [
                        'intent' => $intent,
                        'rag' => $kbMetadata,
                        'complexity' => $complexity,
                        'models_used' => $result['models_used'],
                    ],
                    $apiKey
                );
            } catch (\Exception $e) {
                // Cache save failure should not break the response
                Log::warning('RAGService: Failed to save to cache', [
                    'bot_id' => $bot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /** Whether a message is a trivial greeting/acknowledgement. @see RAGIntentDetector::isSimpleMessage() */
    public function isSimpleMessage(string $userMessage): bool
    {
        return $this->intentDetector->isSimpleMessage($userMessage);
    }

    /** @see RAGPromptBuilder::buildEnhancedPrompt() */
    protected function buildEnhancedPrompt(string $basePrompt, string $kbContext, ?Bot $bot = null, array $memoryNotes = [], string $purchaseHistoryBlock = ''): string
    {
        return $this->promptBuilder->buildEnhancedPrompt($basePrompt, $kbContext, $bot, $memoryNotes, $purchaseHistoryBlock);
    }

    /** @see RAGPromptBuilder::buildPurchaseHistoryBlock() */
    protected function buildPurchaseHistoryBlock(?Conversation $conversation): string
    {
        return $this->promptBuilder->buildPurchaseHistoryBlock($conversation);
    }

    /** @see RAGPromptBuilder::injectStockStatus() */
    public function injectStockStatus(string $prompt): string
    {
        return $this->promptBuilder->injectStockStatus($prompt);
    }

    /**
     * Get the primary chat model to use for a bot.
     *
     * Models come ONLY from Connection Settings UI:
     * 1. Bot's primary_chat_model
     * 2. Bot's fallback_chat_model
     * 3. null — OpenRouterService will reject the call (no config substitution)
     */
    protected function getChatModelForBot(Bot $bot): ?string
    {
        return $bot->resolvedChatModel();
    }

    /**
     * Get the fallback chat model for a bot.
     * Comes ONLY from the Connection Settings form — empty means no fallback.
     */
    protected function getFallbackChatModelForBot(Bot $bot): ?string
    {
        return $bot->fallback_chat_model;
    }

    /**
     * ค่าบอทเป็นเพดาน: complex ใช้เต็ม, ไม่ complex cap ที่ medium (ประหยัด latency/cost ข้อความง่าย)
     */
    protected function resolveReasoningEffort(string $botEffort, bool $isComplex): string
    {
        if ($isComplex) {
            return $botEffort;
        }
        $rank = ['low' => 0, 'medium' => 1, 'high' => 2];

        return ($rank[$botEffort] ?? 1) > 1 ? 'medium' : $botEffort;
    }

    /** @see RAGKnowledgeBase::getApiKeyForBot() */
    protected function getApiKeyForBot(Bot $bot): ?string
    {
        return $this->knowledgeBase->getApiKeyForBot($bot);
    }

    /** @see RAGKnowledgeBase::formatKnowledgeBaseContext() */
    public function formatKnowledgeBaseContext($results): string
    {
        return $this->knowledgeBase->formatKnowledgeBaseContext($results);
    }

    /** @see RAGKnowledgeBase::getFlowKnowledgeBaseContext() */
    public function getFlowKnowledgeBaseContext(Flow $flow, string $query, array &$metadata): string
    {
        return $this->knowledgeBase->getFlowKnowledgeBaseContext($flow, $query, $metadata);
    }

    /** @see RAGKnowledgeBase::flowHasKnowledgeBases() */
    public function flowHasKnowledgeBases(Flow $flow): bool
    {
        return $this->knowledgeBase->flowHasKnowledgeBases($flow);
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
        if ($this->knowledgeBase->shouldUseKnowledgeBase($bot)) {
            $context = $this->knowledgeBase->getKnowledgeBaseContext($bot, $testQuery, $metadata);
        }

        return [
            'bot_id' => $bot->id,
            'kb_enabled' => $bot->kb_enabled,
            'has_knowledge_base' => $bot->defaultFlow?->knowledgeBases()->exists() ?? false,
            'test_query' => $testQuery,
            'context_generated' => ! empty($context),
            'context_preview' => substr($context, 0, 500).(strlen($context) > 500 ? '...' : ''),
            'metadata' => $metadata,
            'hybrid_search_enabled' => $this->hybridSearchService->isEnabled(),
            'query_enhancement_enabled' => $this->queryEnhancement?->isEnabled() ?? false,
            'reranking_enabled' => $this->hybridSearchService->isRerankingEnabled(),
        ];
    }

    /**
     * Determine if semantic cache should be skipped for this request.
     *
     * Context-dependent messages (confirmations, short replies, ongoing conversations)
     * must NOT be cached because the same text means different things in different contexts.
     * e.g., "ยืนยัน" from Customer A confirms order X, but Customer B has order Y.
     */
    protected function shouldSkipCache(
        string $userMessage,
        ?Conversation $conversation,
        array $conversationHistory,
        bool $hasPurchaseHistory = false
    ): bool {
        // Checks ordered cheapest → most expensive for early return optimization

        // 0. ลูกค้ามีประวัติการซื้อ → คำตอบถูกปรุงเฉพาะตัว ห้ามเสิร์ฟให้คนอื่น
        //    (rag_cache แชร์ทั้งบอทผ่าน RagCache::forBot() ไม่ได้แยกตามลูกค้า)
        if ($hasPurchaseHistory) {
            return true;
        }

        // 1. Conversation history is non-empty (ongoing conversation) — ~0.1μs
        if (config('rag.semantic_cache.skip_if_has_history', true) && ! empty($conversationHistory)) {
            return true;
        }

        // 2. Short messages are almost always context-dependent — ~0.5μs
        $trimmed = trim($userMessage);
        $maxLength = config('rag.semantic_cache.skip_if_length_lte', 20);
        if (mb_strlen($trimmed) <= $maxLength) {
            return true;
        }

        // 3. Conversation has memory notes (personalized state = VIP customers) — ~0.1μs
        if ($conversation && ! empty($conversation->memory_notes)) {
            return true;
        }

        // 4. Message mentions a product name/alias — skip cache to ensure fresh stock check
        $productTerms = $this->stockInjectionService->getProductNamesAndAliases();
        foreach ($productTerms as $term) {
            if (mb_strlen($term) >= 2 && mb_stripos($trimmed, $term) !== false) {
                return true;
            }
        }

        // 5. Keyword pattern match for standalone context-dependent terms — ~10-50μs
        $patterns = config('rag.semantic_cache.skip_patterns', []);
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // Chain-of-Thought (CoT) Methods
    // =========================================================================

    /** Detect if a user message requires complex reasoning (Chain-of-Thought). @see RAGIntentDetector::detectComplexity() */
    public function detectComplexity(string $userMessage): array
    {
        return $this->intentDetector->detectComplexity($userMessage);
    }

    /** Detect if user message explicitly requires a tool. @see RAGIntentDetector::detectToolIntent() */
    public function detectToolIntent(string $userMessage, array $enabledTools = []): array
    {
        return $this->intentDetector->detectToolIntent($userMessage, $enabledTools);
    }
}
