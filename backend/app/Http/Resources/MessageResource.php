<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender' => $this->sender,
            'content' => $this->content,
            'type' => $this->type,
            'media_url' => $this->media_url,
            'media_type' => $this->media_type,
            'media_metadata' => $this->media_metadata,
            'model_used' => $this->model_used,
            'prompt_tokens' => $this->prompt_tokens,
            'completion_tokens' => $this->completion_tokens,
            'cost' => $this->cost ? (float) $this->cost : null,
            'external_message_id' => $this->external_message_id,
            'reply_to_message_id' => $this->reply_to_message_id,
            'sentiment' => $this->sentiment,
            'intents' => $this->intents,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
