<?php

namespace App\Support;

use App\Services\RedisHealthGate;

/**
 * Resolves the queue name LLM-bound jobs should be dispatched to.
 *
 * Queue names here must stay in sync with the worker --queue= lists in
 * backend/Dockerfile (supervisor blocks).
 */
class QueueRouter
{
    public const QUEUE_LLM = 'llm';

    public const QUEUE_WEBHOOKS = 'webhooks';

    public static function llmQueue(): string
    {
        return config('queue.llm_split_enabled') ? self::QUEUE_LLM : self::QUEUE_WEBHOOKS;
    }

    /**
     * Connection to dispatch to: 'database' while Redis is down so jobs land on
     * a queue the always-on worker-db drains; null otherwise to use the default.
     */
    public static function connection(): ?string
    {
        return app(RedisHealthGate::class)->isRedisUp() ? null : 'database';
    }

    /**
     * Resolve an asynchronous connection for best-effort jobs.
     *
     * The sync driver is never safe for plugin work because it executes inline.
     */
    public static function asyncConnection(): string
    {
        if (self::connection() === 'database') {
            return 'database';
        }

        $default = config('queue.default');
        if ($default === 'sync') {
            return 'database';
        }

        return $default ?: 'redis';
    }
}
