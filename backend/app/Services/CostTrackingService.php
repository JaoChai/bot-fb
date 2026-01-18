<?php

namespace App\Services;

use App\Models\AgentCostUsage;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CostTrackingService
 *
 * Tracks AI API costs and enforces spending limits.
 * Provides cost estimation based on model and token usage.
 */
class CostTrackingService
{
    /**
     * Model pricing per 1M tokens (USD)
     * Source: OpenRouter pricing as of Dec 2024
     */
    protected array $modelPricing = [
        // GPT-4o series
        'openai/gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'openai/gpt-4o-2024-11-20' => ['input' => 2.50, 'output' => 10.00],
        'openai/gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'openai/gpt-4o-mini-2024-07-18' => ['input' => 0.15, 'output' => 0.60],

        // GPT-4 Turbo
        'openai/gpt-4-turbo' => ['input' => 10.00, 'output' => 30.00],
        'openai/gpt-4-turbo-preview' => ['input' => 10.00, 'output' => 30.00],

        // Claude series
        'anthropic/claude-3.5-sonnet' => ['input' => 3.00, 'output' => 15.00],
        'anthropic/claude-3-sonnet' => ['input' => 3.00, 'output' => 15.00],
        'anthropic/claude-3-haiku' => ['input' => 0.25, 'output' => 1.25],
        'anthropic/claude-3-opus' => ['input' => 15.00, 'output' => 75.00],

        // Gemini series
        'google/gemini-pro-1.5' => ['input' => 1.25, 'output' => 5.00],
        'google/gemini-flash-1.5' => ['input' => 0.075, 'output' => 0.30],

        // Llama series (often free or very cheap)
        'meta-llama/llama-3.1-70b-instruct' => ['input' => 0.35, 'output' => 0.40],
        'meta-llama/llama-3.1-8b-instruct' => ['input' => 0.06, 'output' => 0.06],

        // Default fallback
        'default' => ['input' => 1.00, 'output' => 3.00],
    ];

    /**
     * Current request tracking
     */
    protected ?string $currentRequestId = null;
    protected float $runningCost = 0;
    protected int $runningPromptTokens = 0;
    protected int $runningCompletionTokens = 0;
    protected int $toolCallCount = 0;

    /**
     * Enhanced usage tracking (OpenRouter Best Practice)
     */
    protected int $runningCachedTokens = 0;
    protected int $runningReasoningTokens = 0;
    protected ?float $runningActualCost = null;

    /**
     * Start tracking a new request.
     */
    public function startRequest(): string
    {
        $this->currentRequestId = (string) Str::uuid();
        $this->runningCost = 0;
        $this->runningPromptTokens = 0;
        $this->runningCompletionTokens = 0;
        $this->toolCallCount = 0;
        $this->runningCachedTokens = 0;
        $this->runningReasoningTokens = 0;
        $this->runningActualCost = null;

        return $this->currentRequestId;
    }

    /**
     * Add cost from an API call.
     *
     * @param string $model Model ID
     * @param int $promptTokens Prompt tokens used
     * @param int $completionTokens Completion tokens used
     * @param int $cachedTokens Tokens served from prompt cache (cheaper pricing)
     * @param int $reasoningTokens Tokens used by reasoning models (o1, deepseek-r1)
     * @param float|null $actualCost Real cost from OpenRouter API (vs estimated)
     */
    public function addCost(
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $cachedTokens = 0,
        int $reasoningTokens = 0,
        ?float $actualCost = null
    ): float {
        $pricing = $this->modelPricing[$model] ?? $this->modelPricing['default'];

        // Calculate cost per 1M tokens
        $inputCost = ($promptTokens / 1_000_000) * $pricing['input'];
        $outputCost = ($completionTokens / 1_000_000) * $pricing['output'];
        $totalCost = $inputCost + $outputCost;

        $this->runningCost += $totalCost;
        $this->runningPromptTokens += $promptTokens;
        $this->runningCompletionTokens += $completionTokens;

        // Track enhanced usage from OpenRouter
        $this->runningCachedTokens += $cachedTokens;
        $this->runningReasoningTokens += $reasoningTokens;
        if ($actualCost !== null) {
            $this->runningActualCost = ($this->runningActualCost ?? 0) + $actualCost;
        }

        Log::debug('CostTracking: Added cost', [
            'model' => $model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => $cachedTokens,
            'reasoning_tokens' => $reasoningTokens,
            'estimated_cost' => $totalCost,
            'actual_cost' => $actualCost,
            'running_total' => $this->runningCost,
        ]);

        return $totalCost;
    }

    /**
     * Increment tool call count.
     */
    public function addToolCall(): void
    {
        $this->toolCallCount++;
    }

    /**
     * Get current running cost.
     */
    public function getRunningCost(): float
    {
        return $this->runningCost;
    }

    /**
     * Check if request exceeds per-request limit.
     */
    public function exceedsRequestLimit(?float $maxCost): bool
    {
        if ($maxCost === null) {
            return false;
        }

        return $this->runningCost >= $maxCost;
    }

    /**
     * Check if user exceeds daily limit.
     */
    public function exceedsDailyLimit(User $user): bool
    {
        $settings = $user->settings;
        if (!$settings) {
            return false;
        }

        $maxDaily = $settings->max_daily_cost;
        if (!$maxDaily) {
            return false;
        }

        $dailyCost = AgentCostUsage::getDailyCost($user->id);
        $totalCost = $dailyCost + $this->runningCost;

        return $totalCost >= $maxDaily;
    }

    /**
     * Get remaining daily budget for user.
     */
    public function getRemainingDailyBudget(User $user): ?float
    {
        $settings = $user->settings;
        if (!$settings || !$settings->max_daily_cost) {
            return null;
        }

        $dailyCost = AgentCostUsage::getDailyCost($user->id);
        return max(0, $settings->max_daily_cost - $dailyCost);
    }

    /**
     * Finalize and save usage record.
     */
    public function finalizeRequest(
        int $userId,
        ?int $botId,
        ?int $flowId,
        string $status,
        int $durationMs,
        int $iterations = 1,
        ?string $modelUsed = null,
        ?string $fallbackModelUsed = null,
        ?string $errorMessage = null,
        ?array $metadata = null
    ): AgentCostUsage {
        $usage = AgentCostUsage::create([
            'user_id' => $userId,
            'bot_id' => $botId,
            'flow_id' => $flowId,
            'request_id' => $this->currentRequestId ?? (string) Str::uuid(),
            'estimated_cost' => $this->runningCost,
            'prompt_tokens' => $this->runningPromptTokens,
            'completion_tokens' => $this->runningCompletionTokens,
            'tool_calls' => $this->toolCallCount,
            'model_used' => $modelUsed,
            'fallback_model_used' => $fallbackModelUsed,
            'duration_ms' => $durationMs,
            'iterations' => $iterations,
            'status' => $status,
            'error_message' => $errorMessage,
            'metadata' => $metadata,
            // Enhanced usage tracking (OpenRouter Best Practice)
            'actual_cost' => $this->runningActualCost,
            'cached_tokens' => $this->runningCachedTokens,
            'reasoning_tokens' => $this->runningReasoningTokens,
        ]);

        Log::info('CostTracking: Request finalized', [
            'request_id' => $usage->request_id,
            'user_id' => $userId,
            'estimated_cost' => $this->runningCost,
            'actual_cost' => $this->runningActualCost,
            'status' => $status,
            'tokens' => $this->runningPromptTokens + $this->runningCompletionTokens,
            'cached_tokens' => $this->runningCachedTokens,
            'reasoning_tokens' => $this->runningReasoningTokens,
        ]);

        // Reset tracking
        $this->currentRequestId = null;
        $this->runningCost = 0;
        $this->runningPromptTokens = 0;
        $this->runningCompletionTokens = 0;
        $this->toolCallCount = 0;
        $this->runningCachedTokens = 0;
        $this->runningReasoningTokens = 0;
        $this->runningActualCost = null;

        return $usage;
    }

    /**
     * Estimate cost before making a call.
     */
    public function estimateCost(
        string $model,
        int $estimatedPromptTokens,
        int $estimatedCompletionTokens
    ): float {
        $pricing = $this->modelPricing[$model] ?? $this->modelPricing['default'];

        $inputCost = ($estimatedPromptTokens / 1_000_000) * $pricing['input'];
        $outputCost = ($estimatedCompletionTokens / 1_000_000) * $pricing['output'];

        return $inputCost + $outputCost;
    }

    /**
     * Get pricing info for a model.
     */
    public function getModelPricing(string $model): array
    {
        return $this->modelPricing[$model] ?? $this->modelPricing['default'];
    }

    /**
     * Check if approaching daily limit (for alerts).
     */
    public function isApproachingLimit(User $user): ?array
    {
        $settings = $user->settings;
        if (!$settings || !$settings->cost_alert_enabled) {
            return null;
        }

        $maxDaily = $settings->max_daily_cost;
        if (!$maxDaily) {
            return null;
        }

        $dailyCost = AgentCostUsage::getDailyCost($user->id);
        $percentage = ($dailyCost / $maxDaily) * 100;
        $threshold = $settings->cost_alert_threshold ?? 80;

        if ($percentage >= $threshold) {
            return [
                'daily_cost' => $dailyCost,
                'max_daily' => $maxDaily,
                'percentage' => round($percentage, 1),
                'threshold' => $threshold,
            ];
        }

        return null;
    }
}
