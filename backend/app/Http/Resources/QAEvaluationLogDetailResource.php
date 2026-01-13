<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QAEvaluationLogDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'message_id' => $this->message_id,
            'flow_id' => $this->flow_id,
            'scores' => [
                'answer_relevancy' => $this->answer_relevancy,
                'faithfulness' => $this->faithfulness,
                'role_adherence' => $this->role_adherence,
                'context_precision' => $this->context_precision,
                'task_completion' => $this->task_completion,
            ],
            'overall_score' => (float) $this->overall_score,
            'is_flagged' => (bool) $this->is_flagged,
            'issue_type' => $this->issue_type,
            'issue_details' => $this->issue_details,
            'user_question' => $this->user_question,
            'bot_response' => $this->bot_response,
            'system_prompt_used' => $this->system_prompt_used,
            'kb_chunks_used' => $this->kb_chunks_used,
            'model_metadata' => $this->model_metadata,
            'evaluated_at' => $this->evaluated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
