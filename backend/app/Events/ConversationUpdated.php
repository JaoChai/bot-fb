<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The type of update that occurred.
     */
    public string $updateType;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Conversation $conversation,
        string $updateType = 'updated'
    ) {
        $this->updateType = $updateType;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->conversation->id),
            new PrivateChannel('bot.'.$this->conversation->bot_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'conversation.'.$this->updateType;
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->conversation->id,
            'bot_id' => $this->conversation->bot_id,
            'status' => $this->conversation->status,
            'is_handover' => $this->conversation->is_handover,
            'assigned_user_id' => $this->conversation->assigned_user_id,
            'message_count' => $this->conversation->message_count,
            'last_message_at' => $this->conversation->last_message_at?->toISOString(),
            'needs_response' => $this->conversation->needs_response,
            'unread_count' => $this->conversation->unread_count,
            'update_type' => $this->updateType,
            'updated_at' => $this->conversation->updated_at->toISOString(),
        ];
    }
}
