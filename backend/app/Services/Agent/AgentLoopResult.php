<?php

namespace App\Services\Agent;

/**
 * AgentLoopResult - Result DTO from a completed agent loop run.
 *
 * Contains the final response content, model info, token usage, cost, and agentic metadata.
 */
readonly class AgentLoopResult
{
    public function __construct(
        public string $content,
        public string $model,
        public array $usage,
        public float $cost,
        public array $agentic,
    ) {}

    /**
     * Create an error result with fallback content.
     */
    public static function error(string $content, string $model, string $error, int $iterations = 0, int $toolCalls = 0): self
    {
        return new self(
            content: $content,
            model: $model,
            usage: ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
            cost: 0,
            agentic: [
                'iterations' => $iterations,
                'tool_calls' => $toolCalls,
                'status' => 'error',
                'error' => $error,
            ],
        );
    }
}
