<?php

namespace Tests\Feature;

use App\Services\RedisHealthGate;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Predis\Connection\ConnectionException;
use Predis\Connection\NodeConnectionInterface;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\TimeoutException;
use Tests\TestCase;
use Throwable;

class CacheFailedOverRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::store('file')->forget('redis_health:up');

        parent::tearDown();
    }

    public function test_stale_socket_does_not_mark_healthy_redis_down_and_next_operation_uses_redis(): void
    {
        [$cache, $probe] = $this->connections(new ConnectionException(Mockery::mock(NodeConnectionInterface::class), 'Connection reset'));

        // The failing operation falls back, but a stale socket is not an outage.
        Cache::put('recovery-key', 'first', 10);

        $this->assertTrue((new RedisHealthGate)->isRedisUp());
        $this->assertSame('failover', config('cache.default'));
        Cache::put('recovery-key', 'second', 10);
        $this->assertSame('second', Cache::get('recovery-key'));
        $this->assertSame('first', Cache::store('database')->get('recovery-key'));
        $this->assertSame(1, $cache->disconnects);
        $this->assertSame(1, $probe->disconnects);
        $this->assertSame(1, $probe->pings);
        Log::shouldHaveReceived('warning')->with('Cache store failed over', [
            'store' => 'redis', 'exception' => ConnectionException::class,
        ])->once();
    }

    public function test_stale_socket_with_failed_fresh_probe_marks_gate_down(): void
    {
        [$cache, $probe] = $this->connections(new ConnectionException(Mockery::mock(NodeConnectionInterface::class), 'Connection closed'), false);

        Cache::put('recovery-key', 'first', 10);
        Cache::put('recovery-key', 'second', 10);

        $this->assertFalse((new RedisHealthGate)->isRedisUp());
        $this->assertSame('database', config('cache.default'));
        $this->assertSame('second', Cache::store('database')->get('recovery-key'));
        $this->assertSame(1, $cache->disconnects);
        $this->assertSame(1, $probe->disconnects);
        $this->assertSame(1, $probe->pings);
    }

    public function test_connect_stall_marks_down_without_another_probe(): void
    {
        $this->assertStallSwitchesWithoutProbe(new StreamInitException('Operation timed out'));
    }

    public function test_read_timeout_marks_down_without_another_probe(): void
    {
        $this->assertStallSwitchesWithoutProbe(new TimeoutException(Mockery::mock(NodeConnectionInterface::class)));
    }

    private function assertStallSwitchesWithoutProbe(Throwable $failure): void
    {
        [$cache, $probe] = $this->connections($failure);

        Cache::put('recovery-key', 'first', 10);
        Cache::put('recovery-key', 'second', 10);

        $this->assertFalse((new RedisHealthGate)->isRedisUp());
        $this->assertSame('database', config('cache.default'));
        $this->assertSame('second', Cache::get('recovery-key'));
        $this->assertSame(0, $cache->disconnects);
        $this->assertSame(0, $probe->disconnects);
        $this->assertSame(0, $probe->pings);
    }

    /** Replace only network I/O; use the real RedisStore, FailoverStore and gate. */
    private function connections(Throwable $failure, bool $reachable = true): array
    {
        Log::spy();
        $cache = new class($failure)
        {
            public int $disconnects = 0;

            public array $values = [];

            public function __construct(private Throwable $failure) {}

            public function disconnect(): void
            {
                $this->disconnects++;
            }

            public function setex($key, $ttl, $value): bool
            {
                if ($this->disconnects === 0) {
                    throw $this->failure;
                }
                $this->values[$key] = $value;

                return true;
            }

            public function get($key)
            {
                return $this->values[$key] ?? null;
            }
        };
        $probe = new class($reachable)
        {
            public int $disconnects = 0;

            public int $pings = 0;

            public function __construct(private bool $reachable) {}

            public function disconnect(): void
            {
                $this->disconnects++;
            }

            public function ping(): string
            {
                $this->pings++;
                if (! $this->reachable || $this->disconnects === 0) {
                    throw new \RuntimeException('Unreachable or stale probe socket');
                }

                return 'PONG';
            }
        };
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->with('cache')->andReturn($cache);
        $factory->shouldReceive('connection')->withNoArgs()->andReturn($probe);
        Redis::swap($factory);
        $store = new RedisStore($factory, '', 'cache');
        $this->app['cache']->extend('redis', fn () => new Repository($store));
        $this->app['cache']->forgetDriver(['redis', 'failover']);
        config(['cache.default' => 'failover']);
        Cache::store('file')->put('redis_health:up', true, 10);

        return [$cache, $probe];
    }
}
