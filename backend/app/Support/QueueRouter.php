<?php

namespace App\Support;

/**
 * Resolves the queue name LLM-bound jobs should be dispatched to.
 *
 * Queue names here must stay in sync with the worker --queue= lists in
 * backend/Dockerfile (supervisor blocks) and backend/Procfile.
 */
class QueueRouter
{
    public const QUEUE_LLM = 'llm';

    public const QUEUE_WEBHOOKS = 'webhooks';

    public static function llmQueue(): string
    {
        return config('queue.llm_split_enabled') ? self::QUEUE_LLM : self::QUEUE_WEBHOOKS;
    }
}
