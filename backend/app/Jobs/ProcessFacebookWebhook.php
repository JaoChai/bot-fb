<?php

namespace App\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Exceptions\CircuitOpenException;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Services\AIService;
use App\Services\AutoAssignmentService;
use App\Services\CircuitBreakerService;
use App\Services\LeadRecoveryService;
use App\Services\ProfilePictureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessFacebookWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Bot $bot,
        public array $payload
    ) {}

    /**
     * Execute the job.
     */
    public function handle(AIService $aiService, CircuitBreakerService $circuitBreaker): void
    {
        try {
            // Use circuit breaker to protect against DB failures
            $circuitBreaker->execute(
                'database',
                fn () => $this->processPayload($aiService),
                fn () => $this->sendFallbackMessage()
            );
        } catch (CircuitOpenException $e) {
            // Circuit is open - send fallback and don't retry
            Log::warning('Circuit breaker open for Facebook webhook', [
                'bot_id' => $this->bot->id,
                'service' => $e->getService(),
            ]);
            $this->sendFallbackMessage();
        } catch (\Exception $e) {
            Log::error('Facebook webhook processing failed', [
                'bot_id' => $this->bot->id,
                'error' => $e->getMessage(),
                ...(! app()->environment('production') ? ['trace' => $e->getTraceAsString()] : []),
            ]);

            throw $e;
        }
    }

    /**
     * Send fallback message when system is unavailable.
     * This method doesn't depend on database operations.
     */
    protected function sendFallbackMessage(): void
    {
        if (! config('bot.send_fallback_on_circuit_open', true)) {
            return;
        }

        // Extract sender ID from payload
        $senderId = null;
        foreach ($this->payload['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $event) {
                $senderId = $event['sender']['id'] ?? null;
                if ($senderId) {
                    break 2;
                }
            }
        }

        if (! $senderId) {
            return;
        }

        $fallbackMessage = config('bot.fallback_message', 'ขออภัยครับ ระบบกำลังมีปัญหาชั่วคราว กรุณาลองใหม่ในอีกสักครู่');

        try {
            $this->sendFacebookMessage($senderId, $fallbackMessage);

            Log::info('Sent fallback message to Facebook user', [
                'bot_id' => $this->bot->id,
                'recipient_id' => $senderId,
            ]);
        } catch (\Exception $e) {
            // Don't throw - fallback message is best-effort
            Log::error('Failed to send fallback message to Facebook', [
                'bot_id' => $this->bot->id,
                'recipient_id' => $senderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process the Facebook webhook payload.
     */
    protected function processPayload(AIService $aiService): void
    {
        // Verify this is a page subscription
        if (($this->payload['object'] ?? '') !== 'page') {
            Log::debug('Ignoring non-page Facebook webhook', [
                'object' => $this->payload['object'] ?? 'unknown',
            ]);

            return;
        }

        // Process each entry in the webhook
        foreach ($this->payload['entry'] ?? [] as $entry) {
            // Process messaging events
            foreach ($entry['messaging'] ?? [] as $event) {
                $this->processMessagingEvent($event, $aiService);
            }
        }
    }

    /**
     * Process a single messaging event.
     */
    protected function processMessagingEvent(array $event, AIService $aiService): void
    {
        $senderId = $event['sender']['id'] ?? null;
        $recipientId = $event['recipient']['id'] ?? null;
        $timestamp = $event['timestamp'] ?? null;

        if (! $senderId) {
            Log::warning('Facebook messaging event missing sender ID');

            return;
        }

        // Ignore echo messages (messages sent by the page itself)
        if (isset($event['message']['is_echo']) && $event['message']['is_echo']) {
            Log::debug('Ignoring echo message from page');

            return;
        }

        // Variables for broadcasting after transaction
        $userMessage = null;
        $botMessage = null;
        $conversation = null;
        $isNewConversation = false;
        $isHandover = false;

        // Process in transaction
        DB::transaction(function () use (
            $aiService,
            $senderId,
            $event,
            &$userMessage,
            &$botMessage,
            &$conversation,
            &$isNewConversation,
            &$isHandover
        ) {
            // Find or create conversation (include handover status for auto_handover bots)
            $existingConversation = Conversation::where('bot_id', $this->bot->id)
                ->where('external_customer_id', $senderId)
                ->where('channel_type', 'facebook')
                ->whereIn('status', ['active', 'handover'])
                ->first();

            $isNewConversation = ! $existingConversation;
            $conversation = $existingConversation ?? $this->createNewConversation($senderId);
            $isHandover = $conversation->is_handover;

            // Handle different event types
            if (isset($event['message'])) {
                [$userMessage, $botMessage] = $this->handleMessage(
                    $event['message'],
                    $conversation,
                    $aiService,
                    $senderId,
                    $isHandover,
                    $isNewConversation
                );
            } elseif (isset($event['postback'])) {
                [$userMessage, $botMessage] = $this->handlePostback(
                    $event['postback'],
                    $conversation,
                    $aiService,
                    $senderId,
                    $isHandover,
                    $isNewConversation
                );
            }
        });

        // Broadcast AFTER transaction commits
        if ($conversation) {
            $conversation->refresh();
            $conversationData = [
                'id' => $conversation->id,
                'message_count' => $conversation->message_count,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'unread_count' => $conversation->unread_count,
            ];

            // Mark lead recovery as responded when customer sends a message
            app(LeadRecoveryService::class)->markCustomerResponded($conversation);
        }
        if ($userMessage) {
            broadcast(new MessageSent($userMessage, $conversationData ?? null))->toOthers();
        }
        if ($botMessage) {
            broadcast(new MessageSent($botMessage, $conversationData ?? null))->toOthers();
        }
        if ($conversation) {
            $updateType = $isNewConversation ? 'created' : 'message_received';
            broadcast(new ConversationUpdated($conversation, $updateType))->toOthers();
        }
    }

    /**
     * Handle a message event.
     *
     * @return array{0: Message|null, 1: Message|null}
     */
    protected function handleMessage(
        array $message,
        Conversation $conversation,
        AIService $aiService,
        string $senderId,
        bool $isHandover,
        bool $isNewConversation
    ): array {
        $messageId = $message['mid'] ?? null;

        // Check for duplicate message
        if ($messageId && Message::where('conversation_id', $conversation->id)
            ->where('external_message_id', $messageId)
            ->exists()) {
            Log::info('Duplicate Facebook message ignored', [
                'conversation_id' => $conversation->id,
                'message_id' => $messageId,
            ]);

            return [null, null];
        }

        // Extract message content and type
        $text = $message['text'] ?? null;
        $attachments = $message['attachments'] ?? [];
        $messageType = 'text';
        $mediaUrl = null;
        $mediaType = null;
        $mediaMetadata = null;

        // Process attachments if present
        if (! empty($attachments)) {
            $attachment = $attachments[0]; // Process first attachment
            $attachmentType = $attachment['type'] ?? 'unknown';
            $payload = $attachment['payload'] ?? [];

            $messageType = $this->mapAttachmentType($attachmentType);
            $mediaUrl = $payload['url'] ?? null;
            $mediaMetadata = [
                'attachment_type' => $attachmentType,
                'sticker_id' => $payload['sticker_id'] ?? null,
                'coordinates' => $payload['coordinates'] ?? null,
            ];

            // Generate placeholder text for non-text messages
            if (! $text) {
                $text = $this->generateAttachmentPlaceholder($attachmentType, $mediaMetadata);
            }
        }

        // Save user message
        $userMessage = $conversation->messages()->create([
            'sender' => 'user',
            'content' => $text,
            'type' => $messageType,
            'media_url' => $mediaUrl,
            'media_type' => $mediaType,
            'media_metadata' => $mediaMetadata,
            'external_message_id' => $messageId,
        ]);

        // Update conversation stats
        $conversation->update([
            'unread_count' => DB::raw('unread_count + 1'),
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => now(),
            'last_message_id' => $userMessage->id,
        ]);

        // Update bot stats
        $botUpdate = [
            'total_messages' => DB::raw('total_messages + 1'),
            'last_active_at' => now(),
        ];
        if ($isNewConversation) {
            $botUpdate['total_conversations'] = DB::raw('total_conversations + 1');
        }
        $this->bot->update($botUpdate);

        // Generate AI response if not in handover mode, bot is active, and is a text message
        $botMessage = null;
        if (! $isHandover && $this->bot->status === 'active' && $messageType === 'text' && $text) {
            $botMessage = $this->generateAIResponse(
                $conversation,
                $userMessage,
                $aiService,
                $senderId
            );

            // Update stats for bot message
            if ($botMessage) {
                $conversation->update([
                    'message_count' => DB::raw('message_count + 1'),
                    'last_message_at' => now(),
                    'last_message_id' => $botMessage->id,
                ]);
                $this->bot->update([
                    'total_messages' => DB::raw('total_messages + 1'),
                ]);
            }
        }

        return [$userMessage, $botMessage];
    }

    /**
     * Handle a postback event.
     *
     * @return array{0: Message|null, 1: Message|null}
     */
    protected function handlePostback(
        array $postback,
        Conversation $conversation,
        AIService $aiService,
        string $senderId,
        bool $isHandover,
        bool $isNewConversation
    ): array {
        $payload = $postback['payload'] ?? '';
        $title = $postback['title'] ?? '';

        // Use title as message content, fall back to payload
        $content = $title ?: $payload;

        // Save postback as user message
        $userMessage = $conversation->messages()->create([
            'sender' => 'user',
            'content' => $content,
            'type' => 'postback',
            'media_metadata' => ['postback_payload' => $payload],
        ]);

        // Update conversation stats
        $conversation->update([
            'unread_count' => DB::raw('unread_count + 1'),
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => now(),
            'last_message_id' => $userMessage->id,
        ]);

        // Update bot stats
        $botUpdate = [
            'total_messages' => DB::raw('total_messages + 1'),
            'last_active_at' => now(),
        ];
        if ($isNewConversation) {
            $botUpdate['total_conversations'] = DB::raw('total_conversations + 1');
        }
        $this->bot->update($botUpdate);

        // Generate AI response for postbacks if not in handover mode and bot is active
        $botMessage = null;
        if (! $isHandover && $this->bot->status === 'active') {
            $botMessage = $this->generateAIResponse(
                $conversation,
                $userMessage,
                $aiService,
                $senderId
            );

            if ($botMessage) {
                $conversation->update([
                    'message_count' => DB::raw('message_count + 1'),
                    'last_message_at' => now(),
                    'last_message_id' => $botMessage->id,
                ]);
                $this->bot->update([
                    'total_messages' => DB::raw('total_messages + 1'),
                ]);
            }
        }

        return [$userMessage, $botMessage];
    }

    /**
     * Create a new conversation.
     */
    protected function createNewConversation(string $psid): Conversation
    {
        // Create or update customer profile
        $customerProfile = $this->findOrCreateCustomerProfile($psid);

        // Check if bot has auto_handover enabled
        $autoHandover = $this->bot->auto_handover ?? false;

        // Create new conversation
        $conversation = Conversation::create([
            'bot_id' => $this->bot->id,
            'customer_profile_id' => $customerProfile?->id,
            'external_customer_id' => $psid,
            'channel_type' => 'facebook',
            'status' => $autoHandover ? 'handover' : 'active',
            'is_handover' => $autoHandover,
            'message_count' => 0,
        ]);

        // Auto-assign conversation if enabled
        $autoAssignment = app(AutoAssignmentService::class);
        $assignedUser = $autoAssignment->assignConversation($this->bot, $conversation);

        if ($assignedUser) {
            $conversation->update(['assigned_user_id' => $assignedUser->id]);
        }

        Log::info('New Facebook conversation created', [
            'conversation_id' => $conversation->id,
            'psid' => $psid,
        ]);

        return $conversation;
    }

    /**
     * Find or create customer profile.
     */
    protected function findOrCreateCustomerProfile(string $psid): ?CustomerProfile
    {
        // Try to find existing profile
        $profile = CustomerProfile::where('external_id', $psid)
            ->where('channel_type', 'facebook')
            ->first();

        if ($profile) {
            // Update last interaction
            $profile->update([
                'last_interaction_at' => now(),
                'interaction_count' => DB::raw('interaction_count + 1'),
            ]);

            return $profile;
        }

        // Fetch profile from Facebook Graph API
        $fbProfile = $this->fetchFacebookProfile($psid);

        // Create new profile with race condition handling
        try {
            return CustomerProfile::create([
                'external_id' => $psid,
                'channel_type' => 'facebook',
                'display_name' => $fbProfile['name'] ?? 'Facebook User',
                'picture_url' => app(ProfilePictureService::class)->downloadAndStore(
                    'facebook', $psid, $fbProfile['profile_pic'] ?? null
                ),
                'first_interaction_at' => now(),
                'last_interaction_at' => now(),
                'interaction_count' => 1,
                'metadata' => [
                    'first_name' => $fbProfile['first_name'] ?? null,
                    'last_name' => $fbProfile['last_name'] ?? null,
                ],
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Race condition: another job created the profile, query again
            return CustomerProfile::where('external_id', $psid)
                ->where('channel_type', 'facebook')
                ->first();
        }
    }

    /**
     * Fetch user profile from Facebook Graph API.
     */
    protected function fetchFacebookProfile(string $psid): array
    {
        try {
            $accessToken = $this->bot->channel_access_token;

            if (! $accessToken) {
                Log::warning('No Facebook access token configured', [
                    'bot_id' => $this->bot->id,
                ]);

                return [];
            }

            $response = Http::get("https://graph.facebook.com/v19.0/{$psid}", [
                'fields' => 'first_name,last_name,name,profile_pic',
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Failed to fetch Facebook profile', [
                'bot_id' => $this->bot->id,
                'psid' => $psid,
                'status' => $response->status(),
                'error' => $response->json()['error'] ?? null,
            ]);

            return [];

        } catch (\Exception $e) {
            Log::warning('Exception fetching Facebook profile', [
                'bot_id' => $this->bot->id,
                'psid' => $psid,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Generate AI response and send to Facebook.
     */
    protected function generateAIResponse(
        Conversation $conversation,
        Message $userMessage,
        AIService $aiService,
        string $recipientId
    ): ?Message {
        try {
            // Show typing indicator
            $this->sendTypingIndicator($recipientId, 'typing_on');

            // Generate AI response
            $botMessage = $aiService->generateAndSaveResponse(
                $this->bot,
                $conversation,
                $userMessage
            );

            // Send response to Facebook
            if ($botMessage && $botMessage->content) {
                $this->sendFacebookMessage($recipientId, $botMessage->content);

                Log::info('Facebook AI response sent', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $botMessage->id,
                ]);
            }

            // Turn off typing indicator
            $this->sendTypingIndicator($recipientId, 'typing_off');

            return $botMessage;

        } catch (\Exception $e) {
            Log::error('Failed to generate AI response for Facebook', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Send a message to Facebook Messenger.
     */
    protected function sendFacebookMessage(string $recipientId, string $text): bool
    {
        try {
            $accessToken = $this->bot->channel_access_token;

            if (! $accessToken) {
                Log::error('No Facebook access token configured', [
                    'bot_id' => $this->bot->id,
                ]);

                return false;
            }

            $response = Http::post('https://graph.facebook.com/v19.0/me/messages', [
                'recipient' => ['id' => $recipientId],
                'messaging_type' => 'RESPONSE',
                'message' => ['text' => $text],
                'access_token' => $accessToken,
            ]);

            if (! $response->successful()) {
                Log::error('Failed to send Facebook message', [
                    'bot_id' => $this->bot->id,
                    'recipient_id' => $recipientId,
                    'status' => $response->status(),
                    'error' => $response->json()['error'] ?? null,
                ]);

                return false;
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Exception sending Facebook message', [
                'bot_id' => $this->bot->id,
                'recipient_id' => $recipientId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send typing indicator to Facebook Messenger.
     */
    protected function sendTypingIndicator(string $recipientId, string $action): void
    {
        try {
            $accessToken = $this->bot->channel_access_token;

            if (! $accessToken) {
                return;
            }

            Http::post('https://graph.facebook.com/v19.0/me/messages', [
                'recipient' => ['id' => $recipientId],
                'sender_action' => $action,
                'access_token' => $accessToken,
            ]);

        } catch (\Exception $e) {
            // Silently ignore typing indicator failures
            Log::debug('Failed to send typing indicator', [
                'bot_id' => $this->bot->id,
                'recipient_id' => $recipientId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map Facebook attachment type to our message type.
     */
    protected function mapAttachmentType(string $attachmentType): string
    {
        return match ($attachmentType) {
            'image' => 'image',
            'video' => 'video',
            'audio' => 'audio',
            'file' => 'file',
            'location' => 'location',
            'fallback' => 'text', // URL previews, shared posts, etc.
            default => 'attachment',
        };
    }

    /**
     * Generate placeholder content for attachment messages.
     */
    protected function generateAttachmentPlaceholder(string $type, ?array $metadata): string
    {
        return match ($type) {
            'image' => '[Image]',
            'video' => '[Video]',
            'audio' => '[Audio]',
            'file' => '[File]',
            'location' => isset($metadata['coordinates'])
                ? "[Location: {$metadata['coordinates']['lat']}, {$metadata['coordinates']['long']}]"
                : '[Location shared]',
            'sticker' => isset($metadata['sticker_id'])
                ? "[Sticker #{$metadata['sticker_id']}]"
                : '[Sticker]',
            'fallback' => '[Shared content]',
            default => '[Attachment]',
        };
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Facebook webhook job failed permanently', [
            'bot_id' => $this->bot->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
