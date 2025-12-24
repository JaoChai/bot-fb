<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'channel_type' => $this->channel_type,
            'page_id' => $this->page_id,
            'webhook_url' => $this->webhook_url,
            'total_conversations' => $this->total_conversations ?? 0,
            'total_messages' => $this->total_messages ?? 0,
            'last_active_at' => $this->last_active_at?->toISOString(),
            'settings' => $this->whenLoaded('settings'),
            'default_flow' => $this->whenLoaded('defaultFlow'),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
