<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerProfileResource extends JsonResource
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
            'external_id' => $this->external_id,
            'channel_type' => $this->channel_type,
            'display_name' => $this->display_name,
            'picture_url' => $this->picture_url,
            'phone' => $this->phone,
            'email' => $this->email,
            'interaction_count' => $this->interaction_count,
            'first_interaction_at' => $this->first_interaction_at?->toIso8601String(),
            'last_interaction_at' => $this->last_interaction_at?->toIso8601String(),
            'metadata' => $this->metadata,
            'tags' => $this->tags ?? [],
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
