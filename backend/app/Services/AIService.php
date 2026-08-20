<?php

namespace App\Services;

use App\Exceptions\OpenRouterException;
use App\Jobs\ExtractEntitiesJob;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Guardrail\GuardrailOutputSanitizer;
use App\Services\Guardrail\OffTopicCircuitBreaker;
use App\Services\Guardrail\OffTopicSignalExtractor;
use App\Services\Payment\OrderPayloadExtractor;
use Illuminate\Support\Facades\Log;

class AIService
{
    public function __construct(
        protected OpenRouterService $openRouter,
        protected RAGService $ragService,
        protected StockGuardService $stockGuard,
        private readonly OrderPayloadExtractor $orderPayload,
        private readonly OffTopicSignalExtractor $offTopicSignal,
        private readonly OffTopicCircuitBreaker $offTopicCircuitBreaker,
        private readonly GuardrailOutputSanitizer $outputSanitizer,
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
        ?Conversation $conversation = null,
        array $excludeMessageIds = []
    ): array {
        // Off-topic circuit breaker — ตัดวงจรก่อนเรียก LLM เลยถ้าลูกค้าคนนี้โดน guardrail
        // ซ้ำเกิน threshold ในบทสนทนาเดียวกันแล้ว (กัน token cost จากการใช้ฟรีซ้ำๆ)
        if ($conversation !== null && $this->offTopicCircuitBreaker->isTripped($bot, $conversation)) {
            Log::warning('Off-topic circuit breaker tripped', [
                'bot_id' => $bot->id,
                'conversation_id' => $conversation->id,
            ]);

            return [
                'content' => OffTopicCircuitBreaker::CANNED_MESSAGE,
                'model' => 'circuit_breaker',
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
                'cost' => 0.0,
                'order_payload' => null,
                'off_topic_triggered' => true,
            ];
        }

        // Get conversation history if available
        // excludeMessageIds: ข้อความ turn ปัจจุบันที่ถูก save ไปแล้ว ต้องตัดออกจาก history
        // ไม่งั้น LLM เห็นข้อความเดิมซ้ำ 2 turn (history + current) แล้วตีความจำนวนผิด
        $history = $conversation
            ? $this->getConversationHistory($conversation, $bot->context_window, $excludeMessageIds)
            : [];

        // Get flow for RAGService (agentic mode) and Second AI check
        $flow = $conversation?->currentFlow ?? $bot->defaultFlow;

        // Use RAGService to generate response (handles KB integration automatically)
        $result = $this->ragService->generateResponse(
            bot: $bot,
            userMessage: $userMessage,
            conversationHistory: $history,
            conversation: $conversation,
            flow: $flow
        );

        // Stock Guard: hard-block selling out-of-stock products
        $guardResult = $this->stockGuard->validate($result['content'], $userMessage);
        if ($guardResult['blocked']) {
            $result['content'] = $guardResult['content'];
            $result['stock_guard'] = [
                'blocked' => true,
                'blocked_products' => $guardResult['blocked_products'],
            ];
        }

        // ตัดบล็อกออเดอร์ออกจากข้อความก่อนใครได้เห็น — ทำที่นี่จุดเดียวเพราะทั้ง webhook
        // pipeline และ ProcessAggregatedMessages ผ่านเมธอดนี้เสมอ (ทางออกอื่นทั้งหมด
        // — LINE push, Flex, bubbles, หน้าเว็บ — อ่านจาก content ที่ผ่านตรงนี้แล้ว)
        $result['order_payload'] = null;
        if (config('delivery.order_payload_enabled', false)) {
            $extracted = $this->orderPayload->extract($result['content'] ?? '');
            $result['content'] = $extracted['clean'];
            $result['order_payload'] = $extracted['payload'];
        }

        // Off-topic signal marker — เหมือน [[ORDER]] ด้านบน ตัดออกก่อนใครได้เห็น
        $offTopicExtracted = $this->offTopicSignal->extract($result['content'] ?? '');
        $result['content'] = $offTopicExtracted['clean'];
        $result['off_topic_triggered'] = $offTopicExtracted['triggered'];
        if ($offTopicExtracted['triggered'] && $result['content'] === '') {
            // LLM ปล่อยแค่ marker ไม่มีข้อความอื่นเลย — ถ้าปล่อยเป็นสตริงว่าง
            // ProcessAggregatedMessages จะไม่ส่งอะไรถึงลูกค้าเลย (if ($botMessage->content))
            $result['content'] = OffTopicCircuitBreaker::CANNED_MESSAGE;
        }
        if ($conversation !== null && $offTopicExtracted['triggered']) {
            $this->offTopicCircuitBreaker->recordTrigger($bot, $conversation);
        }

        // Output sanitizer — ตาข่ายสุดท้ายกันคำตอบหลุด (code block/markdown จริง/อ้างว่าเป็น AI)
        // ใช้กับทุกคำตอบ ไม่ใช่แค่ที่ถูกตีว่า off-topic
        $sanitizerResult = $this->outputSanitizer->check($result['content'] ?? '');
        if ($sanitizerResult['flagged']) {
            Log::warning('Guardrail output sanitizer triggered', [
                'bot_id' => $bot->id,
                'conversation_id' => $conversation?->id,
                'reason' => $sanitizerResult['reason'],
            ]);
            $result['content'] = OffTopicCircuitBreaker::CANNED_MESSAGE;
            $result['order_payload'] = null;
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
                $conversation,
                excludeMessageIds: [$userMessage->id]
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

            $metadata = [];
            if (! empty($result['rag']) && $result['rag']['enabled']) {
                $metadata['rag'] = $result['rag'];
            }
            // แหล่งความจริงของจำนวนสินค้า — เส้นทางเงินอ่านจากตรงนี้ก่อน regex (Task 10)
            if (! empty($result['order_payload'])) {
                $metadata['order_payload'] = $result['order_payload'];
            }
            if ($metadata !== []) {
                $messageData['metadata'] = $metadata;
            }

            // Create bot response message
            $botMessage = $conversation->messages()->create($messageData);

            // Update bot stats
            $bot->increment('total_messages');
            $bot->update(['last_active_at' => now()]);

            // Auto-extract entities from conversation (async, throttled every 5 messages)
            if (ExtractEntitiesJob::shouldExtract($conversation)) {
                ExtractEntitiesJob::dispatch($conversation);
            }

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
    protected function getConversationHistory(Conversation $conversation, int $limit = 10, array $excludeMessageIds = []): array
    {
        $query = $conversation->messages()
            ->whereIn('sender', ['user', 'bot']);

        if ($excludeMessageIds !== []) {
            $query->whereNotIn('id', $excludeMessageIds);
        }

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
     * Get user-friendly error message (Thai — customers are Thai-speaking).
     *
     * Public so webhook jobs that call generateResponse() directly can reuse the same
     * copy instead of sending silence (or a raw English error) on failure.
     */
    public function getErrorMessage(OpenRouterException $e): string
    {
        if ($e->isRateLimited()) {
            return 'ตอนนี้มีข้อความเข้ามาเยอะมากครับ 🙏 รบกวนพี่รอสักครู่แล้วส่งใหม่อีกครั้งนะครับ';
        }

        if ($e->isAuthError()) {
            return 'ระบบขัดข้องชั่วคราวครับ 🙏 เดี๋ยวแอดมินรีบตรวจสอบให้นะครับ';
        }

        return 'ขอโทษครับ ระบบใช้เวลาประมวลผลนานกว่าปกติ 🙏 รบกวนพี่พิมพ์เข้ามาอีกครั้งนะครับ เดี๋ยวตอบให้เลยครับ';
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
