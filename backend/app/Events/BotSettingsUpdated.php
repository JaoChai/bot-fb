<?php

namespace App\Events;

use App\Models\Bot;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BotSettingsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Bot $bot,
        public string $settingType = 'qa_inspector'
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('bot.' . $this->bot->id)];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'settings.updated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'bot_id' => $this->bot->id,
            'setting_type' => $this->settingType,
            'qa_inspector_enabled' => $this->bot->qa_inspector_enabled,
            'updated_at' => now()->toISOString(),
        ];
    }
}
