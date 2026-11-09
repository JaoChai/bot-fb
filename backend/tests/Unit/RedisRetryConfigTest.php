<?php

namespace Tests\Unit;

use Illuminate\Redis\Connectors\PredisConnector;
use PHPUnit\Framework\Attributes\DataProvider;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\Retry\Retry;
use Predis\Retry\Strategy\ExponentialBackoff;
use Tests\TestCase;

/**
 * Spec item 1: Predis retry on connect blips for redis.default and
 * redis.cache — array form (config:cache safe), 1 retry, jittered
 * exponential backoff in MICROSECONDS, worst-case connect <= ~1.1 s.
 */
class RedisRetryConfigTest extends TestCase
{
    #[DataProvider('redisConnectionsProvider')]
    public function test_redis_connection_carries_predis_retry_with_one_retry_and_exponential_backoff(string $connection): void
    {
        $connector = new PredisConnector;

        $connection = $connector->connect(config("database.redis.{$connection}"), []);

        $retry = $connection->client()->getConnection()->getParameters()->retry;

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

    public function test_backoff_uses_microsecond_units_with_jitter_within_the_connect_budget(): void
    {
        $connector = new PredisConnector;
        $connection = $connector->connect(config('database.redis.default'), []);

        $retry = $connection->client()->getConnection()->getParameters()->retry;

        $this->assertSame(1, $retry->getRetries(), 'exactly one retry per blip');

        $strategy = $retry->getStrategy();
        $this->assertInstanceOf(ExponentialBackoff::class, $strategy);

        $this->assertSame(50_000, $strategy->getBase(), 'backoff base is 50 ms in MICROSECONDS');
        $this->assertSame(200_000, $strategy->getCap(), 'backoff cap is 200 ms in MICROSECONDS');

        $jitter = (new \ReflectionProperty($strategy, 'withJitter'))->getValue($strategy);
        $this->assertTrue($jitter, 'backoff must be jittered to avoid thundering-herd reconnects');

        // Worst-case connect: timeout + biggest backoff + timeout.
        $timeout = (float) config('database.redis.default.timeout');
        $worstBackoff = min($strategy->getCap(), $strategy->getBase()) / 1_000_000;
        $this->assertLessThanOrEqual(
            1.1,
            $timeout + $worstBackoff + $timeout,
            "worst-case connect {$timeout}s + {$worstBackoff}s + {$timeout}s must stay under the ~1.1s budget"
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

    public function test_config_built_retry_absorbs_one_stream_init_blip_then_succeeds(): void
    {
        $connector = new PredisConnector;
        $connection = $connector->connect(config('database.redis.default'), []);
        $retry = $connection->client()->getConnection()->getParameters()->retry;

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
