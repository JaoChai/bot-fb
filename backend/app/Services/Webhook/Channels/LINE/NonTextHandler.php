<?php

namespace App\Services\Webhook\Channels\LINE;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\LeadRecoveryService;
use App\Services\LINEService;
use App\Services\ResponseHoursService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LINE non-text handler — verbatim move of the non-text message method that
 * lived in App\Jobs\ProcessLINEWebhook (Task 4, 2026-08-26).
 *
 * Moved byte-for-byte (only `$this->x` → injected dependency / `$this->bot`):
 *   - handleNonTextMessage()    → handle()
 *
 * Shared helpers the method calls are constructor-injected services:
 *   - createNewConversation()   → injected (the job's protected method)
 *   - updateStatsForUserMessageOnly() → injected (the job's protected method)
 *   - handleOutsideResponseHours()    → inlined verbatim (only called from here)
 *
 * The image branch already delegates to VisionHandler (Task 3) — left as-is.
 * The sticker branch delegates to StickerHandler (this task).
 */
class NonTextHandler
{
    /**
     * @var \Closure(string, LINEService): Conversation
     */
    private $createNewConversation;

    /**
     * @var \Closure(Conversation, int): void
     */
    private $updateStatsForUserMessageOnly;

    /**
     * @param  \Closure(string, LINEService): Conversation  $createNewConversation
     * @param  \Closure(Conversation, int): void  $updateStatsForUserMessageOnly
     */
    public function __construct(
        private readonly Bot $bot,
        private readonly ResponseHoursService $responseHoursService,
        private readonly LeadRecoveryService $leadRecoveryService,
        $createNewConversation,
        $updateStatsForUserMessageOnly,
        private readonly StickerHandler $stickerHandler,
    ) {
        $this->createNewConversation = $createNewConversation;
        $this->updateStatsForUserMessageOnly = $updateStatsForUserMessageOnly;
    }

    /**
     * handle() — the public entry point (mirrors handleNonTextMessage).
     *
     * Signature is identical to the old internal call. No logic was edited —
     * the body is a byte-for-byte move of handleNonTextMessage (see class
     * docblock for the $this->x substitutions).
     */
    public function handle(
        LINEService $lineService,
        array $event
    ): void {
        $userId = $lineService->extractUserId($event);
        $replyToken = $lineService->extractReplyToken($event);
        $messageData = $lineService->extractMessage($event);
        $messageType = $messageData['type'];

        // Extract LINE webhook event metadata (best practice)
        $webhookEventId = $lineService->extractWebhookEventId($event);
        $eventTimestamp = $lineService->extractEventTimestamp($event);
        $isRedeliveryEvent = $lineService->isRedelivery($event);

        if (! $userId) {
            return;
        }

        // Check if this is a redelivered event that we've already processed
        if ($isRedeliveryEvent && $webhookEventId) {
            if (Message::where('webhook_event_id', $webhookEventId)->exists()) {
                Log::info('Redelivered non-text webhook already processed, skipping', [
                    'webhook_event_id' => $webhookEventId,
                    'message_type' => $messageType,
                ]);

                return;
            }
        }

        // Download media BEFORE transaction (external API call shouldn't be in transaction)
        $mediaUrl = null;
        $mediaType = null;
        $content = null;

        if (in_array($messageType, ['image', 'video', 'audio', 'file'])) {
            $mediaData = $lineService->downloadAndStoreFile($this->bot, $messageData['id'], $messageType);
            if ($mediaData) {
                $mediaUrl = $mediaData['url'];
                $mediaType = $mediaData['mime_type'];
            }
            $content = match ($messageType) {
                'image' => '[รูปภาพ]',
                'video' => '[วิดีโอ]',
                'audio' => '[เสียง]',
                'file' => '[ไฟล์]',
                default => '[สื่อ]',
            };
        } elseif ($messageType === 'sticker') {
            $content = '[สติกเกอร์]';
            // Construct sticker URL from LINE CDN
            $stickerId = $messageData['sticker_id'] ?? null;
            if ($stickerId) {
                $mediaUrl = "https://stickershop.line-scdn.net/stickershop/v1/sticker/{$stickerId}/android/sticker.png";
                $mediaType = 'image/png';
            }
        } elseif ($messageType === 'location') {
            $lat = $messageData['latitude'] ?? '';
            $lng = $messageData['longitude'] ?? '';
            $addr = $messageData['address'] ?? '';
            $content = "[ตำแหน่ง] {$addr} ({$lat}, {$lng})";
        } else {
            $content = '[ข้อความที่ไม่รองรับ]';
        }

        // Variables for broadcasting after transaction
        $userMessage = null;
        $conversation = null;
        $isNewConversation = false;

        // Process in transaction to prevent race conditions and ensure atomic updates
        DB::transaction(function () use (
            $lineService,
            $userId,
            $messageData,
            $messageType,
            $mediaUrl,
            $mediaType,
            $content,
            $webhookEventId,
            $eventTimestamp,
            $isRedeliveryEvent,
            &$userMessage,
            &$conversation,
            &$isNewConversation
        ) {
            // Find or create conversation (include handover status for auto_handover bots)
            // Use lockForUpdate() to prevent race condition when multiple webhooks arrive simultaneously
            $existingConversation = Conversation::where('bot_id', $this->bot->id)
                ->where('external_customer_id', $userId)
                ->where('channel_type', 'line')
                ->whereIn('status', ['active', 'handover'])
                ->lockForUpdate()
                ->first();

            $isNewConversation = ! $existingConversation;
            $conversation = $existingConversation ?? ($this->createNewConversation)($userId, $lineService);

            // Primary deduplication: webhookEventId (LINE best practice)
            if ($webhookEventId && Message::where('conversation_id', $conversation->id)
                ->where('webhook_event_id', $webhookEventId)
                ->exists()) {
                Log::info('Duplicate non-text webhook ignored (by webhook_event_id)', [
                    'conversation_id' => $conversation->id,
                    'webhook_event_id' => $webhookEventId,
                ]);

                return;
            }

            // Fallback deduplication: external_message_id (backward compatibility)
            if ($messageData['id'] && Message::where('conversation_id', $conversation->id)
                ->where('external_message_id', $messageData['id'])
                ->exists()) {
                Log::info('Duplicate non-text webhook ignored (by external_message_id)', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $messageData['id'],
                ]);

                return;
            }

            // Save message to database with LINE event metadata
            $userMessage = $conversation->messages()->create([
                'sender' => 'user',
                'content' => $content,
                'type' => $messageType,
                'media_url' => $mediaUrl,
                'media_type' => $mediaType,
                'external_message_id' => $messageData['id'],
                'webhook_event_id' => $webhookEventId,
                'is_redelivery' => $isRedeliveryEvent,
                'event_timestamp' => $eventTimestamp,
            ]);

            // Update stats atomically with message creation
            ($this->updateStatsForUserMessageOnly)($conversation, $userMessage->id);
            if ($isNewConversation) {
                $this->bot->update([
                    'total_conversations' => DB::raw('total_conversations + 1'),
                ]);
            }
        });

        // Broadcasts AFTER transaction commits (non-blocking)
        // Refresh conversation to get actual DB values after DB::raw updates
        if ($conversation) {
            $conversation->refresh();
            $conversationData = [
                'id' => $conversation->id,
                'message_count' => $conversation->message_count,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'unread_count' => $conversation->unread_count,
            ];

            // Mark lead recovery as responded when customer sends a message
            $this->leadRecoveryService->markCustomerResponded($conversation);
        }
        if ($userMessage) {
            broadcast(new MessageSent($userMessage, $conversationData ?? null))->toOthers();
        }
        if ($conversation) {
            broadcast(new ConversationUpdated($conversation, 'message_received'))->toOthers();
        }

        // Check response hours AFTER saving message but BEFORE AI response
        $responseHoursResult = $this->responseHoursService->checkResponseHours($this->bot);

        if (! $responseHoursResult['allowed']) {
            Log::info('Non-text message received outside response hours', [
                'bot_id' => $this->bot->id,
                'message_type' => $messageType,
                'status' => $responseHoursResult['status'],
                'current_time' => $responseHoursResult['current_time'] ?? null,
            ]);

            if (! $conversation?->is_handover) {
                $this->handleOutsideResponseHours($lineService, $replyToken, $userId);
            }

            return; // Skip AI response
        }

        // Handle image analysis with AI Vision
        if ($messageType === 'image' && $mediaUrl && $conversation && $replyToken) {
            app(VisionHandler::class)->analyze($lineService, $conversation, $userMessage, $mediaUrl, $userId, $replyToken, $conversationData ?? null);

            return; // Skip sticker reply handling for images
        }

        // Reply to stickers if enabled (and not in handover mode, and bot is active)
        if ($messageType === 'sticker' && $replyToken && $conversation && ! $conversation->is_handover && $this->bot->status === 'active') {
            $this->stickerHandler->reply($lineService, $conversation, $messageData, $userId, $replyToken, $conversationData ?? null);
        }

        // Non-text messages (except stickers with reply enabled) are stored silently
    }

    /**
     * Handle messages received outside response hours.
     * Sends offline message if configured, otherwise stays silent.
     *
     * Verbatim move from ProcessLINEWebhook::handleOutsideResponseHours
     * (only called from the non-text path — the text path has its own copy).
     */
    private function handleOutsideResponseHours(
        LINEService $lineService,
        ?string $replyToken,
        string $userId
    ): void {
        $message = $this->responseHoursService->getOfflineMessage($this->bot->settings);

        Log::info('Message received outside response hours', [
            'bot_id' => $this->bot->id,
            'has_offline_message' => $message !== null,
        ]);

        if (! $message) {
            return;
        }

        try {
            $retryKey = $lineService->generateRetryKey();
            $lineService->replyWithFallback($this->bot, $replyToken, $userId, [$message], $retryKey);
        } catch (\Exception $e) {
            Log::warning('Failed to send offline message', [
                'bot_id' => $this->bot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
