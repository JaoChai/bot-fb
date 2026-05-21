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
}
