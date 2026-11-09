<?php

namespace App\Services;

use Illuminate\Redis\Connectors\PredisConnector;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\Retry\Retry;

/**
 * Predis connector whose retry catches ONLY connect failures:
 * StreamInitException — the 2026-11-09 incident shape (review round 1
 * item 3, Lead decision B).
 *
 * Laravel's PredisConnector::formatRetry() builds `new Retry(strategy,
 * retries)` with Predis's DEFAULT catchable list, which also includes
 * TimeoutException and ConnectionException. Those are raised on READ stalls
 * after a successful connect: retrying them doubles the stall latency
 * (2.0s -> ~4.05s measured) and can re-send a lost reply of a
 * non-idempotent command (INCR, queue push eval). A read failure must
 * escape on the first attempt so the cache failover store can fail over
 * without a second dead-Redis round-trip.
 *
 * Registered over the `predis` driver in AppServiceProvider::register()
 * via RedisManager::extend('predis', ...) — the config files keep the
 * scalar `'retry' => [ExponentialBackoff::class => [...]]` array form, so
 * `php artisan config:cache` still works (no objects in config).
 */
class StreamInitRetryPredisConnector extends PredisConnector
{
    /**
     * Exceptions a command may be retried on. Overrides Predis Retry's
     * default catchable list (TimeoutException, ConnectionException,
     * StreamInitException).
     */
    public const RETRY_CATCHABLE = [StreamInitException::class];

    /**
     * Let the parent turn the scalar retry config into a Retry instance,
     * then rebuild that instance with the restricted catchable list. The
     * strategy and retry count come from config unchanged.
     */
    protected function formatRetry(array $config): array
    {
        $config = parent::formatRetry($config);

        if (($config['retry'] ?? null) instanceof Retry) {
            $config['retry'] = new Retry(
                $config['retry']->getStrategy(),
                $config['retry']->getRetries(),
                self::RETRY_CATCHABLE,
            );
        }

        return $config;
    }
}
