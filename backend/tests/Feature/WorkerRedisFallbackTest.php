<?php

namespace Tests\Feature;

use App\Services\RedisFallbackSwitch;
use App\Services\RedisHealthGate;
use Illuminate\Queue\Events\Looping;
use Mockery;
use Tests\TestCase;

class WorkerRedisFallbackTest extends TestCase
{
    private function bindGate(bool $up): void
    {
        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturn($up);
        $this->app->instance(RedisHealthGate::class, $gate);
    }

    public function test_looping_event_swaps_to_database_when_redis_down(): void
    {
        config(['cache.default' => 'redis', 'session.driver' => 'redis']);
        $this->bindGate(false);

        RedisFallbackSwitch::registerWorkerHook();
        event(new Looping('database', 'default'));

        $this->assertSame('database', config('cache.default'));
        $this->assertSame('database', config('session.driver'));
    }

    public function test_looping_event_restores_redis_after_recovery(): void
    {
        // Capture baseline while Redis is the configured driver.
        config(['cache.default' => 'redis', 'session.driver' => 'redis']);
        RedisFallbackSwitch::registerWorkerHook();

        // A prior outage already swapped this worker to database.
        config(['cache.default' => 'database', 'session.driver' => 'database']);
        $this->bindGate(true);

        event(new Looping('database', 'default'));

        $this->assertSame('redis', config('cache.default'));
        $this->assertSame('redis', config('session.driver'));
    }
}
