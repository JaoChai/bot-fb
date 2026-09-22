<?php

namespace Tests\Unit;

use App\Services\RedisHealthGate;
use App\Support\QueueRouter;
use Mockery;
use Tests\TestCase;

class QueueRouterConnectionTest extends TestCase
{
    public function test_returns_database_when_redis_down(): void
    {
        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturnFalse();
        $this->app->instance(RedisHealthGate::class, $gate);

        $this->assertSame('database', QueueRouter::connection());
    }

    public function test_returns_null_to_use_default_when_redis_up(): void
    {
        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturnTrue();
        $this->app->instance(RedisHealthGate::class, $gate);

        $this->assertNull(QueueRouter::connection());
    }

    public function test_async_connection_uses_database_when_redis_is_down(): void
    {
        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturnFalse();
        $this->app->instance(RedisHealthGate::class, $gate);

        $this->assertSame('database', QueueRouter::asyncConnection());
    }

    public function test_async_connection_uses_redis_when_redis_is_up_and_default_is_redis(): void
    {
        config(['queue.default' => 'redis']);
        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturnTrue();
        $this->app->instance(RedisHealthGate::class, $gate);

        $this->assertSame('redis', QueueRouter::asyncConnection());
    }

    public function test_async_connection_uses_database_when_redis_is_up_and_default_is_database(): void
    {
        config(['queue.default' => 'database']);
        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturnTrue();
        $this->app->instance(RedisHealthGate::class, $gate);

        $this->assertSame('database', QueueRouter::asyncConnection());
    }

    public function test_async_connection_replaces_sync_default_with_database(): void
    {
        config(['queue.default' => 'sync']);
        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturnTrue();
        $this->app->instance(RedisHealthGate::class, $gate);

        $this->assertSame('database', QueueRouter::asyncConnection());
    }

    public function test_async_connection_defaults_to_redis_when_default_is_empty(): void
    {
        config(['queue.default' => '']);
        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturnTrue();
        $this->app->instance(RedisHealthGate::class, $gate);

        $this->assertSame('redis', QueueRouter::asyncConnection());
    }
}
