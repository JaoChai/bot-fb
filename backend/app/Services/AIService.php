<?php

namespace App\Services;

use App\Exceptions\OpenRouterException;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\SecondAI\SecondAIService;
use Illuminate\Support\Facades\Log;

class AIService
{
    public function __construct(
        protected OpenRouterService $openRouter,
        protected RAGService $ragService,
        protected SecondAIService $secondAIService
    ) {}

    /**
     * Generate a response for a bot given a user message.
     *
     * Uses RAG (Retrieval Augmented Generation) when the bot has
     * Knowledge Base enabled, enhancing responses with relevant context.
     */
    public function generateResponse(
        Bot $bot,
        string $userMessage,
        ?Conversation $conversation = null
    ): array {
        // Get conversation history if available
        $history = $conversation
            ? $this->getConversationHistory($conversation, $bot->context_window)
            : [];

        // Get flow for RAGService (agentic mode) and Second AI check
        $flow = $conversation?->currentFlow ?? $bot->defaultFlow;

        // Use RAGService to generate response (handles KB integration + agentic mode automatically)
        $result = $this->ragService->generateResponse(
            bot: $bot,
            userMessage: $userMessage,
            conversationHistory: $history,
            conversation: $conversation,
            flow: $flow
        );
        if ($flow && $flow->second_ai_enabled) {
            $secondAIResult = $this->secondAIService->process(
                response: $result['content'],
                flow: $flow,
                userMessage: $userMessage,
                apiKey: $bot->user?->openrouter_api_key
            );

            $result['content'] = $secondAIResult['content'];
            $result['second_ai'] = [
                'applied' => $secondAIResult['second_ai_applied'],
                'metadata' => $secondAIResult['second_ai'] ?? [],
            ];
        }

        // Ensure usage key exists with defaults (some models may not return usage data)
        if (! isset($result['usage'])) {
            $result['usage'] = [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ];
            Log::warning('AI response missing usage data', [
                'model' => $result['model'] ?? 'unknown',
                'from_cache' => $result['from_cache'] ?? false,
            ]);
        }

        // Calculate cost
        $result['cost'] = $this->openRouter->estimateCost(
            $result['usage']['prompt_tokens'] ?? 0,
            $result['usage']['completion_tokens'] ?? 0,
            $result['model'] ?? 'unknown'
        );

        return $result;
    }

    /**
     * Generate a response and save it to the conversation.
     */
    public function generateAndSaveResponse(
        Bot $bot,
        Conversation $conversation,
        Message $userMessage
    ): Message {
        try {
            $result = $this->generateResponse(
                $bot,
                $userMessage->content,
                $conversation
            );

            // Build message data with RAG metadata
            $messageData = [
                'sender' => 'bot',
                'content' => $result['content'],
                'type' => 'text',
                'model_used' => $result['model'] ?? 'unknown',
                'prompt_tokens' => $result['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $result['usage']['completion_tokens'] ?? 0,
                'cost' => $result['cost'] ?? 0,
                // Enhanced usage tracking (OpenRouter Best Practice)
                'cached_tokens' => $result['usage']['cached_tokens'] ?? null,
                'reasoning_tokens' => $result['usage']['reasoning_tokens'] ?? null,
                'reasoning_content' => $result['reasoning'] ?? null,
            ];

            // Include RAG metadata if KB was used
            if (! empty($result['rag']) && $result['rag']['enabled']) {
                $messageData['metadata'] = [
                    'rag' => $result['rag'],
                ];
            }

            // Include Second AI metadata if applied
            if (! empty($result['second_ai']) && $result['second_ai']['applied']) {
                $messageData['metadata'] = array_merge(
                    $messageData['metadata'] ?? [],
                    ['second_ai' => $result['second_ai']]
                );
            }

            // Create bot response message
            $botMessage = $conversation->messages()->create($messageData);

            // Update bot stats
            $bot->increment('total_messages');
            $bot->update(['last_active_at' => now()]);

            return $botMessage;
        } catch (OpenRouterException $e) {
            Log::error('AI response generation failed', [
                'bot_id' => $bot->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            // Create error message
            return $conversation->messages()->create([
                'sender' => 'bot',
                'content' => $this->getErrorMessage($e),
                'type' => 'text',
            ]);
        }
    }

    /**
     * Get conversation history for context.
     */
    protected function getConversationHistory(Conversation $conversation, int $limit = 10): array
    {
        $query = $conversation->messages()
            ->whereIn('sender', ['user', 'bot']);

        // Filter out messages before context was cleared
        if ($conversation->context_cleared_at) {
            $query->where('created_at', '>', $conversation->context_cleared_at);
        }

        return $query->latest()
            ->take($limit)
            ->get()
            ->reverse()
            ->map(fn (Message $msg) => [
                'sender' => $msg->sender,
                'content' => $msg->content,
            ])
            ->values()
            ->toArray();
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
     * Get user-friendly error message.
     */
    protected function getErrorMessage(OpenRouterException $e): string
    {
        if ($e->isRateLimited()) {
            return 'I\'m receiving too many messages right now. Please try again in a moment.';
        }

        if ($e->isAuthError()) {
            return 'I\'m having trouble connecting. Please contact support.';
        }

        return 'I apologize, but I\'m having trouble processing your request. Please try again.';
    }

    /**
     * Test bot configuration.
     */
    public function testBotConfiguration(Bot $bot, string $testMessage = 'Hello!'): array
    {
        return $this->generateResponse($bot, $testMessage);
    }

    /**
     * Check if AI service is available.
     */
    public function isAvailable(): bool
    {
        return $this->openRouter->isConfigured() && $this->openRouter->testConnection();
    }

    /**
     * List available models.
     */
    public function listModels(): array
    {
        return $this->openRouter->listModels();
    }

    /**
     * Get recommended models for different use cases.
     */
    public function getRecommendedModels(): array
    {
        return [
            'quality' => [
                'id' => 'anthropic/claude-3.5-sonnet',
                'name' => 'Claude 3.5 Sonnet',
                'description' => 'Best quality responses',
                'cost_per_million_tokens' => 3.00,
            ],
            'balanced' => [
                'id' => 'openai/gpt-4o',
                'name' => 'GPT-4o',
                'description' => 'Good balance of quality and cost',
                'cost_per_million_tokens' => 2.50,
            ],
            'economical' => [
                'id' => 'openai/gpt-4o-mini',
                'name' => 'GPT-4o Mini',
                'description' => 'Cost-effective for simple tasks',
                'cost_per_million_tokens' => 0.15,
            ],
            'open_source' => [
                'id' => 'meta-llama/llama-3.1-70b-instruct',
                'name' => 'Llama 3.1 70B',
                'description' => 'Open source alternative',
                'cost_per_million_tokens' => 0.52,
            ],
        ];
    }
}
