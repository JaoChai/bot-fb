<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QAWeeklyReportDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'week_start' => $this->week_start?->toDateString(),
            'week_end' => $this->week_end?->toDateString(),
            'status' => $this->status,
            'performance_summary' => $this->performance_summary,
            'top_issues' => $this->top_issues,
            'prompt_suggestions' => $this->prompt_suggestions,
            'total_conversations' => $this->total_conversations,
            'total_flagged' => $this->total_flagged,
            'average_score' => (float) $this->average_score,
            'previous_average_score' => $this->previous_average_score ? (float) $this->previous_average_score : null,
            'generation_cost' => $this->generation_cost ? (float) $this->generation_cost : null,
            'generated_at' => $this->generated_at?->toISOString(),
        ];
    }
}
