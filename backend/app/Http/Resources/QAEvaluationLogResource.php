<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class QAEvaluationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'message_id' => $this->message_id,
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
            'user_question' => Str::limit($this->user_question, 100),
            'bot_response' => Str::limit($this->bot_response, 150),
            'evaluated_at' => $this->evaluated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
