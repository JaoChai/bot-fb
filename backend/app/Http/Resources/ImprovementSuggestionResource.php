<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImprovementSuggestionResource extends JsonResource
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
            'session_id' => $this->session_id,
            'type' => $this->type,
            'priority' => $this->priority,
            'priority_variant' => $this->getPriorityVariant(),
            'confidence_score' => $this->confidence_score,
            'title' => $this->title,
            'description' => $this->description,
            'is_selected' => $this->is_selected,
            'is_applied' => $this->is_applied,
            'applied_at' => $this->applied_at?->toISOString(),
            'source_metric' => $this->source_metric,
            'source_test_case_ids' => $this->source_test_case_ids,
            'created_at' => $this->created_at->toISOString(),

            // System prompt specific
            'current_value' => $this->when(
                $this->type === 'system_prompt',
                $this->current_value
            ),
            'suggested_value' => $this->when(
                $this->type === 'system_prompt',
                $this->suggested_value
            ),
            'diff_summary' => $this->when(
                $this->type === 'system_prompt',
                $this->diff_summary
            ),

            // KB content specific
            'target_knowledge_base_id' => $this->when(
                $this->type === 'kb_content',
                $this->target_knowledge_base_id
            ),
            'kb_content_title' => $this->when(
                $this->type === 'kb_content',
                $this->kb_content_title
            ),
            'kb_content_body' => $this->when(
                $this->type === 'kb_content',
                $this->kb_content_body
            ),
            'related_topics' => $this->when(
                $this->type === 'kb_content',
                $this->related_topics
            ),
        ];
    }
}
