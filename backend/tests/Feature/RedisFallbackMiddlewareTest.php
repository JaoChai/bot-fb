<?php

namespace Tests\Feature;

use App\Http\Middleware\RedisFallbackMiddleware;
use App\Services\RedisHealthGate;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class RedisFallbackMiddlewareTest extends TestCase
{
    public function test_swaps_to_database_when_redis_down(): void
    {
        config(['cache.default' => 'redis', 'session.driver' => 'redis']);

        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturnFalse();

        (new RedisFallbackMiddleware($gate))->handle(Request::create('/'), fn ($r) => response('ok'));

        $this->assertSame('database', config('cache.default'));
        $this->assertSame('database', config('session.driver'));
    }

    public function test_keeps_redis_when_up(): void
    {
        config(['cache.default' => 'redis', 'session.driver' => 'redis']);

        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturnTrue();

        (new RedisFallbackMiddleware($gate))->handle(Request::create('/'), fn ($r) => response('ok'));

        $this->assertSame('redis', config('cache.default'));
        $this->assertSame('redis', config('session.driver'));
    }
}
