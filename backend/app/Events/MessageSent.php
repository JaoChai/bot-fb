<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Conversation data captured at dispatch time to avoid race conditions.
     * Since broadcasts are queued, calling fresh() in broadcastWith() would get
     * the current DB value which may have changed (e.g., after markAsRead).
     */
    public array $conversationData;

    /**
     * Create a new event instance.
     *
     * @param Message $message The message being sent
     * @param array|null $conversationData Pre-captured conversation data (optional, will fetch fresh if not provided)
     */
    public function __construct(
        public Message $message,
        ?array $conversationData = null
    ) {
        // Capture conversation data NOW (at dispatch time) to avoid race conditions
        // When queue job runs later, the DB value may have changed
        if ($conversationData !== null) {
            $this->conversationData = $conversationData;
        } else {
            $conversation = $this->message->conversation()->first();
            $this->conversationData = [
                'id' => $conversation->id,
                'message_count' => $conversation->message_count,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'unread_count' => $conversation->unread_count,
            ];
        }
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->message->conversation_id),
            new PrivateChannel('bot.'.$this->message->conversation->bot_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * Get the data to broadcast.
     * Includes conversation update data to reduce double broadcasts.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            // Message data
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender' => $this->message->sender,
            'content' => $this->message->content,
            'type' => $this->message->type,
            'media_url' => $this->message->media_url,
            'media_type' => $this->message->media_type,
            'created_at' => $this->message->created_at->toISOString(),
            // Conversation data captured at dispatch time (not fresh() to avoid race conditions)
            'conversation' => [
                'id' => $this->conversationData['id'],
                'message_count' => $this->conversationData['message_count'],
                'last_message_at' => $this->conversationData['last_message_at'],
                'needs_response' => $this->message->sender === 'user',
                'unread_count' => $this->conversationData['unread_count'],
            ],
        ];
    }
}
