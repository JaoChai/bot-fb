<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\Log;

/**
 * SyncAgentCallbacks - Silent callback implementation for webhook path.
 *
 * Collects content and logs without SSE output.
 * Auto-rejects HITL approval requests (via AgentLoopConfig::autoRejectHitl).
 */
class SyncAgentCallbacks implements AgentLoopCallbacks
{
    protected string $content = '';
    protected array $logs = [];

    public function onAgentStart(array $data): void
    {
        $this->logs[] = ['event' => 'agent_start', 'data' => $data];
        Log::debug('AgentLoop (sync): started', $data);
    }

    public function onThinking(array $data): void
    {
        $this->logs[] = ['event' => 'agent_thinking', 'data' => $data];
    }

    public function onToolCall(array $data): void
    {
        $this->logs[] = ['event' => 'tool_call', 'data' => $data];
        Log::debug('AgentLoop (sync): tool_call', ['tool' => $data['tool_name'] ?? 'unknown']);
    }

    public function onToolResult(array $data): void
    {
        $this->logs[] = ['event' => 'tool_result', 'data' => $data];
    }

    public function onApprovalRequired(array $data): void
    {
        // In sync mode, HITL is auto-rejected via config->autoRejectHitl
        // This callback should not be reached, but log just in case
        Log::info('AgentLoop (sync): approval_required (should be auto-rejected)', $data);
        $this->logs[] = ['event' => 'approval_required', 'data' => $data];
    }

    public function onApprovalWaiting(array $data): void
    {
        // Should not be reached in sync mode
        $this->logs[] = ['event' => 'approval_waiting', 'data' => $data];
    }

    public function onApprovalResponse(array $data): void
    {
        $this->logs[] = ['event' => 'approval_response', 'data' => $data];
    }

    public function onSafetyStop(array $data): void
    {
        Log::warning('AgentLoop (sync): safety_stop', $data);
        $this->logs[] = ['event' => 'safety_stop', 'data' => $data];
    }

    public function onAgentDone(array $data): void
    {
        $this->logs[] = ['event' => 'agent_done', 'data' => $data];
        Log::debug('AgentLoop (sync): done', $data);
    }

    public function onAgentError(array $data): void
    {
        Log::error('AgentLoop (sync): error', $data);
        $this->logs[] = ['event' => 'agent_error', 'data' => $data];
    }

    public function onMaxIterations(array $data): void
    {
        Log::warning('AgentLoop (sync): max_iterations', $data);
        $this->logs[] = ['event' => 'max_iterations', 'data' => $data];
    }

    public function onContent(string $content, string $model, string $source): void
    {
        $this->content = $content;
        $this->logs[] = ['event' => 'content', 'model' => $model, 'source' => $source];
    }

    /**
     * Get the final response content.
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Get all collected event logs.
     */
    public function getLogs(): array
    {
        return $this->logs;
    }
}
