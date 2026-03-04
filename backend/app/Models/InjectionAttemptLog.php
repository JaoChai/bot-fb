<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InjectionAttemptLog extends Model
{
    use HasFactory;

    protected $table = 'injection_attempts_log';

    protected $fillable = [
        'bot_id',
        'conversation_id',
        'user_input',
        'detected_patterns',
        'risk_score',
        'action_taken',
    ];

    protected $casts = [
        'detected_patterns' => 'array',
        'risk_score' => 'decimal:2',
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

    // Scopes
    public function scopeByBot(Builder $query, int $botId): Builder
    {
        return $query->where('bot_id', $botId);
    }

    public function scopeBlocked(Builder $query): Builder
    {
        return $query->where('action_taken', 'blocked');
    }

    public function scopeFlagged(Builder $query): Builder
    {
        return $query->where('action_taken', 'flagged');
    }

    public function scopeHighRisk(Builder $query, float $threshold = 0.7): Builder
    {
        return $query->where('risk_score', '>=', $threshold);
    }

    public function scopeByDateRange(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    // Helper methods
    public function isBlocked(): bool
    {
        return $this->action_taken === 'blocked';
    }

    public function isFlagged(): bool
    {
        return $this->action_taken === 'flagged';
    }

    /**
     * Get pattern names from detected patterns
     *
     * @return array<string>
     */
    public function getPatternNamesAttribute(): array
    {
        return array_column($this->detected_patterns ?? [], 'pattern');
    }

    /**
     * Get categories of detected patterns
     *
     * @return array<string>
     */
    public function getCategoriesAttribute(): array
    {
        return array_unique(
            array_column($this->detected_patterns ?? [], 'category')
        );
    }
}
