<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationTestCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'evaluation_id',
        'knowledge_base_id',
        'title',
        'description',
        'persona_key',
        'test_type',
        'expected_topics',
        'source_chunks',
        'status',
        'answer_relevancy',
        'faithfulness',
        'role_adherence',
        'context_precision',
        'task_completion',
        'overall_score',
        'detailed_feedback',
    ];

    protected $casts = [
        'expected_topics' => 'array',
        'source_chunks' => 'array',
        'detailed_feedback' => 'array',
        'answer_relevancy' => 'float',
        'faithfulness' => 'float',
        'role_adherence' => 'float',
        'context_precision' => 'float',
        'task_completion' => 'float',
        'overall_score' => 'float',
    ];

    // Test type constants
    public const TYPE_SINGLE_TURN = 'single_turn';
    public const TYPE_MULTI_TURN = 'multi_turn';
    public const TYPE_EDGE_CASE = 'edge_case';
    public const TYPE_PERSONA_ADHERENCE = 'persona_adherence';

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // Metric weights for overall score calculation
    public const METRIC_WEIGHTS = [
        'answer_relevancy' => 0.25,
        'faithfulness' => 0.25,
        'role_adherence' => 0.20,
        'context_precision' => 0.15,
        'task_completion' => 0.15,
    ];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EvaluationMessage::class, 'test_case_id')->orderBy('turn_number');
    }

    // Helper methods
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function markAsRunning(): void
    {
        $this->update(['status' => self::STATUS_RUNNING]);
    }

    public function markAsCompleted(array $scores, array $feedback): void
    {
        $overallScore = $this->calculateOverallScore($scores);

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'answer_relevancy' => $scores['answer_relevancy'] ?? null,
            'faithfulness' => $scores['faithfulness'] ?? null,
            'role_adherence' => $scores['role_adherence'] ?? null,
            'context_precision' => $scores['context_precision'] ?? null,
            'task_completion' => $scores['task_completion'] ?? null,
            'overall_score' => $overallScore,
            'detailed_feedback' => $feedback,
        ]);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => self::STATUS_FAILED]);
    }

    protected function calculateOverallScore(array $scores): float
    {
        $totalWeight = 0;
        $weightedSum = 0;

        foreach (self::METRIC_WEIGHTS as $metric => $weight) {
            if (isset($scores[$metric]) && $scores[$metric] !== null) {
                $weightedSum += $scores[$metric] * $weight;
                $totalWeight += $weight;
            }
        }

        return $totalWeight > 0 ? round($weightedSum / $totalWeight, 4) : 0;
    }

    public function getConversation(): array
    {
        return $this->messages->map(function ($msg) {
            return [
                'role' => $msg->role,
                'content' => $msg->content,
            ];
        })->toArray();
    }
}
