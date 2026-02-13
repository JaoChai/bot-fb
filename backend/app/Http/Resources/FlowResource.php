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
            'fallback_model' => $this->fallback_model,
            'decision_model' => $this->decision_model,
            'fallback_decision_model' => $this->fallback_decision_model,
            'temperature' => (float) $this->temperature,
            'max_tokens' => $this->max_tokens,

            // Agentic mode
            'agentic_mode' => $this->agentic_mode,
            'max_tool_calls' => $this->max_tool_calls,
            'enabled_tools' => $this->enabled_tools ?? [],

            // Agent Safety
            'agent_timeout_seconds' => $this->agent_timeout_seconds,
            'agent_max_cost_per_request' => $this->agent_max_cost_per_request,
            'hitl_enabled' => $this->hitl_enabled,
            'hitl_dangerous_actions' => $this->hitl_dangerous_actions ?? [],

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
            'language' => $this->language,
            'is_default' => $this->is_default,

            // Second AI
            'second_ai_enabled' => $this->second_ai_enabled ?? false,
            'second_ai_options' => $this->second_ai_options ?? [
                'fact_check' => false,
                'policy' => false,
                'personality' => false,
            ],

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
