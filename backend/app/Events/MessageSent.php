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
     * Create a new event instance.
     */
    public function __construct(
        public Message $message
    ) {}

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
        $conversation = $this->message->conversation;

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
            // Conversation update data for single-event updates
            'conversation' => [
                'id' => $conversation->id,
                'message_count' => $conversation->message_count,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
            ],
        ];
    }
}
