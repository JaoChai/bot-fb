<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdminNotification implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public int $userId,
        public string $type,
        public string $title,
        public string $message,
        public array $data = []
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->userId.'.notifications'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'notification';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'data' => $this->data,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Create a handover request notification.
     */
    public static function handoverRequest(int $userId, int $conversationId, string $customerName): self
    {
        return new self(
            userId: $userId,
            type: 'handover_request',
            title: 'Human Handover Request',
            message: "Customer {$customerName} requested human support",
            data: ['conversation_id' => $conversationId]
        );
    }

    /**
     * Create a new conversation notification.
     */
    public static function newConversation(int $userId, int $botId, string $botName): self
    {
        return new self(
            userId: $userId,
            type: 'new_conversation',
            title: 'New Conversation',
            message: "New conversation started on {$botName}",
            data: ['bot_id' => $botId]
        );
    }
}
