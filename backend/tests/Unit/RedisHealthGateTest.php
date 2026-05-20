<?php

namespace Tests\Unit;

use App\Services\RedisHealthGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RedisHealthGateTest extends TestCase
{
    public function test_reports_up_when_ping_succeeds(): void
    {
        Cache::store('file')->forget('redis_health:up');
        Redis::shouldReceive('connection->ping')->andReturn('PONG');

        $gate = new RedisHealthGate;

        $this->assertTrue($gate->isRedisUp());
    }

    public function test_reports_down_when_ping_throws(): void
    {
        Cache::store('file')->forget('redis_health:up');
        Redis::shouldReceive('connection->ping')
            ->andThrow(new \RuntimeException('Connection timed out'));

        $gate = new RedisHealthGate;

        $this->assertFalse($gate->isRedisUp());
    }

    public function test_memoizes_within_request(): void
    {
        Cache::store('file')->forget('redis_health:up');
        Redis::shouldReceive('connection->ping')->once()->andReturn('PONG');

        $gate = new RedisHealthGate;

        $gate->isRedisUp();
        $gate->isRedisUp();
    }

    public function test_does_not_throw_and_falls_back_to_probe_when_file_cache_throws(): void
    {
        Cache::shouldReceive('store')
            ->with('file')
            ->andReturnSelf();
        Cache::shouldReceive('remember')
            ->andThrow(new \RuntimeException('disk full'));

        Redis::shouldReceive('connection->ping')->andReturn('PONG');

        $gate = new RedisHealthGate;

        $this->assertTrue($gate->isRedisUp());
    }
}
