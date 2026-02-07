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
        protected IntentAnalysisService $intentAnalysis,
        protected FlowCacheService $flowCacheService,
        protected ?QueryEnhancementService $queryEnhancement = null,
        protected ?SemanticCacheService $semanticCache = null
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
        ?Conversation $conversation = null,
        ?Flow $flow = null,
        ?string $apiKeyOverride = null
    ): array {
        // Get API key first (used for both decision and chat models)
        $apiKey = $apiKeyOverride ?? $this->getApiKeyForBot($bot);

        $bot->loadMissing(['defaultFlow.knowledgeBases']);

        // Step 0: Check Semantic Cache first (fastest path)
        if ($this->semanticCache?->isEnabled()) {
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

        // Step 1: Analyze intent using Decision Model
        $intent = $this->intentAnalysis->analyzeIntent($bot, $userMessage, [
            'validIntents' => ['chat', 'knowledge', 'flow'],
            'includeExamples' => true,
            'apiKey' => $apiKey,
        ]);

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
            $this->getSystemPromptForBot($bot),
            $kbContext,
            $bot,
            $memoryNotes
        );

        // Step 7: Add Chain-of-Thought instruction if question is complex
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

        // Step 8: Get chat models
        $chatModel = $this->getChatModelForBot($bot);
        $fallbackChatModel = $this->getFallbackChatModelForBot($bot);

        // Step 9: Calculate max tokens (increase for complex questions)
        $maxTokens = $bot->llm_max_tokens;
        if ($complexity['is_complex']) {
            $multiplier = config('rag.chain_of_thought.max_tokens_multiplier', 1.5);
            $maxTokens = (int) min($maxTokens * $multiplier, 4096);
        }

        // Step 10: Generate response using Chat Model
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
        $result['from_cache'] = false;

        // Step 10: Save to Semantic Cache for future similar queries
        if ($this->semanticCache?->isEnabled() && !empty($result['content'])) {
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

    /**
     * Check if the bot should use its Knowledge Base.
     */
    protected function shouldUseKnowledgeBase(Bot $bot): bool
    {
        // Must have KB enabled
        if (!$bot->kb_enabled) {
            return false;
        }

        // Must have a default flow with knowledge bases
        $defaultFlow = $this->flowCacheService->getDefaultFlow($bot->id);
        if (!$defaultFlow || !$defaultFlow->knowledgeBases()->exists()) {
            Log::debug('Bot has KB enabled but no knowledge bases in default flow', [
                'bot_id' => $bot->id,
                'has_default_flow' => $defaultFlow !== null,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Get context from Knowledge Base for the given query.
     *
     * Delegates to getFlowKnowledgeBaseContext since KBs are now accessed via Flow.
     */
    protected function getKnowledgeBaseContext(
        Bot $bot,
        string $query,
        array &$metadata
    ): string {
        // Get default flow and delegate to flow-based context retrieval
        $defaultFlow = $this->flowCacheService->getDefaultFlow($bot->id);
        if (!$defaultFlow) {
            return '';
        }

        return $this->getFlowKnowledgeBaseContext($defaultFlow, $query, $metadata);
    }

    /**
     * Format KB search results into context for the prompt.
     * Public method to allow delegation from controllers.
     */
    public function formatKnowledgeBaseContext($results): string
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
     * Build enhanced system prompt with memory notes, KB context, and multiple bubbles instruction.
     */
    protected function buildEnhancedPrompt(
        string $basePrompt,
        string $kbContext,
        ?Bot $bot = null,
        array $memoryNotes = []
    ): string {
        $prompt = $basePrompt;

        // Append memory notes if available (injected before KB context for priority)
        if (!empty($memoryNotes)) {
            $prompt .= "\n\n## Memory:\n";
            foreach ($memoryNotes as $content) {
                $prompt .= "- {$content}\n";
            }
        }

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
     * 1. Bot's primary_chat_model (from Connection Settings UI)
     * 2. Bot's fallback_chat_model
     * 3. Config default model (handled by OpenRouterService)
     */
    protected function getChatModelForBot(Bot $bot): ?string
    {
        // Priority 1: Bot's primary chat model (from Connection Settings UI)
        if ($bot->primary_chat_model) {
            return $bot->primary_chat_model;
        }

        // Priority 2: Bot's fallback chat model
        if ($bot->fallback_chat_model) {
            return $bot->fallback_chat_model;
        }

        // Priority 3: Config default (handled by OpenRouterService)
        return null;
    }

    /**
     * Get the fallback chat model for a bot.
     *
     * Priority:
     * 1. Bot's fallback_chat_model (from Connection Settings UI)
     * 2. Config fallback (handled by OpenRouterService)
     */
    protected function getFallbackChatModelForBot(Bot $bot): ?string
    {
        // Priority 1: Bot's fallback chat model (from Connection Settings UI)
        if ($bot->fallback_chat_model) {
            return $bot->fallback_chat_model;
        }

        // Priority 2: Config fallback (handled by OpenRouterService)
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
            'has_knowledge_base' => $bot->defaultFlow?->knowledgeBases()->exists() ?? false,
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
