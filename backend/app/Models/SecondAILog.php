<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class SecondAILog extends Model
{
    use HasFactory;

    protected $table = 'second_ai_logs';

    protected $fillable = [
        'bot_id',
        'conversation_id',
        'message_id',
        'flow_id',
        'groundedness_score',
        'policy_compliance_score',
        'personality_match_score',
        'overall_score',
        'was_modified',
        'checks_applied',
        'modifications',
        'latency_ms',
        'model_used',
        'execution_mode',
    ];

    protected $casts = [
        'groundedness_score' => 'decimal:2',
        'policy_compliance_score' => 'decimal:2',
        'personality_match_score' => 'decimal:2',
        'overall_score' => 'decimal:2',
        'was_modified' => 'boolean',
        'checks_applied' => 'array',
        'modifications' => 'array',
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
    public function scopeByBot(Builder $query, int $botId): Builder
    {
        return $query->where('bot_id', $botId);
    }

    public function scopeByFlow(Builder $query, int $flowId): Builder
    {
        return $query->where('flow_id', $flowId);
    }

    public function scopeModified(Builder $query): Builder
    {
        return $query->where('was_modified', true);
    }

    public function scopeByDateRange(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    public function scopeScoreBelow(Builder $query, float $threshold): Builder
    {
        return $query->where('overall_score', '<', $threshold);
    }

    public function scopeByExecutionMode(Builder $query, string $mode): Builder
    {
        return $query->where('execution_mode', $mode);
    }

    // Helper methods
    public function getScoresAttribute(): array
    {
        return [
            'groundedness' => $this->groundedness_score,
            'policy_compliance' => $this->policy_compliance_score,
            'personality_match' => $this->personality_match_score,
            'overall' => $this->overall_score,
        ];
    }

    /**
     * Get checks that were actually applied (required modifications)
     *
     * @return array<string>
     */
    public function getAppliedChecksAttribute(): array
    {
        if (empty($this->modifications)) {
            return [];
        }

        return array_filter(
            array_keys($this->modifications),
            fn (string $check) => ($this->modifications[$check]['required'] ?? false)
        );
    }

    /**
     * Check if a specific check type was applied
     */
    public function wasCheckApplied(string $checkType): bool
    {
        return in_array($checkType, $this->applied_checks);
    }

    /**
     * Calculate weighted overall score from individual metrics
     *
     * Weights: groundedness 40%, policy 30%, personality 30%
     */
    public static function calculateOverallScore(
        ?float $groundedness,
        ?float $policy,
        ?float $personality
    ): ?float {
        $weights = [
            'groundedness' => 0.4,
            'policy' => 0.3,
            'personality' => 0.3,
        ];

        $scores = [
            'groundedness' => $groundedness,
            'policy' => $policy,
            'personality' => $personality,
        ];

        $totalWeight = 0;
        $weightedSum = 0;

        foreach ($weights as $key => $weight) {
            if ($scores[$key] !== null) {
                $weightedSum += $scores[$key] * $weight;
                $totalWeight += $weight;
            }
        }

        return $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : null;
    }
}
