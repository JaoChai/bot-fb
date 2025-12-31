<?php

namespace App\Services;

use App\Models\Flow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AgentSafetyService
 *
 * Provides safety controls for agentic AI:
 * - Timeout management
 * - Cost limiting
 * - Human-in-the-loop (HITL) approval
 * - Dangerous action detection
 */
class AgentSafetyService
{
    /**
     * Default dangerous tool patterns.
     * Tools matching these patterns require HITL approval.
     */
    protected array $defaultDangerousPatterns = [
        'delete_*',
        'remove_*',
        'destroy_*',
        'drop_*',
        'truncate_*',
        'execute_sql',
        'run_command',
        'send_email',
        'make_payment',
        'transfer_funds',
    ];

    public function __construct(
        protected CostTrackingService $costTracking
    ) {}

    /**
     * Check if an action requires HITL approval.
     */
    public function requiresApproval(Flow $flow, string $toolName, array $toolArgs = []): bool
    {
        // HITL must be enabled
        if (!$flow->hitl_enabled) {
            return false;
        }

        // Check against flow's custom dangerous actions
        $dangerousActions = $flow->hitl_dangerous_actions ?? [];

        // If no custom list, use defaults
        if (empty($dangerousActions)) {
            $dangerousActions = $this->defaultDangerousPatterns;
        }

        foreach ($dangerousActions as $pattern) {
            if ($this->matchesPattern($toolName, $pattern)) {
                Log::info('AgentSafety: Action requires approval', [
                    'flow_id' => $flow->id,
                    'tool' => $toolName,
                    'pattern' => $pattern,
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Check if tool name matches a pattern (supports wildcards).
     */
    protected function matchesPattern(string $toolName, string $pattern): bool
    {
        // Direct match
        if ($toolName === $pattern) {
            return true;
        }

        // Wildcard pattern matching
        $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/i';
        return (bool) preg_match($regex, $toolName);
    }

    /**
     * Request approval for a dangerous action.
     * Returns a pending approval ID.
     */
    public function requestApproval(
        string $requestId,
        Flow $flow,
        string $toolName,
        array $toolArgs,
        int $timeoutSeconds = 60
    ): string {
        $approvalId = $requestId . ':' . uniqid();

        $approvalData = [
            'id' => $approvalId,
            'request_id' => $requestId,
            'flow_id' => $flow->id,
            'tool_name' => $toolName,
            'tool_args' => $toolArgs,
            'status' => 'pending',
            'requested_at' => now()->toIso8601String(),
            'expires_at' => now()->addSeconds($timeoutSeconds)->toIso8601String(),
        ];

        // Store in cache with TTL
        Cache::put(
            "hitl_approval:{$approvalId}",
            $approvalData,
            $timeoutSeconds + 10 // Add buffer
        );

        Log::info('AgentSafety: Approval requested', [
            'approval_id' => $approvalId,
            'tool' => $toolName,
            'timeout' => $timeoutSeconds,
        ]);

        return $approvalId;
    }

    /**
     * Wait for approval (blocking with timeout).
     */
    public function waitForApproval(string $approvalId, int $timeoutSeconds = 60): array
    {
        $startTime = time();
        $checkInterval = 500000; // 0.5 seconds in microseconds

        while (time() - $startTime < $timeoutSeconds) {
            $approval = Cache::get("hitl_approval:{$approvalId}");

            if (!$approval) {
                return [
                    'approved' => false,
                    'reason' => 'expired',
                ];
            }

            if ($approval['status'] !== 'pending') {
                return [
                    'approved' => $approval['status'] === 'approved',
                    'reason' => $approval['reason'] ?? null,
                    'approved_by' => $approval['approved_by'] ?? null,
                ];
            }

            usleep($checkInterval);
        }

        // Timeout
        return [
            'approved' => false,
            'reason' => 'timeout',
        ];
    }

    /**
     * Approve an action (called by user).
     */
    public function approve(string $approvalId, int $userId, ?string $reason = null): bool
    {
        $approval = Cache::get("hitl_approval:{$approvalId}");

        if (!$approval || $approval['status'] !== 'pending') {
            return false;
        }

        $approval['status'] = 'approved';
        $approval['approved_by'] = $userId;
        $approval['reason'] = $reason;
        $approval['responded_at'] = now()->toIso8601String();

        Cache::put(
            "hitl_approval:{$approvalId}",
            $approval,
            60 // Keep for 1 minute after response
        );

        Log::info('AgentSafety: Action approved', [
            'approval_id' => $approvalId,
            'approved_by' => $userId,
        ]);

        return true;
    }

    /**
     * Reject an action (called by user).
     */
    public function reject(string $approvalId, int $userId, ?string $reason = null): bool
    {
        $approval = Cache::get("hitl_approval:{$approvalId}");

        if (!$approval || $approval['status'] !== 'pending') {
            return false;
        }

        $approval['status'] = 'rejected';
        $approval['approved_by'] = $userId;
        $approval['reason'] = $reason ?? 'User rejected';
        $approval['responded_at'] = now()->toIso8601String();

        Cache::put(
            "hitl_approval:{$approvalId}",
            $approval,
            60
        );

        Log::info('AgentSafety: Action rejected', [
            'approval_id' => $approvalId,
            'rejected_by' => $userId,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Get pending approval details.
     */
    public function getApproval(string $approvalId): ?array
    {
        return Cache::get("hitl_approval:{$approvalId}");
    }

    /**
     * Check if a request should timeout.
     */
    public function checkTimeout(float $startTime, int $timeoutSeconds): bool
    {
        $elapsed = microtime(true) - $startTime;
        return $elapsed >= $timeoutSeconds;
    }

    /**
     * Get elapsed time in seconds.
     */
    public function getElapsedSeconds(float $startTime): float
    {
        return microtime(true) - $startTime;
    }

    /**
     * Check all safety limits and return any violations.
     */
    public function checkLimits(
        Flow $flow,
        float $startTime,
        float $runningCost,
        int $userId
    ): ?array {
        // Check timeout
        $timeout = $flow->agent_timeout_seconds ?? 120;
        if ($this->checkTimeout($startTime, $timeout)) {
            return [
                'type' => 'timeout',
                'limit' => $timeout,
                'elapsed' => round($this->getElapsedSeconds($startTime), 1),
            ];
        }

        // Check per-request cost limit
        $maxCost = $flow->agent_max_cost_per_request;
        if ($maxCost !== null && $runningCost >= $maxCost) {
            return [
                'type' => 'cost_limit',
                'limit' => $maxCost,
                'current' => round($runningCost, 4),
                'scope' => 'request',
            ];
        }

        return null;
    }

    /**
     * Get safety configuration summary for a flow.
     */
    public function getSafetyConfig(Flow $flow): array
    {
        return [
            'timeout_seconds' => $flow->agent_timeout_seconds ?? 120,
            'max_cost_per_request' => $flow->agent_max_cost_per_request,
            'hitl_enabled' => $flow->hitl_enabled ?? false,
            'dangerous_actions' => $flow->hitl_dangerous_actions ?? $this->defaultDangerousPatterns,
        ];
    }
}
