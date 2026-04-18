<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bot_id' => $this->bot_id,
            'name' => $this->name,
            'description' => $this->description,

            // AI Configuration
            'system_prompt' => $this->system_prompt,
            'temperature' => (float) $this->temperature,
            'max_tokens' => $this->max_tokens,

            // Knowledge Bases (Many-to-Many)
            'knowledge_bases' => $this->whenLoaded('knowledgeBases', function () {
                return $this->knowledgeBases->map(fn ($kb) => [
                    'id' => $kb->id,
                    'name' => $kb->name,
                    'kb_top_k' => $kb->pivot->kb_top_k,
                    'kb_similarity_threshold' => (float) $kb->pivot->kb_similarity_threshold,
                ]);
            }),

            // Settings
            'is_default' => $this->is_default,

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
