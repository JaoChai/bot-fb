<?php

namespace App\Services\LineWebhook;

use App\Jobs\ReserveAccountStock;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AIService;
use App\Services\Chat\ConversationContextService;
use App\Services\LINEService;
use App\Services\ModelCapabilityService;
use App\Services\OpenRouterService;
use App\Services\Payment\SlipVerificationResult;
use App\Services\Payment\SlipVerificationService;
use App\Services\StickerReplyService;
use App\Support\LlmJson;
use Illuminate\Support\Facades\Log;

class LineWebhookResponseService
{
    /**
     * Keywords indicating a pending order in conversation history.
     * Used by vision analysis to detect when a slip image is expected.
     * Copied verbatim from ProcessLINEWebhook::ORDER_CONTEXT_KEYWORDS (line 46).
     */
    private const ORDER_CONTEXT_KEYWORDS = ['รวมยอดโอน', 'สรุปรายการ', 'เลขบัญชี', 'รวมทั้งหมด', 'โอนเข้าบัญชี', 'ส่งสลิป'];

    /** Shared with ManualPaymentConfirmService — keep this the single source of the success template. */
    public const SLIP_SUCCESS_TEMPLATE = "เงินเข้าแล้ว {amount} บาท ✅\nออเดอร์: {order_summary}\nส่งใน 5-10 นาที ขอบคุณครับ\n[ยืนยันชำระเงิน]";

    private const SLIP_FAIL_TEMPLATE = 'ได้รับสลิปแล้วครับ ขอตรวจสอบยอดสักครู่ เดี๋ยวแอดมินยืนยันให้อีกครั้งนะครับ 🙏';

    private const SLIP_PENDING_TEMPLATE = 'สลิปเพิ่งโอนมา ธนาคารกำลังประมวลผลครับ 🙏 รบกวนรอ 1-2 นาทีแล้วส่งสลิปเดิมมาอีกครั้งนะครับ ระบบจะตรวจให้อัตโนมัติ';

    // รูปโหลดจาก LINE ไม่สำเร็จ (media_url ว่าง) — ตอบลูกค้าให้ส่งใหม่ แทนการเงียบ
    private const IMAGE_UNAVAILABLE_TEMPLATE = 'ขออภัยครับ ระบบโหลดรูปที่ส่งมาไม่สำเร็จ 🙏 รบกวนส่งรูปอีกครั้งนะครับ';

    public function __construct(
        private readonly AIService $aiService,
        private readonly OpenRouterService $openRouterService,
        private readonly StickerReplyService $stickerReplyService,
        private readonly ConversationContextService $conversationContext,
        private readonly ModelCapabilityService $modelCapability,
        private readonly LINEService $line,
        private readonly SlipVerificationService $slipVerification,
    ) {}

    /**
     * Stage 3: Generate AI response and persist bot Message.
     *
     * Sets $ctx->response to a ResponseEnvelope (or leaves null when no response
     * should be sent). Also stores the saved bot Message in $ctx->metadata['bot_message']
     * so Stage 4 can push to LINE and update stats without re-querying.
     *
     * Ports post-transaction AI block (ProcessLINEWebhook::processEvent lines 468-559)
     * plus handleStickerReply (:894-971) and handleImageAnalysis (:981-1157).
     */
    public function generate(WebhookContext $ctx): void
    {
        // Skip conditions checked by Stage 2 (ContextService) and propagated via metadata
        if (! empty($ctx->metadata['bot_inactive']) || ! empty($ctx->metadata['handover'])) {
            return;
        }

        // Aggregation-buffered events are handled by ProcessAggregatedMessages — skip
        if ($ctx->aggregationBuffered) {
            return;
        }

        $messageType = $ctx->messageType();

        match ($messageType) {
            'text' => $this->generateTextResponse($ctx),
            'sticker' => $this->generateStickerResponse($ctx),
            'image' => $this->generateImageResponse($ctx),
            default => null, // video, audio, file, location — no AI response
        };
    }

    // -------------------------------------------------------------------------
    // Text branch (lines 498-558 of legacy processEvent)
    // -------------------------------------------------------------------------

    private function generateTextResponse(WebhookContext $ctx): void
    {
        $conversation = $ctx->conversation;
        $userMessage = $ctx->userMessage;

        if (! $conversation || ! $userMessage) {
            return;
        }

        // Auto-clear stale context before AI generates response (line 500)
        $this->conversationContext->autoClearIfIdle($conversation);

        // Generate AI response — also persists bot Message (line 503-507)
        // Exception intentionally re-thrown: mirrors legacy lines 545-555 (throw $e)
        $botMessage = $this->aiService->generateAndSaveResponse(
            $ctx->bot,
            $conversation,
            $userMessage
        );

        // Store for Stage 4 (LINE push, stats, broadcast)
        $ctx->metadata['bot_message'] = $botMessage;

        if ($botMessage->content) {
            $ctx->response = ResponseEnvelope::text($botMessage->content);
        }
    }

    // -------------------------------------------------------------------------
    // Sticker branch (lines 894-971 of legacy handleStickerReply)
    // -------------------------------------------------------------------------

    private function generateStickerResponse(WebhookContext $ctx): void
    {
        $conversation = $ctx->conversation;
        if (! $conversation) {
            return;
        }

        $settings = $ctx->bot->settings;
        if (! $settings?->reply_sticker_enabled) {
            return;
        }

        $mode = $settings->reply_sticker_mode ?? 'static';
        $messageData = $ctx->event['message'] ?? [];

        try {
            // Show loading indicator for AI mode (line 910-912)
            if ($mode === 'ai') {
                $this->line->showLoadingIndicator($ctx->bot, $ctx->userId(), 15);
            }

            $responseMessage = $this->stickerReplyService->generateReply($ctx->bot, $conversation, $messageData);

            if (! $responseMessage) {
                return;
            }

            // Save bot response (lines 927-936)
            $botMessage = $conversation->messages()->create([
                'sender' => 'bot',
                'content' => $responseMessage,
                'type' => 'text',
                'metadata' => [
                    'sticker_reply' => true,
                    'sticker_mode' => $mode,
                    'sticker_id' => $messageData['sticker_id'] ?? null,
                ],
            ]);

            $ctx->metadata['bot_message'] = $botMessage;
            $ctx->metadata['sticker_mode'] = $mode;
            $ctx->response = ResponseEnvelope::text($responseMessage);
        } catch (\Exception $e) {
            Log::warning('Failed to reply to sticker', [
                'bot_id' => $ctx->bot->id,
                'mode' => $mode,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Image branch (lines 981-1157 of legacy handleImageAnalysis)
    // -------------------------------------------------------------------------

    private function generateImageResponse(WebhookContext $ctx): void
    {
        $conversation = $ctx->conversation;
        $userMessage = $ctx->userMessage;

        if (! $conversation || ! $userMessage) {
            return;
        }

        // Check bot is active and not in handover (legacy lines 991-998)
        if ($ctx->bot->status !== 'active') {
            return;
        }
        if ($conversation->is_handover) {
            return;
        }

        // Get vision-capable model (line 1002-1006)
        $model = $this->getVisionModel($ctx);
        if (! $model) {
            return;
        }

        $imageUrl = $userMessage->media_url ?? $ctx->metadata['media_url'] ?? null;
        if (! $imageUrl) {
            // รูปโหลดจาก LINE ไม่สำเร็จ (media_url ว่าง) — เดิม return เงียบ ทำให้ลูกค้าไม่ได้รับตอบ
            // และแอดมินไม่รู้ (เคสสลิปจะเงียบสนิท ต้องมากดยืนยันเอง). ปิดช่องเงียบ:
            // ตอบลูกค้าให้ส่งรูปใหม่ + เตือนแอดมินถ้าเปิดตรวจสลิป (มีปุ่มยืนยันมือให้)
            Log::warning('Image download failed (media_url null) — replying to customer + alerting admin', [
                'bot_id' => $ctx->bot->id,
                'conversation_id' => $conversation->id,
                'message_id' => $userMessage->id,
            ]);

            $botMessage = $conversation->messages()->create([
                'sender' => 'bot',
                'content' => self::IMAGE_UNAVAILABLE_TEMPLATE,
                'type' => 'text',
                'metadata' => ['image_download_failed' => true],
            ]);
            $ctx->metadata['bot_message'] = $botMessage;
            $ctx->response = ResponseEnvelope::text(self::IMAGE_UNAVAILABLE_TEMPLATE);

            if ($ctx->bot->settings?->slip_verification_enabled) {
                $this->slipVerification->notifyAdmin($ctx->bot, $conversation, new SlipVerificationResult(
                    isSlip: true, passed: false, failReason: 'image_download_failed',
                ));
            }

            return;
        }

        // Show loading indicator (line 1009)
        $this->line->showLoadingIndicator($ctx->bot, $ctx->userId(), 30);

        // Auto-clear stale context before slip verification / vision generate a response (line 1013)
        try {
            $this->conversationContext->autoClearIfIdle($conversation);
        } catch (\Throwable $e) {
            Log::warning('autoClearIfIdle failed', [
                'bot_id' => $ctx->bot->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Slip path needs a wider window — "รวมยอดโอน" often falls outside the last 5
        // messages in real chats. Vision keeps 5 (prompt size), so slice from the wider set.
        $slipHistory = $this->getVisionConversationHistory($conversation, 15);

        // Slip verification (EasySlip-first) — ผ่าน/ไม่ผ่านตอบเลย ไม่เข้า vision
        if ($this->trySlipVerification($ctx, $imageUrl, $slipHistory)) {
            return;
        }

        $history = array_slice($slipHistory, -5);

        try {
            // คำตอบที่ classifySlipImage ร่างไว้แล้ว (ตัดสิน+ตอบจากการมองรูปครั้งเดียว) —
            // ใช้เลย ไม่ต้องเรียก vision ซ้ำ ทั้งประหยัดและกันการตัดสินรอบสองที่อาจขัดกับรอบแรก
            $draft = $ctx->metadata['slip_vision_draft'] ?? null;

            if ($draft !== null) {
                $result = $draft;
            } else {
                $messages = $this->buildVisionChatMessages($ctx, $history, $this->getImageAnalysisPrompt($ctx, $history));

                // Get API key (lines 1048-1050)
                $apiKey = $ctx->bot->user?->settings?->getOpenRouterApiKey()
                    ?? config('services.openrouter.api_key');

                // Call Vision API (lines 1052-1061)
                $result = $this->openRouterService->chatWithVision(
                    messages: $messages,
                    imageUrls: [$imageUrl],
                    model: $model,
                    temperature: $ctx->bot->llm_temperature ?? 0.7,
                    maxTokens: $ctx->bot->llm_max_tokens ?? 1024,
                    apiKeyOverride: $apiKey,
                    fallbackModelOverride: $ctx->bot->fallback_chat_model
                );
            }

            $responseContent = $result['content'] ?? '';

            if (empty($responseContent)) {
                Log::warning('Empty response from Vision API', [
                    'bot_id' => $ctx->bot->id,
                    'conversation_id' => $conversation->id,
                ]);

                return;
            }

            // Save bot response (lines 1075-1091)
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

            $ctx->metadata['bot_message'] = $botMessage;
            $ctx->response = ResponseEnvelope::text($responseContent);

            // Vision ตัดสินเองว่าเป็นสลิป (ตอบให้ลูกค้ารอทีมงาน) ทั้งที่ EasySlip/classifier ไม่จับ —
            // ต้องมี alert ไปหาแอดมินด้วย ไม่งั้นลูกค้ารอเงียบๆ โดยไม่มีใครรู้ (ข้าม ถ้า alert ไปแล้วในรอบนี้)
            if ($ctx->bot->settings?->slip_verification_enabled
                && empty($ctx->metadata['slip_alert_sent'])
                && str_contains($responseContent, 'ได้รับสลิป')) {
                $this->slipVerification->notifyAdmin($ctx->bot, $conversation, new SlipVerificationResult(
                    isSlip: true, passed: false, failReason: 'unreadable',
                ));
            }

            Log::info('Image analyzed successfully', [
                'bot_id' => $ctx->bot->id,
                'conversation_id' => $conversation->id,
                'model' => $result['model'] ?? $model,
                'tokens_used' => $result['usage']['total_tokens'] ?? 0,
            ]);

        } catch (\Exception $e) {
            Log::error('Image analysis failed', [
                'bot_id' => $ctx->bot->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
                ...(! app()->environment('production') ? ['trace' => $e->getTraceAsString()] : []),
            ]);
            // Fail silently — image is already saved, no AI response (line 1155)
        }
    }

    // -------------------------------------------------------------------------
    // Vision helpers (ported from ProcessLINEWebhook lines 1168-1311)
    // -------------------------------------------------------------------------

    /**
     * Get the vision-capable model from bot connection settings.
     * Priority: primary_chat_model → fallback_chat_model.
     * Last resort: primary_chat_model even without vision confirmation.
     * Port of ProcessLINEWebhook::getVisionModel (:1168).
     */
    private function getVisionModel(WebhookContext $ctx): ?string
    {
        $candidates = [
            $ctx->bot->primary_chat_model,
            $ctx->bot->fallback_chat_model,
        ];

        foreach ($candidates as $model) {
            if ($model && $this->modelCapability->supportsVision($model)) {
                return $model;
            }
        }

        // Last resort — use primary model directly (line 1188-1196)
        $primaryModel = $ctx->bot->primary_chat_model;
        if ($primaryModel) {
            Log::warning('Vision model detection failed, using primary model as last resort', [
                'bot_id' => $ctx->bot->id,
                'primary_model' => $primaryModel,
                'models_checked' => array_values(array_filter($candidates)),
            ]);

            return $primaryModel;
        }

        Log::warning('No vision-capable model found in bot settings', [
            'bot_id' => $ctx->bot->id,
            'models_checked' => array_values(array_filter($candidates)),
        ]);

        return null;
    }

    /**
     * Build system prompt for vision/image analysis.
     * Port of ProcessLINEWebhook::buildVisionSystemPrompt (:1212).
     */
    private function buildVisionSystemPrompt(WebhookContext $ctx): string
    {
        $basePrompt = '';

        if (! empty($ctx->bot->system_prompt)) {
            $basePrompt = $ctx->bot->system_prompt;
        } elseif ($ctx->bot->default_flow_id) {
            $flow = $ctx->bot->defaultFlow;
            if ($flow && ! empty($flow->system_prompt)) {
                $basePrompt = $flow->system_prompt;
            }
        }

        if (empty($basePrompt)) {
            $basePrompt = "You are a helpful AI assistant for {$ctx->bot->name}. Be friendly, professional, and helpful.";
        }

        // Slip-verification bots only reach vision when EasySlip could NOT verify the
        // slip — so the LLM must never self-confirm a payment here.
        if ($ctx->bot->settings?->slip_verification_enabled) {
            $visionInstruction = "\n\n## การวิเคราะห์รูปภาพ\n"
                ."เมื่อได้รับรูปภาพ ให้ตรวจสอบก่อนว่าเป็นสลิปโอนเงิน/หลักฐานการชำระเงินหรือไม่\n\n"
                ."**ถ้าเป็นสลิปโอนเงิน:**\n"
                ."- ระบบตรวจสลิปอัตโนมัติของร้านไม่สามารถอ่านรูปนี้ได้ คุณห้ามยืนยันการรับเงินเองเด็ดขาด\n"
                ."- ห้ามตอบว่า \"เงินเข้าแล้ว\" และห้ามใส่แท็ก [ยืนยันชำระเงิน]\n"
                ."- ตอบเพียง: \"ได้รับสลิปแล้วครับ รอทีมงานตรวจสอบยอดเข้าสักครู่นะครับ ปกติไม่เกิน 5 นาที ขอบคุณที่รอครับ\"\n\n"
                ."**ถ้าไม่ใช่สลิป:**\n"
                .'- อธิบายรูปภาพและช่วยตอบคำถามตามบริบทของการสนทนา';

            return $basePrompt.$visionInstruction;
        }

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
     * Port of ProcessLINEWebhook::getImageAnalysisPrompt (:1251).
     */
    private function getImageAnalysisPrompt(WebhookContext $ctx, array $conversationHistory = []): string
    {
        $settings = $ctx->bot->settings;
        if ($settings && ! empty($settings->image_analysis_prompt)) {
            return $settings->image_analysis_prompt;
        }

        $hasPendingOrder = $this->detectPendingOrder($conversationHistory);

        if ($hasPendingOrder) {
            return 'ลูกค้าส่งรูปมา — ตรวจสอบว่าเป็นสลิปโอนเงินหรือไม่ ถ้าเป็นสลิปให้ยืนยันยอดตาม conversation history';
        }

        return 'กรุณาอธิบายรูปภาพนี้ และช่วยตอบคำถามหากมี';
    }

    /**
     * Detect if conversation history indicates a pending order awaiting payment.
     * Port of ProcessLINEWebhook::detectPendingOrder (:1272).
     */
    private function detectPendingOrder(array $conversationHistory): bool
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
     * Get conversation history for vision context (text messages only, recent 5).
     * Port of ProcessLINEWebhook::getVisionConversationHistory (:1290).
     */
    private function getVisionConversationHistory(Conversation $conversation, int $limit = 5): array
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
            ->map(fn (Message $msg) => [
                'sender' => $msg->sender,
                'content' => $msg->content,
            ])
            ->values()
            ->toArray();
    }

    // -------------------------------------------------------------------------
    // Slip verification (EasySlip-first, runs before vision in the image branch)
    // -------------------------------------------------------------------------

    /**
     * ตรวจสลิปกับ EasySlip ก่อนเข้า vision
     * คืน true = จัดการตอบแล้ว (ข้าม vision), false = ไป vision ต่อ (ไม่ใช่สลิป/ปิด feature/API ล่ม)
     */
    private function trySlipVerification(WebhookContext $ctx, string $imageUrl, array $history): bool
    {
        $settings = $ctx->bot->settings;
        if (! $settings?->slip_verification_enabled) {
            return false;
        }

        try {
            $result = $this->slipVerification->verify(
                $ctx->bot,
                $ctx->conversation,
                $ctx->userMessage,
                $imageUrl,
                $history,
                // นอกจากคืน ?bool แล้ว classifySlipImage ยังฝากร่างคำตอบไว้ใน
                // $ctx->metadata['slip_vision_draft'] ให้ generateImageResponse ใช้ต่อ
                fn (): ?bool => $this->classifySlipImage($ctx, $imageUrl, $history),
            );

            // config_error (token หาย) ปฏิบัติเหมือน api_error — แจ้งแอดมิน + ปล่อยไป vision ให้ลูกค้ายังได้รับตอบ
            if (in_array($result->failReason, ['api_error', 'config_error'], true)) {
                $this->slipVerification->notifyAdmin($ctx->bot, $ctx->conversation, $result);
                $ctx->metadata['slip_alert_sent'] = true;

                return false; // fallback vision — ลูกค้าต้องได้รับตอบ
            }

            if (! $result->isSlip) {
                return false; // รูปทั่วไป → vision เดิม
            }

            if ($result->passed) {
                $template = $settings->slip_success_message ?: self::SLIP_SUCCESS_TEMPLATE;
                $text = str_replace(
                    ['{amount}', '{order_summary}'],
                    [number_format($result->amount ?? 0), $result->orderSummary ?? '-'],
                    $template,
                );
            } elseif ($result->failReason === 'pending') {
                // ธนาคารยังประมวลผลไม่เสร็จ — ลูกค้าแก้เองได้ (รอแล้วส่งใหม่) ไม่ต้อง alert แอดมิน
                $text = self::SLIP_PENDING_TEMPLATE;
            } else {
                $text = $settings->slip_fail_message ?: self::SLIP_FAIL_TEMPLATE;
                $this->slipVerification->notifyAdmin($ctx->bot, $ctx->conversation, $result);
            }

            $botMessage = $ctx->conversation->messages()->create([
                'sender' => 'bot',
                'content' => $text,
                'type' => 'text',
                'metadata' => [
                    'slip_verification' => true,
                    'slip_status' => $result->status(),
                    'slip_trans_ref' => $result->transRef,
                    'image_url' => $imageUrl,
                ],
            ]);

            $ctx->metadata['bot_message'] = $botMessage;

            if ($result->passed && $result->slipVerificationId !== null) {
                ReserveAccountStock::dispatch(
                    $ctx->bot->id,
                    $ctx->conversation->id,
                    $result->slipVerificationId,
                    $result->amount,
                    $result->orderItems ?? [],
                );
            }

            $ctx->response = ResponseEnvelope::text($text);

            Log::info('Slip verification handled image', [
                'bot_id' => $ctx->bot->id,
                'conversation_id' => $ctx->conversation->id,
                'status' => $result->passed ? 'passed' : $result->failReason,
                'trans_ref' => $result->transRef,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Slip verification crashed, falling back to vision', [
                'bot_id' => $ctx->bot->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * ตัดสิน+ร่างคำตอบในการเรียกครั้งเดียว (single-call structured output) — ใช้ตอน EasySlip
     * อ่านรูปไม่ได้ (400) เพื่อแยก "สลิปเบลอ" ออกจาก "รูปทั่วไป" (เช่น screenshot หน้าจออื่นๆ)
     *
     * คำตัดสิน (is_slip) กับคำตอบลูกค้า (reply) มาจากการมองรูปครั้งเดียวกัน จึงขัดแย้งกันเองไม่ได้
     * ถ้าไม่ใช่สลิป reply จะถูกเก็บไว้ใน metadata ให้ generateImageResponse ใช้เลยโดยไม่เรียก vision ซ้ำ
     * คืน null เมื่อตอบไม่ได้/เรียกไม่สำเร็จ → ฝั่ง verify() จะถือเป็นสลิป (fail-safe ไปทางตรวจมือ)
     */
    private function classifySlipImage(WebhookContext $ctx, string $imageUrl, array $history): ?bool
    {
        try {
            $model = $this->getVisionModel($ctx);
            if (! $model) {
                return null;
            }

            $apiKey = $ctx->bot->user?->settings?->getOpenRouterApiKey()
                ?? config('services.openrouter.api_key');

            $instruction = "ลูกค้าส่งรูปมา (แนบมากับข้อความนี้) ให้ตอบเป็น JSON เท่านั้น ห้ามมีข้อความอื่นนอก JSON รูปแบบ:\n"
                ."{\"is_slip\": true/false, \"reply\": \"...\"}\n"
                ."- is_slip: true เมื่อรูปเป็นสลิปโอนเงิน/หลักฐานการชำระเงินจากธนาคารหรือแอปการเงิน, false เมื่อเป็นรูปอื่น\n"
                .'- reply: เมื่อ is_slip เป็น false ให้เขียนข้อความตอบลูกค้าตามบริบทบทสนทนา; เมื่อ is_slip เป็น true ให้ใส่สตริงว่าง ""';

            $messages = $this->buildVisionChatMessages($ctx, array_slice($history, -5), $instruction);

            // chatWithVision ใส่ json_schema ให้เฉพาะ model ที่รองรับ structured_outputs —
            // ตัวอื่นพึ่งคำสั่ง JSON ใน prompt แล้ว parse เอง (decodeSlipCheck รองรับ JSON ห่อข้อความ/code fence)
            $schema = [
                'type' => 'object',
                'properties' => [
                    'is_slip' => [
                        'type' => 'boolean',
                        'description' => 'true เมื่อรูปเป็นสลิปโอนเงิน/หลักฐานการชำระเงินจากธนาคารหรือแอปการเงิน',
                    ],
                    'reply' => [
                        'type' => 'string',
                        'description' => 'ข้อความตอบลูกค้าตามบริบทบทสนทนา เมื่อ is_slip เป็น false; สตริงว่างเมื่อ is_slip เป็น true',
                    ],
                ],
                'required' => ['is_slip', 'reply'],
                'additionalProperties' => false,
            ];

            $result = $this->openRouterService->chatWithVision(
                messages: $messages,
                imageUrls: [$imageUrl],
                model: $model,
                // JSON ต้อง parse ได้เสถียร — ใช้ temperature ต่ำคงที่ ไม่ใช้ค่าแชทของบอท
                temperature: 0.3,
                maxTokens: $ctx->bot->llm_max_tokens ?? 1024,
                apiKeyOverride: $apiKey,
                fallbackModelOverride: $ctx->bot->fallback_chat_model,
                responseFormat: ['type' => 'json_schema', 'json_schema' => ['name' => 'slip_image_check', 'strict' => true, 'schema' => $schema]],
            );

            $decoded = $this->decodeSlipCheck($result['content'] ?? '');

            Log::info('Slip image classification', [
                'bot_id' => $ctx->bot->id,
                'conversation_id' => $ctx->conversation?->id,
                'is_slip' => $decoded['is_slip'] ?? null,
                'raw' => $decoded === null ? mb_substr($result['content'] ?? '', 0, 200) : null,
            ]);

            if ($decoded === null) {
                return null;
            }

            if ($decoded['is_slip'] === false && $decoded['reply'] !== '') {
                $ctx->metadata['slip_vision_draft'] = [
                    'content' => $decoded['reply'],
                    'model' => $result['model'] ?? $model,
                    'usage' => $result['usage'] ?? [],
                ];
            }

            return $decoded['is_slip'];
        } catch (\Throwable $e) {
            Log::warning('Slip image classification failed, treating as slip (fail-safe)', [
                'bot_id' => $ctx->bot->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * แกะผล JSON ของ classifySlipImage — รองรับทั้ง JSON ล้วน (structured output)
     * และ JSON ที่ห่อด้วยข้อความ/code fence (model ที่ไม่รองรับ) คืน null เมื่อ parse/validate ไม่ผ่าน
     *
     * @return array{is_slip: bool, reply: string}|null
     */
    private function decodeSlipCheck(string $content): ?array
    {
        $data = json_decode(LlmJson::extractObject($content), true);
        if (! is_array($data) || ! is_bool($data['is_slip'] ?? null) || ! is_string($data['reply'] ?? null)) {
            return null;
        }

        return ['is_slip' => $data['is_slip'], 'reply' => trim($data['reply'])];
    }

    /**
     * ประกอบ messages สำหรับ vision call: system prompt (persona + กติการูปภาพ) + ประวัติ + คำสั่งท้าย
     */
    private function buildVisionChatMessages(WebhookContext $ctx, array $history, string $userContent): array
    {
        $messages = [];

        $systemPrompt = $this->buildVisionSystemPrompt($ctx);
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['sender'] === 'user' ? 'user' : 'assistant',
                'content' => $msg['content'],
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $userContent];

        return $messages;
    }
}
