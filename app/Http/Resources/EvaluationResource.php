<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationResource extends JsonResource
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
            'bot_id' => $this->bot_id,
            'flow_id' => $this->flow_id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'judge_model' => $this->judge_model,
            'personas' => $this->personas,
            'config' => $this->config,

            // Scores
            'overall_score' => $this->overall_score,
            'metric_scores' => $this->metric_scores,

            // Progress
            'progress' => [
                'total_test_cases' => $this->total_test_cases,
                'completed_test_cases' => $this->completed_test_cases,
                'percent' => $this->progress,
            ],

            // Cost
            'total_tokens_used' => $this->total_tokens_used,
            'estimated_cost' => $this->estimated_cost,

            // Timestamps
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),

            // Error (if failed)
            'error_message' => $this->when($this->status === 'failed', $this->error_message),

            // Relationships
            'flow' => $this->whenLoaded('flow', fn() => [
                'id' => $this->flow->id,
                'name' => $this->flow->name,
            ]),
            'report' => $this->whenLoaded('report', fn() => new EvaluationReportResource($this->report)),
        ];
    }
}
