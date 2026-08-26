<?php

namespace App\Services\Webhook\Channels\LINE;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\ConversationContextService;
use App\Services\FlowPluginService;
use App\Services\LINEService;
use App\Services\ModelCapabilityService;
use App\Services\MultipleBubblesService;
use App\Services\OpenRouterService;
use App\Services\PaymentFlexService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LINE image-analysis handler — verbatim move of the vision methods that
 * lived in App\Jobs\ProcessLINEWebhook (Task 3, 2026-08-26).
 *
 * Moved byte-for-byte (only `$this->x` → injected dependency / `$this->bot`):
 *   - handleImageAnalysis()        → analyze()
 *   - getVisionModel()
 *   - buildVisionSystemPrompt()
 *   - getImageAnalysisPrompt()
 *   - detectPendingOrder()         (vision-only helper — moved along)
 *   - getVisionConversationHistory() (vision-only helper — moved along)
 *   - ORDER_CONTEXT_KEYWORDS const (used by detectPendingOrder only)
 *
 * $this->bot references became the constructor-injected Bot (the job passed
 * its own $this->bot to the handler — same object, same values).
 * app(X::class) lookups became constructor-injected services — same
 * bindings resolved at the call site.
 *
 * The only signature change: the original was `void`. The public entry point
 * `analyze()` now returns the LLM reply text (or null when no reply is sent)
 * so the caller can drive its reply path with the value — mirroring how the
 * original's reply text flowed into the job's reply/broadcast blocks. No
 * logic was edited.
 */
class VisionHandler
{
    /**
     * Keywords indicating a pending order in conversation history.
     * Used by vision analysis to detect when a slip image is expected.
     */
    private const ORDER_CONTEXT_KEYWORDS = ['รวมยอดโอน', 'สรุปรายการ', 'เลขบัญชี', 'รวมทั้งหมด', 'โอนเข้าบัญชี', 'ส่งสลิป'];

    public function __construct(
        private readonly Bot $bot,
        private readonly OpenRouterService $openRouterService,
        private readonly ModelCapabilityService $modelCapability,
        private readonly ConversationContextService $conversationContext,
        private readonly MultipleBubblesService $bubblesService,
        private readonly PaymentFlexService $paymentFlexService,
        private readonly FlowPluginService $flowPluginService,
    ) {}

    /**
     * analyze() — the public entry point (mirrors handleImageAnalysis).
     *
     * Signature is identical to the old internal call except the return type:
     * the original was `void`; analyze() returns the LLM reply text (or null
     * when no reply is sent) so the caller can drive its reply path with the
     * value. Argument list is unchanged. No logic was edited — the body is a
     * byte-for-byte move of handleImageAnalysis (see class docblock for the
     * $this->x substitutions).
     *
     * @return string|null the LLM reply content, or null when no reply sent
     */
    public function analyze(
        LINEService $lineService,
        Conversation $conversation,
        ?Message $userMessage,
        string $imageUrl,
        string $userId,
        string $replyToken,
        ?array $conversationData = null
    ): ?string {
        // Check if bot is active
        if ($this->bot->status !== 'active') {
            return null;
        }

        // Check if conversation is in handover mode
        if ($conversation->is_handover) {
            return null;
        }

        // Get vision-capable model from bot settings
        $model = $this->getVisionModel();

        if (! $model) {
            return null;
        }

        // Show loading indicator
        $lineService->showLoadingIndicator($this->bot, $userId, 30);

        try {
            // Auto-clear stale context before image analysis
            $this->conversationContext->autoClearIfIdle($conversation);

            // Build system prompt for image analysis
            $systemPrompt = $this->buildVisionSystemPrompt();

            // Get conversation history for context
            $history = $this->getVisionConversationHistory($conversation);

            // Build messages array
            $messages = [];

            // Add system prompt
            if ($systemPrompt) {
                $messages[] = [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ];
            }

            // Add conversation history
            foreach ($history as $msg) {
                $messages[] = [
                    'role' => $msg['sender'] === 'user' ? 'user' : 'assistant',
                    'content' => $msg['content'],
                ];
            }

            // Add current image message with prompt (context-aware: detects pending orders)
            $imagePrompt = $this->getImageAnalysisPrompt($history);
            $messages[] = [
                'role' => 'user',
                'content' => $imagePrompt,
            ];

            // Get API key
            $apiKey = $this->bot->user?->settings?->getOpenRouterApiKey()
                ?? config('services.openrouter.api_key');

            // Call Vision API
            $result = $this->openRouterService->chatWithVision(
                messages: $messages,
                imageUrls: [$imageUrl],
                model: $model,
                temperature: $this->bot->llm_temperature ?? 0.7,
                maxTokens: $this->bot->llm_max_tokens ?? 1024,
                apiKeyOverride: $apiKey,
                fallbackModelOverride: $this->bot->fallback_chat_model
            );

            $responseContent = $result['content'] ?? '';

            if (empty($responseContent)) {
                Log::warning('Empty response from Vision API', [
                    'bot_id' => $this->bot->id,
                    'conversation_id' => $conversation->id,
                ]);

                return null;
            }

            // Save bot response
            $botMessage = $conversation->messages()->create([
                'sender' => 'bot',
                'content' => $responseContent,
                'type' => 'text',
                'model_used' => $result['model'] ?? $model,
                'prompt_tokens' => $result['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $result['usage']['completion_tokens'] ?? 0,
                'cost' => $this->openRouterService->estimateCost(
                    $result['usage']['prompt_tokens'] ?? 0,
                    $result['usage']['completion_tokens'] ?? 0,
                    $result['model'] ?? $model
                ),
                'metadata' => [
                    'vision_analysis' => true,
                    'image_url' => $imageUrl,
                ],
            ]);

            // Update conversation stats
            $conversation->update([
                'message_count' => DB::raw('message_count + 1'),
                'last_message_at' => now(),
                'last_message_id' => $botMessage->id,
            ]);

            // Update bot stats
            $this->bot->update([
                'total_messages' => DB::raw('total_messages + 1'),
                'last_active_at' => now(),
            ]);

            // Send reply to LINE with fallback to push if token expired
            if ($this->bubblesService->isEnabled($this->bot)) {
                $bubbles = $this->bubblesService->parseIntoBubbles($responseContent, $this->bot);
                $this->bubblesService->sendBubbles($this->bot, $userId, $replyToken, $bubbles, $conversation);
            } else {
                $transformed = $this->paymentFlexService->tryConvertToFlex($responseContent, $conversation);
                $retryKey = $lineService->generateRetryKey();
                $lineService->replyWithFallback($this->bot, $replyToken, $userId, [$transformed], $retryKey);
            }

            // Refresh and broadcast
            $conversation->refresh();
            $updatedConversationData = [
                'id' => $conversation->id,
                'message_count' => $conversation->message_count,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'unread_count' => $conversation->unread_count,
            ];

            broadcast(new MessageSent($botMessage, $updatedConversationData))->toOthers();
            broadcast(new ConversationUpdated($conversation, 'message_received'))->toOthers();

            // Execute flow plugins (e.g., Telegram notifications) after image analysis
            try {
                $this->flowPluginService
                    ->executePlugins($this->bot, $conversation, $botMessage);
            } catch (\Exception $e2) {
                Log::warning('Flow plugin execution failed after image analysis', [
                    'conversation_id' => $conversation->id,
                    'error' => $e2->getMessage(),
                ]);
            }

            Log::info('Image analyzed successfully', [
                'bot_id' => $this->bot->id,
                'conversation_id' => $conversation->id,
                'model' => $result['model'] ?? $model,
                'tokens_used' => $result['usage']['total_tokens'] ?? 0,
            ]);

            return $responseContent;

        } catch (\Exception $e) {
            Log::error('Image analysis failed', [
                'bot_id' => $this->bot->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
                ...(! app()->environment('production') ? ['trace' => $e->getTraceAsString()] : []),
            ]);
            // Fail silently - image is already saved, just no AI response

            return null;
        }
    }

    /**
     * Get the vision-capable model from bot connection settings.
     *
     * Checks supportsVision() for each model in priority order:
     * 1. primary_chat_model
     * 2. fallback_chat_model
     */
    protected function getVisionModel(): ?string
    {
        $candidates = [
            $this->bot->primary_chat_model,
            $this->bot->fallback_chat_model,
        ];

        // Level 1+2: Check supportsVision (API + config + heuristic)
        foreach ($candidates as $model) {
            if ($model && $this->modelCapability->supportsVision($model)) {
                return $model;
            }
        }

        // Level 3: Last resort — use primary_chat_model directly
        // Better to try and let OpenRouter error than fail silently
        $primaryModel = $this->bot->primary_chat_model;
        if ($primaryModel) {
            Log::warning('Vision model detection failed, using primary model as last resort', [
                'bot_id' => $this->bot->id,
                'primary_model' => $primaryModel,
                'models_checked' => array_values(array_filter($candidates)),
            ]);

            return $primaryModel;
        }

        Log::warning('No vision-capable model found in bot settings', [
            'bot_id' => $this->bot->id,
            'models_checked' => array_values(array_filter($candidates)),
        ]);

        return null;
    }

    /**
     * Build system prompt for vision/image analysis.
     * Uses bot's system prompt with vision-specific additions.
     * Includes payment slip detection instructions.
     */
    protected function buildVisionSystemPrompt(): string
    {
        // Get base system prompt from bot or flow
        $basePrompt = '';

        if (! empty($this->bot->system_prompt)) {
            $basePrompt = $this->bot->system_prompt;
        } elseif ($this->bot->default_flow_id) {
            $flow = $this->bot->defaultFlow;
            if ($flow && ! empty($flow->system_prompt)) {
                $basePrompt = $flow->system_prompt;
            }
        }

        if (empty($basePrompt)) {
            $basePrompt = "You are a helpful AI assistant for {$this->bot->name}. Be friendly, professional, and helpful.";
        }

        // Add vision-specific instruction with payment slip detection
        $visionInstruction = "\n\n## การวิเคราะห์รูปภาพ\n"
            ."เมื่อได้รับรูปภาพ ให้ตรวจสอบก่อนว่าเป็นสลิปโอนเงิน/หลักฐานการชำระเงินหรือไม่\n\n"
            ."**ถ้าเป็นสลิปโอนเงิน:**\n"
            ."- อ่านยอดเงินที่โอนจากสลิป\n"
            ."- ดู conversation history เพื่อหาออเดอร์ที่รอชำระเงิน\n"
            ."- ตอบในรูปแบบนี้เท่านั้น:\n"
            ."  เงินเข้าแล้ว [จำนวนเงิน] บาท ✅\n"
            ."  ออเดอร์: [สรุปรายการจาก conversation history]\n"
            ."  ส่งใน 5-10 นาที ขอบคุณครับ\n"
            ."  [ยืนยันชำระเงิน]\n\n"
            ."**ถ้าไม่ใช่สลิป:**\n"
            .'- อธิบายรูปภาพและช่วยตอบคำถามตามบริบทของการสนทนา';

        return $basePrompt.$visionInstruction;
    }

    /**
     * Get the prompt to use when analyzing an image.
     * Context-aware: detects pending orders and instructs slip verification.
     */
    protected function getImageAnalysisPrompt(array $conversationHistory = []): string
    {
        // Check bot settings for custom image prompt
        $settings = $this->bot->settings;
        if ($settings && ! empty($settings->image_analysis_prompt)) {
            return $settings->image_analysis_prompt;
        }

        // Check if conversation has a pending order (payment context)
        $hasPendingOrder = $this->detectPendingOrder($conversationHistory);

        if ($hasPendingOrder) {
            return 'ลูกค้าส่งรูปมา — ตรวจสอบว่าเป็นสลิปโอนเงินหรือไม่ ถ้าเป็นสลิปให้ยืนยันยอดตาม conversation history';
        }

        return 'กรุณาอธิบายรูปภาพนี้ และช่วยตอบคำถามหากมี';
    }

    /**
     * Detect if conversation history indicates a pending order awaiting payment.
     */
    protected function detectPendingOrder(array $conversationHistory): bool
    {
        foreach ($conversationHistory as $msg) {
            $content = $msg['content'] ?? '';
            foreach (self::ORDER_CONTEXT_KEYWORDS as $keyword) {
                if (mb_strpos($content, $keyword) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get conversation history for vision context.
     * Limited to recent messages to keep context manageable.
     */
    protected function getVisionConversationHistory(Conversation $conversation, int $limit = 5): array
    {
        $query = $conversation->messages()
            ->whereIn('sender', ['user', 'bot'])
            ->where('type', 'text'); // Only include text messages in history

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
}
