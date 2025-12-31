<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Evaluation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'bot_id',
        'flow_id',
        'user_id',
        'name',
        'description',
        'status',
        'judge_model',
        'generator_model',
        'simulator_model',
        'personas',
        'config',
        'overall_score',
        'metric_scores',
        'recommendations',
        'started_at',
        'completed_at',
        'total_test_cases',
        'completed_test_cases',
        'total_tokens_used',
        'estimated_cost',
        'error_message',
    ];

    protected $casts = [
        'personas' => 'array',
        'config' => 'array',
        'metric_scores' => 'array',
        'recommendations' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'overall_score' => 'float',
        'estimated_cost' => 'float',
        'total_test_cases' => 'integer',
        'completed_test_cases' => 'integer',
        'total_tokens_used' => 'integer',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_GENERATING_TESTS = 'generating_tests';
    public const STATUS_RUNNING = 'running';
    public const STATUS_EVALUATING = 'evaluating';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function testCases(): HasMany
    {
        return $this->hasMany(EvaluationTestCase::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(EvaluationReport::class);
    }

    // Helper methods
    public function getProgressAttribute(): float
    {
        if ($this->total_test_cases === 0) {
            return 0;
        }
        return round(($this->completed_test_cases / $this->total_test_cases) * 100, 2);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [
            self::STATUS_GENERATING_TESTS,
            self::STATUS_RUNNING,
            self::STATUS_EVALUATING,
        ]);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function markAsGeneratingTests(): void
    {
        $this->update([
            'status' => self::STATUS_GENERATING_TESTS,
            'started_at' => now(),
        ]);
    }

    public function markAsRunning(): void
    {
        $this->update(['status' => self::STATUS_RUNNING]);
    }

    public function markAsEvaluating(): void
    {
        $this->update(['status' => self::STATUS_EVALUATING]);
    }

    public function markAsCompleted(array $metricScores, float $overallScore): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'metric_scores' => $metricScores,
            'overall_score' => $overallScore,
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

    public function incrementCompletedTestCases(): void
    {
        $this->increment('completed_test_cases');
    }

    public function addTokensUsed(int $tokens): void
    {
        $this->increment('total_tokens_used', $tokens);
    }
}
