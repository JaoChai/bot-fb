<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Slim resource for flow list endpoint.
 * Excludes large fields (system_prompt) to reduce payload size.
 * Use FlowResource for detail/edit endpoints that need full data.
 */
class FlowListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bot_id' => $this->bot_id,
            'name' => $this->name,
            'description' => $this->description ? Str::limit($this->description, 100) : null,

            // Knowledge Bases count only (no full KB data)
            'knowledge_bases_count' => $this->whenLoaded('knowledgeBases', fn () => $this->knowledgeBases->count(), 0),

            // Settings
            'is_default' => $this->is_default,

            // Timestamps
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
