<?php

namespace App\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Services\AIService;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessTelegramWebhook implements ShouldQueue
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
        public array $update
    ) {}

    /**
     * Execute the job.
     */
    public function handle(TelegramService $telegramService, AIService $aiService): void
    {
        try {
            $this->processUpdate($telegramService, $aiService);
        } catch (\Exception $e) {
            Log::error('Telegram webhook processing failed', [
                'bot_id' => $this->bot->id,
                'update_id' => $this->update['update_id'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Process the Telegram update.
     */
    protected function processUpdate(TelegramService $telegramService, AIService $aiService): void
    {
        // Parse the update
        $parsed = $telegramService->parseUpdate($this->update);

        // Only process message updates
        if ($parsed['type'] === 'unknown' || !$parsed['chat_id']) {
            Log::debug('Ignoring non-message update', [
                'update_id' => $parsed['update_id'],
                'type' => $parsed['type'],
            ]);
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
            $telegramService,
            $aiService,
            $parsed,
            &$userMessage,
            &$botMessage,
            &$conversation,
            &$isNewConversation,
            &$isHandover
        ) {
            // Find or create conversation (include handover status for auto_handover bots)
            $existingConversation = Conversation::where('bot_id', $this->bot->id)
                ->where('external_customer_id', $parsed['chat_id'])
                ->where('channel_type', 'telegram')
                ->whereIn('status', ['active', 'handover'])
                ->first();

            $isNewConversation = !$existingConversation;
            $conversation = $existingConversation ?? $this->createNewConversation($parsed, $telegramService);
            $isHandover = $conversation->is_handover;

            // Check for duplicate message
            $messageId = (string) $parsed['message_id'];
            if ($messageId && Message::where('conversation_id', $conversation->id)
                ->where('external_message_id', $messageId)
                ->exists()) {
                Log::info('Duplicate Telegram message ignored', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $messageId,
                ]);
                return;
            }

            // Process media if present
            $mediaData = $this->processMedia($telegramService, $parsed);

            // Determine message content
            $content = $parsed['text'];
            if (!$content && $parsed['type'] !== 'text') {
                // Generate placeholder for non-text messages
                $content = $this->generateMediaPlaceholder($parsed['type'], $mediaData);
            }

            // Save user message
            $userMessage = $conversation->messages()->create([
                'sender' => 'user',
                'content' => $content,
                'type' => $this->mapMessageType($parsed['type']),
                'media_url' => $mediaData['url'] ?? null,
                'media_type' => $mediaData['mime_type'] ?? null,
                'media_metadata' => $mediaData['metadata'] ?? null,
                'external_message_id' => $messageId,
                'reply_to_message_id' => $parsed['reply_to_message_id'],
            ]);

            // Update conversation stats
            $conversation->update([
                'unread_count' => DB::raw('unread_count + 1'),
                'message_count' => DB::raw('message_count + 1'),
                'last_message_at' => now(),
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

            // Generate AI response if not in handover mode and is a text message
            if (!$isHandover && $parsed['type'] === 'text' && $userMessage) {
                $botMessage = $this->generateAIResponse(
                    $conversation,
                    $userMessage,
                    $aiService,
                    $telegramService,
                    $parsed['chat_id']
                );

                // Update stats for bot message
                if ($botMessage) {
                    $conversation->update([
                        'message_count' => DB::raw('message_count + 1'),
                        'last_message_at' => now(),
                    ]);
                    $this->bot->update([
                        'total_messages' => DB::raw('total_messages + 1'),
                    ]);
                }
            }
        });

        // Broadcast AFTER transaction commits
        if ($userMessage) {
            broadcast(new MessageSent($userMessage))->toOthers();
        }
        if ($botMessage) {
            broadcast(new MessageSent($botMessage))->toOthers();
        }
        if ($conversation) {
            $updateType = $isNewConversation ? 'created' : 'message_received';
            broadcast(new ConversationUpdated($conversation->fresh(), $updateType))->toOthers();
        }
    }

    /**
     * Create a new conversation.
     */
    protected function createNewConversation(array $parsed, TelegramService $telegramService): Conversation
    {
        // Create or update customer profile
        $customerProfile = $this->findOrCreateCustomerProfile($parsed, $telegramService);

        // Check if bot has auto_handover enabled
        $autoHandover = $this->bot->auto_handover ?? false;

        // Create new conversation
        $conversation = Conversation::create([
            'bot_id' => $this->bot->id,
            'customer_profile_id' => $customerProfile?->id,
            'external_customer_id' => $parsed['chat_id'],
            'channel_type' => 'telegram',
            'status' => $autoHandover ? 'handover' : 'active',
            'is_handover' => $autoHandover,
            'telegram_chat_type' => $parsed['chat_type'],
            'telegram_chat_title' => $parsed['chat_title'],
            'message_count' => 0,
        ]);

        Log::info('New Telegram conversation created', [
            'conversation_id' => $conversation->id,
            'chat_id' => $parsed['chat_id'],
            'chat_type' => $parsed['chat_type'],
        ]);

        return $conversation;
    }

    /**
     * Process media from the message.
     */
    protected function processMedia(TelegramService $telegramService, array $parsed): array
    {
        if ($parsed['type'] === 'text') {
            return [];
        }

        $rawMessage = $parsed['raw_message'] ?? [];

        // Get file_id
        $fileId = $telegramService->extractFileId($rawMessage);
        if (!$fileId) {
            // For location, contact, poll - extract metadata only
            return [
                'metadata' => $telegramService->extractMediaMetadata($rawMessage),
            ];
        }

        // Download and store the file
        $fileData = $telegramService->downloadAndStoreFile($this->bot, $fileId);

        if (!$fileData) {
            Log::warning('Failed to download Telegram media', [
                'bot_id' => $this->bot->id,
                'file_id' => $fileId,
                'type' => $parsed['type'],
            ]);

            return [
                'metadata' => array_merge(
                    $telegramService->extractMediaMetadata($rawMessage),
                    ['file_id' => $fileId, 'download_failed' => true]
                ),
            ];
        }

        return [
            'url' => $fileData['url'],
            'mime_type' => $fileData['mime_type'],
            'metadata' => array_merge(
                $telegramService->extractMediaMetadata($rawMessage),
                [
                    'file_id' => $fileId,
                    'file_size' => $fileData['file_size'],
                    'storage_path' => $fileData['path'],
                ]
            ),
        ];
    }

    /**
     * Map Telegram message type to our message type.
     */
    protected function mapMessageType(string $telegramType): string
    {
        return match ($telegramType) {
            'photo' => 'image',
            'video', 'video_note', 'animation' => 'video',
            'voice' => 'voice',
            'audio' => 'audio',
            'file' => 'file',
            'sticker' => 'sticker',
            'location' => 'location',
            'contact' => 'contact',
            'poll' => 'poll',
            default => 'text',
        };
    }

    /**
     * Generate placeholder content for non-text messages.
     */
    protected function generateMediaPlaceholder(string $type, array $mediaData): string
    {
        $metadata = $mediaData['metadata'] ?? [];

        return match ($type) {
            'photo' => '[Photo]',
            'video', 'video_note', 'animation' => '[Video]',
            'voice' => '[Voice message]',
            'audio' => isset($metadata['title'])
                ? "[Audio: {$metadata['title']}]"
                : '[Audio]',
            'file' => isset($metadata['file_name'])
                ? "[File: {$metadata['file_name']}]"
                : '[File]',
            'sticker' => isset($metadata['emoji'])
                ? "[Sticker {$metadata['emoji']}]"
                : '[Sticker]',
            'location' => isset($metadata['title'])
                ? "[Location: {$metadata['title']}]"
                : '[Location shared]',
            'contact' => isset($metadata['first_name'])
                ? "[Contact: {$metadata['first_name']}]"
                : '[Contact shared]',
            'poll' => isset($metadata['question'])
                ? "[Poll: {$metadata['question']}]"
                : '[Poll]',
            default => '[Media]',
        };
    }

    /**
     * Find or create customer profile.
     */
    protected function findOrCreateCustomerProfile(array $parsed, TelegramService $telegramService): ?CustomerProfile
    {
        // For group chats, use chat_id; for private chats, use user_id
        $externalId = $parsed['chat_type'] === 'private'
            ? $parsed['user_id']
            : $parsed['chat_id'];

        // Try to find existing profile
        $profile = CustomerProfile::where('external_id', $externalId)
            ->where('channel_type', 'telegram')
            ->first();

        if ($profile) {
            // Update last interaction
            $profile->update([
                'last_interaction_at' => now(),
                'interaction_count' => DB::raw('interaction_count + 1'),
            ]);
            return $profile;
        }

        // Determine display name
        $displayName = $parsed['chat_type'] === 'private'
            ? trim(($parsed['first_name'] ?? '') . ' ' . ($parsed['last_name'] ?? ''))
            : $parsed['chat_title'];

        if (!$displayName) {
            $displayName = $parsed['username'] ?? null;
        }

        // Create new profile
        return CustomerProfile::create([
            'external_id' => $externalId,
            'channel_type' => 'telegram',
            'display_name' => $displayName ?: 'Telegram User',
            'picture_url' => $this->fetchUserProfilePhoto($parsed, $telegramService),
            'first_interaction_at' => now(),
            'last_interaction_at' => now(),
            'interaction_count' => 1,
            'metadata' => [
                'username' => $parsed['username'],
                'user_id' => $parsed['user_id'],
                'chat_type' => $parsed['chat_type'],
                'chat_title' => $parsed['chat_title'],
            ],
        ]);
    }

    /**
     * Fetch user profile photo from Telegram API.
     * Only fetches for private chats (individual users).
     */
    protected function fetchUserProfilePhoto(array $parsed, TelegramService $telegramService): ?string
    {
        // Only fetch for private chats (individual users)
        if ($parsed['chat_type'] !== 'private') {
            return null;
        }

        $userId = $parsed['user_id'] ?? null;
        if (!$userId) {
            return null;
        }

        return $telegramService->getUserProfilePhoto($this->bot, $userId);
    }

    /**
     * Generate AI response for non-handover conversations.
     */
    protected function generateAIResponse(
        Conversation $conversation,
        Message $userMessage,
        AIService $aiService,
        TelegramService $telegramService,
        string $chatId
    ): ?Message {
        try {
            // Generate AI response using the same service as LINE
            $botMessage = $aiService->generateAndSaveResponse(
                $this->bot,
                $conversation,
                $userMessage
            );

            // Send response to Telegram
            if ($botMessage && $botMessage->content) {
                $telegramService->sendMessage(
                    $this->bot,
                    $chatId,
                    $botMessage->content
                );

                Log::info('Telegram AI response sent', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $botMessage->id,
                ]);
            }

            return $botMessage;

        } catch (\Exception $e) {
            Log::error('Failed to generate AI response for Telegram', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Telegram webhook job failed permanently', [
            'bot_id' => $this->bot->id,
            'update_id' => $this->update['update_id'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
