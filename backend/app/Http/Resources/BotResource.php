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

            // Channel credentials (for debugging)
            'channel_access_token' => $this->channel_access_token,
            'channel_secret' => $this->channel_secret,

            // LLM Settings (legacy)
            'llm_model' => $this->llm_model,
            'llm_fallback_model' => $this->llm_fallback_model,
            'system_prompt' => $this->system_prompt,
            'llm_temperature' => $this->llm_temperature,
            'llm_max_tokens' => $this->llm_max_tokens,
            'context_window' => $this->context_window,

            // Multi-model LLM configuration
            'primary_chat_model' => $this->primary_chat_model,
            'fallback_chat_model' => $this->fallback_chat_model,
            'decision_model' => $this->decision_model,
            'fallback_decision_model' => $this->fallback_decision_model,

            // Webhook forwarder
            'webhook_forwarder_enabled' => $this->webhook_forwarder_enabled ?? false,

            // Knowledge Base (RAG) Settings
            'kb_enabled' => $this->kb_enabled ?? false,
            'kb_relevance_threshold' => $this->kb_relevance_threshold ?? 0.7,
            'kb_max_results' => $this->kb_max_results ?? 3,

            // Auto handover setting
            'auto_handover' => $this->auto_handover ?? false,

            // Stats
            'total_conversations' => $this->total_conversations ?? 0,
            'total_messages' => $this->total_messages ?? 0,
            'last_active_at' => $this->last_active_at?->toISOString(),

            // Relationships
            'settings' => $this->whenLoaded('settings'),
            'default_flow' => $this->whenLoaded('defaultFlow'),
            'knowledge_base' => new KnowledgeBaseResource($this->whenLoaded('knowledgeBase')),

            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
