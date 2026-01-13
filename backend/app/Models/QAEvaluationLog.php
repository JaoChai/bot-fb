<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class QAEvaluationLog extends Model
{
    use HasFactory;

    protected $table = 'qa_evaluation_logs';

    protected $fillable = [
        'bot_id',
        'conversation_id',
        'message_id',
        'flow_id',
        'answer_relevancy',
        'faithfulness',
        'role_adherence',
        'context_precision',
        'task_completion',
        'overall_score',
        'is_flagged',
        'issue_type',
        'issue_details',
        'user_question',
        'bot_response',
        'system_prompt_used',
        'kb_chunks_used',
        'model_metadata',
        'evaluated_at',
    ];

    protected $casts = [
        'answer_relevancy' => 'decimal:2',
        'faithfulness' => 'decimal:2',
        'role_adherence' => 'decimal:2',
        'context_precision' => 'decimal:2',
        'task_completion' => 'decimal:2',
        'overall_score' => 'decimal:2',
        'is_flagged' => 'boolean',
        'issue_details' => 'array',
        'kb_chunks_used' => 'array',
        'model_metadata' => 'array',
        'evaluated_at' => 'datetime',
    ];

    // Relationships
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    // Scopes
    public function scopeFlagged(Builder $query): Builder
    {
        return $query->where('is_flagged', true);
    }

    public function scopeByBot(Builder $query, int $botId): Builder
    {
        return $query->where('bot_id', $botId);
    }

    public function scopeByDateRange(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    public function scopeByIssueType(Builder $query, string $issueType): Builder
    {
        return $query->where('issue_type', $issueType);
    }

    public function scopeScoreBelow(Builder $query, float $threshold): Builder
    {
        return $query->where('overall_score', '<', $threshold);
    }

    public function scopeByConversation(Builder $query, int $conversationId): Builder
    {
        return $query->where('conversation_id', $conversationId);
    }

    // Helper methods
    public function getScoresAttribute(): array
    {
        return [
            'answer_relevancy' => $this->answer_relevancy,
            'faithfulness' => $this->faithfulness,
            'role_adherence' => $this->role_adherence,
            'context_precision' => $this->context_precision,
            'task_completion' => $this->task_completion,
        ];
    }
}
