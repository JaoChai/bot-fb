<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImprovementSession extends Model
{
    use HasFactory;

    // Status constants
    public const STATUS_ANALYZING = 'analyzing';
    public const STATUS_SUGGESTIONS_READY = 'suggestions_ready';
    public const STATUS_APPLYING = 'applying';
    public const STATUS_RE_EVALUATING = 're_evaluating';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'evaluation_id',
        'flow_id',
        'bot_id',
        'user_id',
        'status',
        'original_system_prompt',
        'original_kb_snapshot',
        'analysis_summary',
        'before_score',
        'after_score',
        'score_improvement',
        're_evaluation_id',
        'agent_model',
        'total_tokens_used',
        'estimated_cost',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'original_kb_snapshot' => 'array',
        'before_score' => 'float',
        'after_score' => 'float',
        'score_improvement' => 'float',
        'total_tokens_used' => 'integer',
        'estimated_cost' => 'float',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Relationships
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(ImprovementSuggestion::class, 'session_id');
    }

    public function reEvaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class, 're_evaluation_id');
    }

    // Helper methods
    public function isAnalyzing(): bool
    {
        return $this->status === self::STATUS_ANALYZING;
    }

    public function isSuggestionsReady(): bool
    {
        return $this->status === self::STATUS_SUGGESTIONS_READY;
    }

    public function isApplying(): bool
    {
        return $this->status === self::STATUS_APPLYING;
    }

    public function isReEvaluating(): bool
    {
        return $this->status === self::STATUS_RE_EVALUATING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function markAsAnalyzing(): void
    {
        $this->update([
            'status' => self::STATUS_ANALYZING,
            'started_at' => now(),
        ]);
    }

    public function markAsSuggestionsReady(string $analysisSummary): void
    {
        $this->update([
            'status' => self::STATUS_SUGGESTIONS_READY,
            'analysis_summary' => $analysisSummary,
        ]);
    }

    public function markAsApplying(): void
    {
        $this->update(['status' => self::STATUS_APPLYING]);
    }

    public function markAsReEvaluating(int $reEvaluationId): void
    {
        $this->update([
            'status' => self::STATUS_RE_EVALUATING,
            're_evaluation_id' => $reEvaluationId,
        ]);
    }

    public function markAsCompleted(float $beforeScore, float $afterScore): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'before_score' => $beforeScore,
            'after_score' => $afterScore,
            'score_improvement' => $afterScore - $beforeScore,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    public function markAsCancelled(): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'completed_at' => now(),
        ]);
    }

    public function addTokensUsed(int $tokens): void
    {
        $this->increment('total_tokens_used', $tokens);
    }

    public function getSelectedSuggestions()
    {
        return $this->suggestions()->where('is_selected', true)->get();
    }

    public function getSelectedSuggestionsCount(): int
    {
        return $this->suggestions()->where('is_selected', true)->count();
    }
}
