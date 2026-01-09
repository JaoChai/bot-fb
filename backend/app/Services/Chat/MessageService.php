<?php

namespace App\Services\Chat;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\LINEService;
use App\Services\TelegramService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageService
{
    public function __construct(
        private LINEService $lineService,
        private TelegramService $telegramService
    ) {}

    /**
     * Get messages for a conversation with pagination.
     */
    public function getMessages(
        Conversation $conversation,
        int $perPage = 50,
        string $order = 'desc'
    ): LengthAwarePaginator {
        // Enforce maximum limit of 100 messages per page
        $perPage = min($perPage, 100);

        return $conversation->messages()
            ->orderBy('created_at', $order)
            ->paginate($perPage);
    }

    /**
     * Send a message from agent to customer (HITL).
     *
     * @return array{message: Message, delivery_error: string|null}
     * @throws \InvalidArgumentException If conversation is not in handover mode
     */
    public function sendAgentMessage(
        Bot $bot,
        Conversation $conversation,
        array $data
    ): array {
        if (!$conversation->is_handover) {
            throw new \InvalidArgumentException('Conversation must be in handover mode to send agent messages');
        }

        $sendError = null;

        // Use transaction for data consistency
        $message = DB::transaction(function () use ($data, $conversation) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender' => 'agent',
                'content' => $data['content'],
                'type' => $data['type'] ?? 'text',
                'media_url' => $data['media_url'] ?? null,
            ]);

            // Update conversation stats atomically
            $conversation->update(['last_message_at' => now()]);
            $conversation->increment('message_count');

            return $message;
        });

        // Send to customer via channel (outside transaction - external API call)
        try {
            $this->sendToChannel($bot, $conversation, $data);
        } catch (\Exception $e) {
            Log::error('Failed to send agent message to customer', [
                'conversation_id' => $conversation->id,
                'bot_id' => $bot->id,
                'channel_type' => $conversation->channel_type,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
            ]);
            $sendError = 'Failed to deliver message to customer';
        }

        // Reload conversation with updated stats for broadcast
        $conversation->refresh();
        $conversationData = [
            'id' => $conversation->id,
            'message_count' => $conversation->message_count,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'unread_count' => $conversation->unread_count,
        ];

        // Broadcast the message for real-time updates
        broadcast(new MessageSent($message, $conversationData))->toOthers();
        broadcast(new ConversationUpdated($conversation))->toOthers();

        return [
            'message' => $message,
            'delivery_error' => $sendError,
        ];
    }

    /**
     * Mark a conversation as read (reset unread count).
     */
    public function markAsRead(Conversation $conversation): Conversation
    {
        if ($conversation->unread_count > 0) {
            $conversation->update(['unread_count' => 0]);
            broadcast(new ConversationUpdated($conversation))->toOthers();
        }

        $conversation->load(['customerProfile']);

        return $conversation;
    }

    /**
     * Send message to the appropriate channel.
     */
    private function sendToChannel(Bot $bot, Conversation $conversation, array $data): void
    {
        $type = $data['type'] ?? 'text';
        $mediaUrl = $data['media_url'] ?? null;

        if ($conversation->channel_type === 'line') {
            $this->sendToLine($bot, $conversation, $type, $data['content'], $mediaUrl);
        } elseif ($conversation->channel_type === 'telegram') {
            $this->sendToTelegram($bot, $conversation, $type, $data['content'], $mediaUrl);
        }
    }

    /**
     * Send message via LINE.
     */
    private function sendToLine(
        Bot $bot,
        Conversation $conversation,
        string $type,
        string $content,
        ?string $mediaUrl
    ): void {
        $userId = $conversation->external_customer_id;

        match ($type) {
            'photo', 'image' => $mediaUrl
                ? $this->lineService->push($bot, $userId, [$this->lineService->imageMessage($mediaUrl)])
                : $this->lineService->push($bot, $userId, [$this->lineService->textMessage($content)]),
            'video' => $mediaUrl
                ? $this->lineService->push($bot, $userId, [$this->lineService->videoMessage($mediaUrl)])
                : $this->lineService->push($bot, $userId, [$this->lineService->textMessage($content)]),
            'audio', 'voice' => $mediaUrl
                ? $this->lineService->push($bot, $userId, [$this->lineService->audioMessage($mediaUrl)])
                : $this->lineService->push($bot, $userId, [$this->lineService->textMessage($content)]),
            default => $this->lineService->push($bot, $userId, [$this->lineService->textMessage($content)]),
        };
    }

    /**
     * Send message via Telegram.
     */
    private function sendToTelegram(
        Bot $bot,
        Conversation $conversation,
        string $type,
        string $content,
        ?string $mediaUrl
    ): void {
        $chatId = $conversation->external_customer_id;

        match ($type) {
            'photo', 'image' => $mediaUrl
                ? $this->telegramService->sendPhoto($bot, $chatId, $mediaUrl, $content ?: null)
                : $this->telegramService->sendMessage($bot, $chatId, $content),
            'video' => $mediaUrl
                ? $this->telegramService->sendVideo($bot, $chatId, $mediaUrl, $content ?: null)
                : $this->telegramService->sendMessage($bot, $chatId, $content),
            'document', 'file' => $mediaUrl
                ? $this->telegramService->sendDocument($bot, $chatId, $mediaUrl, $content ?: null)
                : $this->telegramService->sendMessage($bot, $chatId, $content),
            'voice' => $mediaUrl
                ? $this->telegramService->sendVoice($bot, $chatId, $mediaUrl)
                : $this->telegramService->sendMessage($bot, $chatId, $content),
            default => $this->telegramService->sendMessage($bot, $chatId, $content),
        };
    }
}
