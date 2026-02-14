<?php

namespace App\Services\Agent;

use App\Models\Bot;
use App\Models\Flow;

/**
 * AgentLoopConfig - Configuration DTO for running the agent loop.
 *
 * Immutable data object carrying all inputs needed for a single agent loop run.
 */
readonly class AgentLoopConfig
{
    public function __construct(
        public Bot $bot,
        public Flow $flow,
        public string $userMessage,
        public array $conversationHistory,
        public string $apiKey,
        public ?int $userId = null,
        public string $kbContext = '',
        public array $memoryNotes = [],
        public bool $autoRejectHitl = false,
    ) {}
}
