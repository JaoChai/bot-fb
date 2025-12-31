<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationTestCaseResource extends JsonResource
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
            'knowledge_base_id' => $this->knowledge_base_id,
            'title' => $this->title,
            'description' => $this->description,
            'persona_key' => $this->persona_key,
            'test_type' => $this->test_type,
            'status' => $this->status,

            // Scores
            'scores' => [
                'answer_relevancy' => $this->answer_relevancy,
                'faithfulness' => $this->faithfulness,
                'role_adherence' => $this->role_adherence,
                'context_precision' => $this->context_precision,
                'task_completion' => $this->task_completion,
                'overall' => $this->overall_score,
            ],

            // Feedback
            'detailed_feedback' => $this->detailed_feedback,

            // Metadata
            'expected_topics' => $this->expected_topics,
            'source_chunks' => $this->source_chunks,

            // Conversation
            'messages' => $this->whenLoaded('messages', fn() =>
                $this->messages->map(fn($msg) => [
                    'id' => $msg->id,
                    'turn_number' => $msg->turn_number,
                    'role' => $msg->role,
                    'content' => $msg->content,
                    'rag_metadata' => $msg->rag_metadata,
                    'model_metadata' => $msg->model_metadata,
                    'turn_scores' => $msg->turn_scores,
                ])
            ),

            // Timestamps
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
