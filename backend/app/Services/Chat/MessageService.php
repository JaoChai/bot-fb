<?php

namespace App\Services\Chat;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Channel\ChannelAdapterFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageService
{
    public function __construct(
        private ChannelAdapterFactory $channelFactory
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
     *
     * @throws \InvalidArgumentException If conversation is not in handover mode
     */
    public function sendAgentMessage(
        Bot $bot,
        Conversation $conversation,
        array $data
    ): array {
        if (! $conversation->is_handover) {
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
        // Note: This always fires regardless of external API result (sendToChannel)
        try {
            broadcast(new MessageSent($message, $conversationData))->toOthers();
            broadcast(new ConversationUpdated($conversation))->toOthers();

            Log::debug('Agent message broadcast sent', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);
        } catch (\Exception $e) {
            // Broadcast failure shouldn't prevent successful response
            Log::error('Failed to broadcast agent message', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

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

            try {
                broadcast(new ConversationUpdated($conversation))->toOthers();
            } catch (\Exception $e) {
                Log::error('Failed to broadcast mark as read', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $conversation->load(['customerProfile', 'assignedUser']);

        return $conversation;
    }

    /**
     * Send message to the appropriate channel using adapter pattern.
     */
    private function sendToChannel(Bot $bot, Conversation $conversation, array $data): void
    {
        $channelType = $conversation->channel_type;

        // Skip if channel not supported (e.g., facebook not yet implemented)
        if (! $this->channelFactory->supports($channelType)) {
            Log::warning('Unsupported channel type for message sending', [
                'channel_type' => $channelType,
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        $adapter = $this->channelFactory->make($channelType);

        $adapter->sendMessage(
            $bot,
            $conversation,
            $data['type'] ?? 'text',
            $data['content'],
            $data['media_url'] ?? null
        );
    }
}
