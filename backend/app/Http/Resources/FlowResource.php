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
            'model' => $this->model,
            'temperature' => (float) $this->temperature,
            'max_tokens' => $this->max_tokens,

            // Agentic mode
            'agentic_mode' => $this->agentic_mode,
            'max_tool_calls' => $this->max_tool_calls,
            'enabled_tools' => $this->enabled_tools ?? [],

            // Knowledge Base
            'knowledge_base_id' => $this->knowledge_base_id,
            'knowledge_base' => $this->whenLoaded('knowledgeBase', function () {
                return [
                    'id' => $this->knowledgeBase->id,
                    'name' => $this->knowledgeBase->name,
                ];
            }),
            'kb_top_k' => $this->kb_top_k,
            'kb_similarity_threshold' => (float) $this->kb_similarity_threshold,

            // Settings
            'language' => $this->language,
            'is_default' => $this->is_default,

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
