<?php

namespace App\Services\Agent;

/**
 * AgentLoopCallbacks - Interface for Agent Loop event callbacks.
 *
 * Implementations handle how agent events are delivered:
 * - SseAgentCallbacks: SSE streaming for frontend chat emulator
 * - SyncAgentCallbacks: Silent collection for webhook path
 */
interface AgentLoopCallbacks
{
    /**
     * Called when the agent loop starts.
     *
     * @param  array{max_iterations: int, tools: string[], model: string, safety: array}  $data
     */
    public function onAgentStart(array $data): void;

    /**
     * Called at the beginning of each iteration.
     *
     * @param  array{iteration: int, elapsed_seconds: float, running_cost: float}  $data
     */
    public function onThinking(array $data): void;

    /**
     * Called when a tool is about to be executed.
     *
     * @param  array{iteration: int, tool_id: string, tool_name: string, arguments: array}  $data
     */
    public function onToolCall(array $data): void;

    /**
     * Called after a tool finishes execution.
     *
     * @param  array{iteration: int, tool_id: string, tool_name: string, status: string, result_preview: string, time_ms: int}  $data
     */
    public function onToolResult(array $data): void;

    /**
     * Called when HITL approval is required for a dangerous action.
     *
     * @param  array{approval_id: string, tool_name: string, tool_args: array, timeout_seconds: int}  $data
     */
    public function onApprovalRequired(array $data): void;

    /**
     * Called as heartbeat while waiting for HITL approval.
     *
     * @param  array{approval_id: string, elapsed_seconds: int, timeout_seconds: int, tool_name: string}  $data
     */
    public function onApprovalWaiting(array $data): void;

    /**
     * Called when HITL approval response is received.
     *
     * @param  array{approval_id: string, approved: bool, reason: ?string}  $data
     */
    public function onApprovalResponse(array $data): void;

    /**
     * Called when a safety limit is triggered (timeout, cost limit, daily limit).
     *
     * @param  array{type: string, details?: array, message?: string, iteration: int}  $data
     */
    public function onSafetyStop(array $data): void;

    /**
     * Called when the agent loop completes successfully.
     *
     * @param  array{iterations: int, total_tool_calls: int, total_cost: float, elapsed_seconds: float}  $data
     */
    public function onAgentDone(array $data): void;

    /**
     * Called when an error occurs during the agent loop.
     *
     * @param  array{iteration: int, error: string}  $data
     */
    public function onAgentError(array $data): void;

    /**
     * Called when max iterations are reached.
     *
     * @param  array{iterations: int, message: string}  $data
     */
    public function onMaxIterations(array $data): void;

    /**
     * Called to deliver final response content.
     *
     * @param  string  $content  The response text
     * @param  string  $model  The model that generated the response
     * @param  string  $source  Content source (e.g. 'agent_final_response', 'max_iterations_fallback')
     */
    public function onContent(string $content, string $model, string $source): void;
}
