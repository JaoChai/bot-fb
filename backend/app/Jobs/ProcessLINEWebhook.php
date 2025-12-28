<?php

namespace App\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Services\AIService;
use App\Services\LINEService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessLINEWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Bot $bot,
        public array $event
    ) {}

    /**
     * Execute the job.
     */
    public function handle(LINEService $lineService, AIService $aiService): void
    {
        try {
            $this->processEvent($lineService, $aiService);
        } catch (\Exception $e) {
            Log::error('LINE webhook processing failed', [
                'bot_id' => $this->bot->id,
                'event_type' => $this->event['type'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Process the LINE event.
     */
    protected function processEvent(LINEService $lineService, AIService $aiService): void
    {
        // Only process message events
        if (!$lineService->isMessageEvent($this->event)) {
            Log::debug('Ignoring non-message event', [
                'type' => $this->event['type'] ?? 'unknown',
            ]);
            return;
        }

        // Only process text messages for now
        if (!$lineService->isTextMessage($this->event)) {
            $this->handleNonTextMessage($lineService);
            return;
        }

        // Extract event data
        $userId = $lineService->extractUserId($this->event);
        $replyToken = $lineService->extractReplyToken($this->event);
        $messageData = $lineService->extractMessage($this->event);

        if (!$userId || !$messageData['text']) {
            Log::warning('Invalid LINE message event', [
                'has_user_id' => (bool) $userId,
                'has_text' => (bool) $messageData['text'],
            ]);
            return;
        }

        // Show loading indicator immediately (before AI processing)
        // This runs outside the transaction for immediate user feedback
        // Non-blocking - if it fails, we continue processing
        $lineService->showLoadingIndicator($this->bot, $userId, 30);

        // Process in transaction
        DB::transaction(function () use ($lineService, $aiService, $userId, $replyToken, $messageData) {
            // Find or create conversation
            $conversation = $this->findOrCreateConversation($userId, $lineService);

            // Save user message
            $userMessage = $this->saveUserMessage($conversation, $messageData);

            // Broadcast user message to connected clients
            broadcast(new MessageSent($userMessage))->toOthers();

            // Skip AI response if conversation is in handover mode
            if ($conversation->is_handover) {
                Log::info('Conversation in handover mode, skipping AI response', [
                    'conversation_id' => $conversation->id,
                ]);
                return;
            }

            // Generate AI response
            $botMessage = $aiService->generateAndSaveResponse(
                $this->bot,
                $conversation,
                $userMessage
            );

            // Broadcast bot message to connected clients
            broadcast(new MessageSent($botMessage))->toOthers();

            // Send reply to LINE
            if ($replyToken && $botMessage->content) {
                $lineService->reply($this->bot, $replyToken, [$botMessage->content]);
            }

            // Update conversation stats
            $this->updateConversationStats($conversation);

            // Broadcast conversation update
            broadcast(new ConversationUpdated($conversation, 'message_received'))->toOthers();
        });
    }

    /**
     * Handle non-text messages.
     */
    protected function handleNonTextMessage(LINEService $lineService): void
    {
        $replyToken = $lineService->extractReplyToken($this->event);
        $messageData = $lineService->extractMessage($this->event);

        if (!$replyToken) {
            return;
        }

        // Send a helpful response for non-text messages
        $messageType = $messageData['type'];
        $response = match ($messageType) {
            'image' => 'ขออภัยครับ ขณะนี้ยังไม่รองรับการวิเคราะห์รูปภาพ กรุณาพิมพ์ข้อความแทนครับ',
            'video' => 'ขออภัยครับ ขณะนี้ยังไม่รองรับการวิเคราะห์วิดีโอ กรุณาพิมพ์ข้อความแทนครับ',
            'audio' => 'ขออภัยครับ ขณะนี้ยังไม่รองรับการวิเคราะห์เสียง กรุณาพิมพ์ข้อความแทนครับ',
            'sticker' => 'ขอบคุณสำหรับสติกเกอร์ครับ! มีอะไรให้ช่วยเพิ่มเติมไหมครับ?',
            'location' => 'ได้รับตำแหน่งที่ตั้งแล้วครับ มีอะไรให้ช่วยเหลือไหมครับ?',
            'file' => 'ขออภัยครับ ขณะนี้ยังไม่รองรับการวิเคราะห์ไฟล์ กรุณาพิมพ์ข้อความแทนครับ',
            default => 'ขออภัยครับ ไม่สามารถประมวลผลข้อความประเภทนี้ได้ กรุณาพิมพ์ข้อความแทนครับ',
        };

        try {
            $lineService->reply($this->bot, $replyToken, [$response]);
        } catch (\Exception $e) {
            Log::warning('Failed to send non-text message response', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find or create conversation for the LINE user.
     */
    protected function findOrCreateConversation(string $userId, LINEService $lineService): Conversation
    {
        // Try to find existing active conversation
        $conversation = Conversation::where('bot_id', $this->bot->id)
            ->where('external_customer_id', $userId)
            ->where('channel_type', 'line')
            ->where('status', 'active')
            ->first();

        if ($conversation) {
            return $conversation;
        }

        // Create or update customer profile
        $customerProfile = $this->findOrCreateCustomerProfile($userId, $lineService);

        // Create new conversation
        $conversation = Conversation::create([
            'bot_id' => $this->bot->id,
            'customer_profile_id' => $customerProfile?->id,
            'external_customer_id' => $userId,
            'channel_type' => 'line',
            'status' => 'active',
            'current_flow_id' => $this->bot->default_flow_id,
            'message_count' => 0,
        ]);

        // Broadcast new conversation event
        broadcast(new ConversationUpdated($conversation, 'created'))->toOthers();

        return $conversation;
    }

    /**
     * Find or create customer profile.
     */
    protected function findOrCreateCustomerProfile(string $userId, LINEService $lineService): ?CustomerProfile
    {
        // Try to find existing profile
        $profile = CustomerProfile::where('external_id', $userId)
            ->where('channel_type', 'line')
            ->first();

        if ($profile) {
            return $profile;
        }

        // Get LINE profile
        $lineProfile = $lineService->getProfile($this->bot, $userId);

        // Create new profile
        return CustomerProfile::create([
            'external_id' => $userId,
            'channel_type' => 'line',
            'display_name' => $lineProfile['displayName'] ?? null,
            'picture_url' => $lineProfile['pictureUrl'] ?? null,
            'first_interaction_at' => now(),
            'last_interaction_at' => now(),
            'interaction_count' => 1,
            'metadata' => [
                'status_message' => $lineProfile['statusMessage'] ?? null,
            ],
        ]);
    }

    /**
     * Save user message to database.
     */
    protected function saveUserMessage(Conversation $conversation, array $messageData): Message
    {
        return $conversation->messages()->create([
            'sender' => 'user',
            'content' => $messageData['text'],
            'type' => 'text',
            'external_message_id' => $messageData['id'],
        ]);
    }

    /**
     * Update conversation statistics.
     */
    protected function updateConversationStats(Conversation $conversation): void
    {
        $conversation->increment('message_count', 2); // User + Bot messages
        $conversation->update(['last_message_at' => now()]);

        // Update bot stats
        $this->bot->increment('total_messages', 2);
        $this->bot->increment('total_conversations');
        $this->bot->update(['last_active_at' => now()]);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('LINE webhook job failed permanently', [
            'bot_id' => $this->bot->id,
            'event_type' => $this->event['type'] ?? 'unknown',
            'error' => $exception->getMessage(),
        ]);
    }
}
