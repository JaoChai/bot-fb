<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Queue\Events\QueuePaused;
use Illuminate\Queue\Events\QueueResumed;
use Illuminate\Queue\Events\QueuesPaused;
use Illuminate\Queue\Events\QueuesResumed;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\QueueServiceProvider;

/**
 * QueueManager whose pause/resume state lives in the worker-control cache
 * store (the `cache.store` binding, bound to the redis-independent database
 * store in AppServiceProvider) instead of the default store.
 *
 * Laravel's QueueManager reads and writes pause state through the default
 * cache store. With prod's CACHE_STORE=redis that state becomes unreadable
 * during a Redis outage, and `queue:pause`/`queue:resume` would disagree
 * with the worker's pause checks. Routing the pause plane through the same
 * Redis-independent store as the restart signal keeps them coherent.
 *
 * Connectors are re-registered through QueueServiceProvider (the same
 * registrations the framework's `queue` binding performs) so this class is
 * a drop-in replacement for it.
 */
class WorkerControlQueueManager extends QueueManager
{
    public function __construct($app)
    {
        parent::__construct($app);

        $provider = new QueueServiceProvider($app);
        (new \ReflectionMethod($provider, 'registerConnectors'))->invoke($provider, $this);
    }

    /**
     * The Redis-independent store the queue worker control plane uses.
     */
    private function controlStore(): Repository
    {
        return $this->app['cache.store'];
    }

    public function pause($connection, $queue)
    {
        $this->controlStore()->forever("illuminate:queue:paused:{$connection}:{$queue}", true);

        $this->app['events']->dispatch(
            new QueuePaused($connection, $queue)
        );
    }

    public function pauseFor($connection, $queue, $ttl)
    {
        $this->controlStore()->put("illuminate:queue:paused:{$connection}:{$queue}", true, $ttl);

        $this->app['events']->dispatch(
            new QueuePaused($connection, $queue, $ttl)
        );
    }

    public function pauseAll()
    {
        $this->controlStore()->forever('illuminate:queues:paused', true);

        $this->app['events']->dispatch(
            new QueuesPaused
        );
    }

    public function resume($connection, $queue)
    {
        $this->controlStore()->forget("illuminate:queue:paused:{$connection}:{$queue}");

        $this->app['events']->dispatch(
            new QueueResumed($connection, $queue)
        );
    }

    public function resumeAll()
    {
        $this->controlStore()->forget('illuminate:queues:paused');

        $this->app['events']->dispatch(
            new QueuesResumed
        );
    }

    public function isPaused($connection, $queue)
    {
        $cache = $this->controlStore();

        return (bool) ($cache->get('illuminate:queues:paused')
            ?: $cache->get("illuminate:queue:paused:{$connection}:{$queue}"));
    }

    public function getPausedQueues($connection, $queues)
    {
        $cache = $this->controlStore();

        if ($cache->get('illuminate:queues:paused')) {
            return array_values($queues);
        }

        $states = $cache->many(
            array_map(fn ($queue) => "illuminate:queue:paused:{$connection}:{$queue}", $queues)
        );

        return array_values(array_filter(
            $queues, fn ($queue) => $states["illuminate:queue:paused:{$connection}:{$queue}"] ?? false
        ));
    }
}
