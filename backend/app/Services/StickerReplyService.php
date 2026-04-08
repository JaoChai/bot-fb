<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

class StickerReplyService
{
    public function __construct(protected OpenRouterService $openRouterService) {}

    /**
     * Generate a reply for a sticker message.
     *
     * @param  Bot  $bot  The bot instance
     * @param  Conversation  $conversation  The conversation
     * @param  array  $messageData  The sticker message data
     * @return string|null The reply message or null if reply is disabled
     */
    public function generateReply(Bot $bot, Conversation $conversation, array $messageData): ?string
    {
        $settings = $bot->settings;
        if (! $settings?->reply_sticker_enabled) {
            return null;
        }

        $mode = $settings->reply_sticker_mode ?? 'static';

        return match ($mode) {
            'ai' => $this->generateAIReply($bot, $conversation, $messageData),
            default => $this->getStaticReply($settings),
        };
    }

    /**
     * Get static reply message from settings.
     *
     * @param  mixed  $settings  Bot settings
     * @return string The static reply message
     */
    protected function getStaticReply($settings): string
    {
        return $settings->reply_sticker_message ?: 'ได้รับสติกเกอร์แล้วค่ะ';
    }

    /**
     * Generate AI reply by analyzing sticker with Vision API.
     *
     * @param  Bot  $bot  The bot instance
     * @param  Conversation  $conversation  The conversation
     * @param  array  $messageData  The sticker message data
     * @return string The AI-generated reply or fallback to static reply
     */
    protected function generateAIReply(Bot $bot, Conversation $conversation, array $messageData): string
    {
        // 1. Get sticker URL
        $stickerId = $messageData['sticker_id'] ?? null;
        if (! $stickerId) {
            return $this->getStaticReply($bot->settings);
        }

        $stickerUrl = "https://stickershop.line-scdn.net/stickershop/v1/sticker/{$stickerId}/android/sticker.png";

        // 2. Find vision-capable model
        $model = $this->getVisionModel($bot);
        if (! $model) {
            return $this->getStaticReply($bot->settings);
        }

        // 3. Build messages with personality context
        $messages = $this->buildVisionMessages($bot, $conversation);

        // 4. Call Vision API
        $apiKey = $bot->user?->settings?->getOpenRouterApiKey()
            ?? config('services.openrouter.api_key');

        try {
            $result = $this->openRouterService->chatWithVision(
                messages: $messages,
                imageUrls: [$stickerUrl],
                model: $model,
                temperature: $bot->llm_temperature ?? 0.7,
                maxTokens: 256,
                apiKeyOverride: $apiKey,
                useFallback: (bool) $bot->fallback_chat_model,
                fallbackModelOverride: $bot->fallback_chat_model
            );

            return $result['content'] ?: $this->getStaticReply($bot->settings);
        } catch (\Exception $e) {
            Log::warning('AI sticker reply failed', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);

            return $this->getStaticReply($bot->settings);
        }
    }

    /**
     * Get the vision-capable model from bot connection settings.
     *
     * Checks supportsVision() for each model in priority order:
     * 1. primary_chat_model
     * 2. fallback_chat_model
     * 3. decision_model
     * 4. fallback_decision_model
     */
    protected function getVisionModel(Bot $bot): ?string
    {
        $capabilityService = app(ModelCapabilityService::class);

        $candidates = [
            $bot->primary_chat_model,
            $bot->fallback_chat_model,
            $bot->decision_model,
            $bot->fallback_decision_model,
        ];

        foreach ($candidates as $model) {
            if ($model && $capabilityService->supportsVision($model)) {
                return $model;
            }
        }

        Log::warning('No vision-capable model found for sticker reply', [
            'bot_id' => $bot->id,
            'models_checked' => array_values(array_filter($candidates)),
        ]);

        return null;
    }

    /**
     * Build messages array for vision API including personality context.
     *
     * @param  Bot  $bot  The bot instance
     * @param  Conversation  $conversation  The conversation
     * @return array Messages array for API call
     */
    protected function buildVisionMessages(Bot $bot, Conversation $conversation): array
    {
        $messages = [];

        // System prompt with personality
        $systemPrompt = $this->buildSystemPrompt($bot);
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];

        // Recent conversation context (3 messages)
        $history = $this->getRecentHistory($conversation, 3);
        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['sender'] === 'user' ? 'user' : 'assistant',
                'content' => $msg['content'],
            ];
        }

        // User prompt for sticker analysis
        $userPrompt = $bot->settings->reply_sticker_ai_prompt
            ?: 'ลูกค้าส่งสติกเกอร์นี้มา กรุณาตอบกลับให้เหมาะสมกับความหมายของสติกเกอร์';
        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        return $messages;
    }

    /**
     * Build system prompt with bot personality for sticker analysis.
     *
     * @param  Bot  $bot  The bot instance
     * @return string The system prompt
     */
    protected function buildSystemPrompt(Bot $bot): string
    {
        // Get base personality from bot or flow
        $basePrompt = '';
        if (! empty($bot->system_prompt)) {
            $basePrompt = $bot->system_prompt;
        } elseif ($bot->default_flow_id && $bot->defaultFlow?->system_prompt) {
            $basePrompt = $bot->defaultFlow->system_prompt;
        }

        if (empty($basePrompt)) {
            $basePrompt = "You are a helpful AI assistant for {$bot->name}. Be friendly and helpful.";
        }

        // Add sticker-specific instruction
        $stickerInstruction = "\n\nWhen a user sends a sticker image:
1. Analyze the sticker to understand its emotion/meaning
2. Respond appropriately matching the sticker's sentiment
3. Keep your response SHORT (1-2 sentences maximum)
4. Be friendly and match the casual tone
5. Respond in the same language the user has been using";

        return $basePrompt.$stickerInstruction;
    }

    /**
     * Get recent conversation history for context.
     *
     * @param  Conversation  $conversation  The conversation
     * @param  int  $limit  Maximum number of messages to retrieve
     * @return array Array of messages with sender and content
     */
    protected function getRecentHistory(Conversation $conversation, int $limit = 3): array
    {
        $query = $conversation->messages()
            ->whereIn('sender', ['user', 'bot'])
            ->where('type', 'text');

        if ($conversation->context_cleared_at) {
            $query->where('created_at', '>', $conversation->context_cleared_at);
        }

        return $query->latest()
            ->take($limit)
            ->get()
            ->reverse()
            ->map(fn ($msg) => ['sender' => $msg->sender, 'content' => $msg->content])
            ->values()
            ->toArray();
    }
}
