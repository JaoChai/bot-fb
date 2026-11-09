<?php

namespace Tests\Unit;

use Illuminate\Redis\Connections\PredisConnection;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Predis\Connection\ConnectionException;
use Predis\Connection\NodeConnectionInterface;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\Retry\Retry;
use Predis\Retry\Strategy\ExponentialBackoff;
use Predis\TimeoutException;
use Tests\TestCase;

/**
 * Spec item 1 + review round 1 item 3 (Lead decision, option B, in this PR):
 * the app's Predis connector must build a retry that fires ONLY on connect
 * failures — StreamInitException, the 2026-11-09 incident shape. Predis's
 * DEFAULT catchable list also includes TimeoutException/ConnectionException,
 * which are raised on READ stalls after a successful connect: retrying them
 * doubles the stall latency (2.0s -> ~4.05s measured) and can re-send a lost
 * reply of a non-idempotent command (INCR, queue push eval). The connections
 * here are built through the app's registered 'predis' creator
 * (RedisManager::extend in AppServiceProvider), so the test proves the real
 * wiring, and clients stay lazy (no network) in CI.
 */
class RedisRetryConfigTest extends TestCase
{
    #[DataProvider('redisConnectionsProvider')]
    public function test_redis_connection_carries_predis_retry_with_one_retry_and_exponential_backoff(string $connection): void
    {
        $redisConnection = app('redis')->connection($connection);

        $this->assertInstanceOf(PredisConnection::class, $redisConnection);

        $retry = $redisConnection->client()->getConnection()->getParameters()->retry;

        $this->assertInstanceOf(Retry::class, $retry, 'the redis connection must be built with a Predis Retry');
        $this->assertSame(1, $retry->getRetries(), 'exactly one retry per blip');
        $this->assertInstanceOf(ExponentialBackoff::class, $retry->getStrategy());
    }

    public static function redisConnectionsProvider(): array
    {
        return [
            'default connection' => ['default'],
            'cache connection' => ['cache'],
        ];
    }

    /**
     * The incident-shaped reason for the whole card: a connect blip must be
     * absorbed by the retry, not raised to the caller.
     */
    #[DataProvider('redisConnectionsProvider')]
    public function test_config_built_retry_absorbs_one_stream_init_blip_then_succeeds(string $connection): void
    {
        $retry = app('redis')->connection($connection)->client()->getConnection()->getParameters()->retry;

        $attempts = 0;

        try {
            $value = $retry->callWithRetry(function () use (&$attempts) {
                $attempts++;

                if ($attempts === 1) {
                    // The real Sentry exception class from the 2026-11-09 incident.
                    throw new StreamInitException('Operation timed out [tcp://redis.railway.internal:6379]');
                }

                return 'value-after-blip';
            });
        } catch (StreamInitException) {
            $this->fail('a single connect blip must be absorbed by the configured retry, but the exception escaped');
        }

        $this->assertSame('value-after-blip', $value, 'a single connect blip must be absorbed, not raised');
        $this->assertSame(2, $attempts);
    }

    /**
     * The Lead decision: READ stalls (TimeoutException after a successful
     * connect) must NOT be retried — one attempt, then the exception escapes
     * so the FailoverStore can fail over without doubling the stall latency
     * or re-sending a non-idempotent command whose reply was lost.
     */
    #[DataProvider('redisConnectionsProvider')]
    public function test_read_timeout_is_not_retried(string $connection): void
    {
        $retry = app('redis')->connection($connection)->client()->getConnection()->getParameters()->retry;

        $attempts = 0;

        try {
            $retry->callWithRetry(function () use (&$attempts) {
                $attempts++;

                throw new TimeoutException(Mockery::mock(NodeConnectionInterface::class));
            });
        } catch (TimeoutException) {
            // expected: escapes on the FIRST attempt
        }

        $this->assertSame(1, $attempts, 'a read timeout must not be retried: the reply may already have side effects and the stall must not double');
    }

    /** ConnectionException raised on a read after connect: NOT retried either. */
    #[DataProvider('redisConnectionsProvider')]
    public function test_connection_exception_on_a_read_is_not_retried(string $connection): void
    {
        $retry = app('redis')->connection($connection)->client()->getConnection()->getParameters()->retry;

        $attempts = 0;

        try {
            $retry->callWithRetry(function () use (&$attempts) {
                $attempts++;

                throw new ConnectionException(Mockery::mock(NodeConnectionInterface::class), 'Error while reading line from the server');
            });
        } catch (ConnectionException) {
            // expected: escapes on the FIRST attempt
        }

        $this->assertSame(1, $attempts, 'a post-connect ConnectionException must not be retried');
    }

    public function test_retry_catches_only_stream_init_failures(): void
    {
        foreach (['default', 'cache'] as $connection) {
            $retry = app('redis')->connection($connection)->client()->getConnection()->getParameters()->retry;

            $catchable = (new \ReflectionProperty(Retry::class, 'catchableExceptions'))->getValue($retry);

            $this->assertSame(
                [StreamInitException::class],
                $catchable,
                "the {$connection} connection retry must catch ONLY connect failures (StreamInitException), not Predis's default Timeout/Connection/StreamInit list"
            );
        }
    }

    public function test_backoff_uses_microsecond_units_with_jitter_within_the_connect_budget(): void
    {
        $retry = app('redis')->connection('default')->client()->getConnection()->getParameters()->retry;

        $this->assertSame(1, $retry->getRetries(), 'exactly one retry per blip');

        $strategy = $retry->getStrategy();
        $this->assertInstanceOf(ExponentialBackoff::class, $strategy);

        $this->assertSame(50_000, $strategy->getBase(), 'backoff base is 50 ms in MICROSECONDS');
        $this->assertSame(200_000, $strategy->getCap(), 'backoff cap is 200 ms in MICROSECONDS');

        $jitter = (new \ReflectionProperty($strategy, 'withJitter'))->getValue($strategy);
        $this->assertTrue($jitter, 'backoff must be jittered to avoid thundering-herd reconnects');

        // With 1 retry only compute(0) = rand * base <= base is ever used.
        // Worst-case connect: timeout + biggest backoff + timeout.
        $timeout = (float) config('database.redis.default.timeout');
        $worstBackoff = $strategy->getBase() / 1_000_000;
        $worstCase = $timeout + $worstBackoff + $timeout;
        $this->assertLessThanOrEqual(
            1.1,
            $worstCase,
            "worst-case connect {$timeout}s + {$worstBackoff}s + {$timeout}s = {$worstCase}s must stay under the ~1.1s budget"
        );
        $this->assertSame(0.5, config('database.redis.default.timeout'), 'code default REDIS_TIMEOUT drops to 0.5s');
    }

    public function test_env_still_overrides_the_timeout_and_backoff(): void
    {
        $_ENV['REDIS_TIMEOUT'] = '2.0';
        $_SERVER['REDIS_TIMEOUT'] = '2.0';
        $_ENV['REDIS_MAX_RETRIES'] = '3';
        $_SERVER['REDIS_MAX_RETRIES'] = '3';

        try {
            $config = require config_path('database.php');

            $this->assertSame(2.0, $config['redis']['default']['timeout']);
            $this->assertSame(3, $config['redis']['default']['max_retries'] ?? null);
        } finally {
            unset($_ENV['REDIS_TIMEOUT'], $_SERVER['REDIS_TIMEOUT'], $_ENV['REDIS_MAX_RETRIES'], $_SERVER['REDIS_MAX_RETRIES']);
        }
    }

    public function test_config_remains_cacheable(): void
    {
        $cached = app()->getCachedConfigPath();

        try {
            $this->artisan('config:cache')->assertExitCode(0);
            $this->assertFileExists($cached, 'config must stay cacheable for the Docker boot');
        } finally {
            $this->artisan('config:clear');
        }

        $this->assertFileDoesNotExist($cached);
    }
}
