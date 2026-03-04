<?php

namespace App\Services\Agent;

use Closure;

/**
 * SseAgentCallbacks - SSE streaming callback implementation.
 *
 * Maps AgentLoopCallbacks to SSE events for the frontend chat emulator.
 * Produces IDENTICAL events to what StreamController currently produces.
 */
class SseAgentCallbacks implements AgentLoopCallbacks
{
    protected bool $chatStartSent = false;

    /**
     * @param  Closure(string, array): bool  $sendSSE  fn($event, $data) => bool
     * @param  array  $metrics  Reference to StreamController's metrics array
     */
    public function __construct(
        protected Closure $sendSSE,
        protected array &$metrics,
    ) {}

    public function onAgentStart(array $data): void
    {
        ($this->sendSSE)('agent_start', $data);
    }

    public function onThinking(array $data): void
    {
        ($this->sendSSE)('agent_thinking', $data);
    }

    public function onToolCall(array $data): void
    {
        $this->metrics['tool_calls']++;
        ($this->sendSSE)('tool_call', $data);
    }

    public function onToolResult(array $data): void
    {
        ($this->sendSSE)('tool_result', $data);
    }

    public function onApprovalRequired(array $data): void
    {
        ($this->sendSSE)('agent_approval_required', $data);
    }

    public function onApprovalWaiting(array $data): void
    {
        ($this->sendSSE)('agent_approval_waiting', $data);
    }

    public function onApprovalResponse(array $data): void
    {
        ($this->sendSSE)('agent_approval_response', $data);
    }

    public function onSafetyStop(array $data): void
    {
        ($this->sendSSE)('agent_safety_stop', $data);
    }

    public function onAgentDone(array $data): void
    {
        ($this->sendSSE)('agent_done', $data);
    }

    public function onAgentError(array $data): void
    {
        ($this->sendSSE)('agent_error', $data);
    }

    public function onMaxIterations(array $data): void
    {
        ($this->sendSSE)('agent_max_iterations', $data);
    }

    public function onContent(string $content, string $model, string $source): void
    {
        // Send chat_start before first content chunk (matches StreamController behavior)
        if (! $this->chatStartSent) {
            ($this->sendSSE)('chat_start', [
                'model' => $model,
                'source' => $source,
            ]);
            $this->chatStartSent = true;
        }

        // Send content in chunks to simulate streaming (matches StreamController)
        $chunkSize = 50;
        $offset = 0;

        while ($offset < mb_strlen($content)) {
            $chunk = mb_substr($content, $offset, $chunkSize);
            ($this->sendSSE)('content', ['text' => $chunk]);
            $offset += $chunkSize;
            usleep(10000); // 10ms delay for smooth streaming effect
        }
    }
}
