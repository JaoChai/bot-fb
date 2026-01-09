<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImprovementSessionResource extends JsonResource
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
            'evaluation_id' => $this->evaluation_id,
            'flow_id' => $this->flow_id,
            'bot_id' => $this->bot_id,
            'status' => $this->status,
            'analysis_summary' => $this->analysis_summary,
            'before_score' => $this->before_score,
            'after_score' => $this->after_score,
            'score_improvement' => $this->score_improvement,
            're_evaluation_id' => $this->re_evaluation_id,
            'agent_model' => $this->agent_model,
            'total_tokens_used' => $this->total_tokens_used,
            'estimated_cost' => $this->estimated_cost,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),

            // Counts
            'suggestions_count' => $this->whenCounted('suggestions'),
            'selected_suggestions_count' => $this->when(
                $this->relationLoaded('suggestions'),
                fn() => $this->suggestions->where('is_selected', true)->count()
            ),

            // Relations
            'evaluation' => $this->whenLoaded('evaluation', function () {
                return [
                    'id' => $this->evaluation->id,
                    'name' => $this->evaluation->name,
                    'overall_score' => $this->evaluation->overall_score,
                    'status' => $this->evaluation->status,
                ];
            }),
            're_evaluation' => $this->whenLoaded('reEvaluation', function () {
                return $this->reEvaluation ? [
                    'id' => $this->reEvaluation->id,
                    'name' => $this->reEvaluation->name,
                    'overall_score' => $this->reEvaluation->overall_score,
                    'status' => $this->reEvaluation->status,
                ] : null;
            }),
            'suggestions' => ImprovementSuggestionResource::collection(
                $this->whenLoaded('suggestions')
            ),
        ];
    }
}
