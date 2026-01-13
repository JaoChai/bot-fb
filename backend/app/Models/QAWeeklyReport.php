<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class QAWeeklyReport extends Model
{
    use HasFactory;

    protected $table = 'qa_weekly_reports';

    protected $fillable = [
        'bot_id',
        'week_start',
        'week_end',
        'status',
        'performance_summary',
        'top_issues',
        'prompt_suggestions',
        'total_conversations',
        'total_flagged',
        'average_score',
        'previous_average_score',
        'generation_cost',
        'generated_at',
        'notification_sent',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'performance_summary' => 'array',
        'top_issues' => 'array',
        'prompt_suggestions' => 'array',
        'total_conversations' => 'integer',
        'total_flagged' => 'integer',
        'average_score' => 'decimal:2',
        'previous_average_score' => 'decimal:2',
        'generation_cost' => 'decimal:4',
        'generated_at' => 'datetime',
        'notification_sent' => 'boolean',
    ];

    // Report statuses
    public const STATUS_GENERATING = 'generating';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // Relationships
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    // Scopes
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeByBot(Builder $query, int $botId): Builder
    {
        return $query->where('bot_id', $botId);
    }

    public function scopeByWeek(Builder $query, $weekStart): Builder
    {
        return $query->where('week_start', $weekStart);
    }

    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderBy('week_start', 'desc');
    }

    public function scopePendingNotification(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED)
            ->where('notification_sent', false);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeGenerating(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_GENERATING);
    }

    // Helper methods
    public function getScoreChangeAttribute(): ?float
    {
        if ($this->previous_average_score === null) {
            return null;
        }

        return (float) $this->average_score - (float) $this->previous_average_score;
    }

    public function getFlaggedPercentageAttribute(): float
    {
        if ($this->total_conversations === 0) {
            return 0.0;
        }

        return round(($this->total_flagged / $this->total_conversations) * 100, 2);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function getErrorRate(): float
    {
        if ($this->total_conversations === 0) {
            return 0;
        }
        return ($this->total_flagged / $this->total_conversations) * 100;
    }

    public function getScoreTrend(): ?float
    {
        if ($this->previous_average_score === null) {
            return null;
        }
        return $this->average_score - $this->previous_average_score;
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'generated_at' => now(),
        ]);
    }

    public function markAsFailed(): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
        ]);
    }
}
