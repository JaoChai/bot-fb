<?php

namespace App\Support;

use App\Services\RedisHealthGate;
use Predis\Connection\ConnectionException;
use Predis\PredisException;
use RedisException;

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

    /**
     * Dispatch an LLM-bound webhook job on connection()/llmQueue(), falling
     * back to the database queue when Redis dies mid-dispatch (2026-10-22
     * incident). The SAME job object is dispatched exactly once — either to
     * the resolved connection, or once to 'database' on the same queue after
     * marking the health gate down.
     */
    public static function dispatchLlm(object $job, ?\DateTimeInterface $delay = null): void
    {
        $job->onConnection(self::connection())
            ->onQueue(self::llmQueue());

        if ($delay !== null) {
            $job->delay($delay);
        }

        try {
            dispatch($job);
        } catch (PredisException|ConnectionException|RedisException) {
            app(RedisHealthGate::class)->markDown();

            $job->onConnection('database')
                ->onQueue(self::llmQueue());

            dispatch($job);
        }
    }
}
