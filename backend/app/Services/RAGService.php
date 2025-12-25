<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

/**
 * RAG (Retrieval Augmented Generation) Service
 *
 * Integrates Knowledge Base semantic search into bot responses.
 * When a user sends a message, the service:
 * 1. Searches the bot's KB for relevant documents
 * 2. Builds context from matching chunks
 * 3. Enhances the system prompt with KB context
 * 4. Generates an informed response via the LLM
 */
class RAGService
{
    public function __construct(
        protected SemanticSearchService $searchService,
        protected OpenRouterService $openRouter
    ) {}

    /**
     * Generate a response using RAG if KB is enabled.
     *
     * @param Bot $bot The bot to respond as
     * @param string $userMessage The user's message
     * @param array $conversationHistory Previous messages for context
     * @return array Response with content, usage stats, and RAG metadata
     */
    public function generateResponse(
        Bot $bot,
        string $userMessage,
        array $conversationHistory = []
    ): array {
        $kbContext = '';
        $kbMetadata = [
            'enabled' => false,
            'results_count' => 0,
            'chunks_used' => [],
        ];

        // Check if KB is enabled and bot has a knowledge base
        if ($this->shouldUseKnowledgeBase($bot)) {
            $kbContext = $this->getKnowledgeBaseContext(
                $bot,
                $userMessage,
                $kbMetadata
            );
        }

        // Build enhanced system prompt with KB context
        $systemPrompt = $this->buildEnhancedPrompt(
            $bot->system_prompt ?? $this->getDefaultSystemPrompt($bot),
            $kbContext
        );

        // Get model and API key from user settings (centralized)
        $model = $this->getModelForBot($bot);
        $apiKey = $this->getApiKeyForBot($bot);

        // Send to OpenRouter
        $result = $this->openRouter->generateBotResponse(
            userMessage: $userMessage,
            systemPrompt: $systemPrompt,
            conversationHistory: $conversationHistory,
            model: $model,
            temperature: $bot->llm_temperature,
            maxTokens: $bot->llm_max_tokens,
            apiKeyOverride: $apiKey
        );

        // Add RAG metadata to result
        $result['rag'] = $kbMetadata;

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
     */
    protected function getKnowledgeBaseContext(
        Bot $bot,
        string $query,
        array &$metadata
    ): string {
        $kb = $bot->knowledgeBase;

        try {
            // Search KB using bot's configured settings
            $results = $this->searchService->search(
                knowledgeBaseId: $kb->id,
                query: $query,
                limit: $bot->kb_max_results ?? config('rag.max_results', 3),
                threshold: $bot->kb_relevance_threshold ?? config('rag.default_threshold', 0.7)
            );

            if ($results->isEmpty()) {
                Log::debug('No relevant KB results found', [
                    'bot_id' => $bot->id,
                    'kb_id' => $kb->id,
                    'query' => substr($query, 0, 100),
                ]);
                return '';
            }

            // Update metadata
            $metadata['enabled'] = true;
            $metadata['results_count'] = $results->count();
            $metadata['chunks_used'] = $results->map(fn ($r) => [
                'document' => $r['document_name'],
                'similarity' => $r['similarity'],
            ])->toArray();

            // Format context for prompt
            return $this->formatKnowledgeBaseContext($results);
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
     * Build enhanced system prompt with KB context.
     */
    protected function buildEnhancedPrompt(string $basePrompt, string $kbContext): string
    {
        if (empty($kbContext)) {
            return $basePrompt;
        }

        // Append KB context to the system prompt
        return $basePrompt . "\n\n" . $kbContext;
    }

    /**
     * Get the LLM model to use for a bot.
     *
     * Priority:
     * 1. User Settings model (centralized - recommended for single-user)
     * 2. Bot-specific model (legacy/override)
     * 3. Config default model
     */
    protected function getModelForBot(Bot $bot): ?string
    {
        // Priority 1: User Settings (centralized model)
        $user = $bot->user;
        if ($user && $user->settings && $user->settings->openrouter_model) {
            return $user->settings->openrouter_model;
        }

        // Priority 2: Bot-specific model (legacy support)
        if ($bot->llm_model) {
            return $bot->llm_model;
        }

        // Priority 3: Config default (handled by OpenRouterService)
        return null;
    }

    /**
     * Get the API key to use for a bot.
     *
     * Priority:
     * 1. User Settings API key (centralized - recommended)
     * 2. Config/env fallback (handled by OpenRouterService)
     */
    protected function getApiKeyForBot(Bot $bot): ?string
    {
        $user = $bot->user;
        if ($user && $user->settings && $user->settings->hasOpenRouterKey()) {
            return $user->settings->openrouter_api_key;
        }

        // Let OpenRouterService use its default from config
        return null;
    }

    /**
     * Get default system prompt for a bot.
     */
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
     * Test RAG for a bot with a sample query.
     */
    public function testRAG(Bot $bot, string $testQuery): array
    {
        $metadata = [
            'enabled' => false,
            'results_count' => 0,
            'chunks_used' => [],
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
        ];
    }
}
