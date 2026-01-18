<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AgentCostUsage
 *
 * Tracks AI cost usage per request for daily limits,
 * analytics, and billing purposes.
 */
class AgentCostUsage extends Model
{
    protected $table = 'agent_cost_usage';

    protected $fillable = [
        'user_id',
        'bot_id',
        'flow_id',
        'request_id',
        'estimated_cost',
        'prompt_tokens',
        'completion_tokens',
        'tool_calls',
        'model_used',
        'fallback_model_used',
        'duration_ms',
        'iterations',
        'status',
        'error_message',
        'metadata',
        // Enhanced usage tracking (OpenRouter Best Practice)
        'actual_cost',
        'cached_tokens',
        'reasoning_tokens',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:6',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'tool_calls' => 'integer',
        'duration_ms' => 'integer',
        'iterations' => 'integer',
        'metadata' => 'array',
        // Enhanced usage tracking (OpenRouter Best Practice)
        'actual_cost' => 'decimal:6',
        'cached_tokens' => 'integer',
        'reasoning_tokens' => 'integer',
    ];

    /**
     * Status constants
     */
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_TIMEOUT = 'timeout';
    public const STATUS_COST_LIMIT = 'cost_limit';
    public const STATUS_RATE_LIMIT = 'rate_limit';
    public const STATUS_ERROR = 'error';
    public const STATUS_CANCELLED = 'cancelled';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    /**
     * Scope: Get usage for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Get today's usage.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    /**
     * Scope: Get this month's usage.
     */
    public function scopeThisMonth($query)
    {
        return $query->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month);
    }

    /**
     * Calculate total cost for a user today.
     */
    public static function getDailyCost(int $userId): float
    {
        return static::forUser($userId)
            ->today()
            ->sum('estimated_cost');
    }

    /**
     * Calculate total cost for a user this month.
     */
    public static function getMonthlyCost(int $userId): float
    {
        return static::forUser($userId)
            ->thisMonth()
            ->sum('estimated_cost');
    }

    /**
     * Get usage statistics for a user.
     */
    public static function getStats(int $userId): array
    {
        $today = static::forUser($userId)->today();
        $month = static::forUser($userId)->thisMonth();

        return [
            'daily' => [
                'cost' => $today->sum('estimated_cost'),
                'requests' => $today->count(),
                'tokens' => $today->sum('prompt_tokens') + $today->sum('completion_tokens'),
            ],
            'monthly' => [
                'cost' => $month->sum('estimated_cost'),
                'requests' => $month->count(),
                'tokens' => $month->sum('prompt_tokens') + $month->sum('completion_tokens'),
            ],
        ];
    }
}
