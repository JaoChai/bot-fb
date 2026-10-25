<?php

namespace Tests\Unit;

use App\Services\RedisHealthGate;
use Illuminate\Support\Carbon;
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

    public function test_mark_down_forces_false_in_same_instance_without_probing(): void
    {
        Cache::store('file')->forget('redis_health:up');
        Cache::store('file')->put('redis_health:up', true, 10);
        Redis::shouldReceive('connection->ping')->never();

        $gate = new RedisHealthGate;

        $gate->markDown();

        $this->assertFalse($gate->isRedisUp());
    }

    public function test_mark_down_is_visible_to_a_fresh_instance_without_probing(): void
    {
        Cache::store('file')->forget('redis_health:up');
        Redis::shouldReceive('connection->ping')->never();

        (new RedisHealthGate)->markDown();

        $this->assertFalse((new RedisHealthGate)->isRedisUp());
    }

    public function test_mark_down_expires_after_health_ttl_and_probes_again(): void
    {
        config(['redis-fallback.health_ttl' => 1]);
        Cache::store('file')->forget('redis_health:up');
        Redis::shouldReceive('connection->ping')->andReturn('PONG');

        $gate = new RedisHealthGate;

        $gate->markDown();
        $this->assertFalse($gate->isRedisUp());

        Carbon::setTestNow(Carbon::now()->addSeconds(2));

        $this->assertTrue((new RedisHealthGate)->isRedisUp());
    }

    public function test_mark_down_keeps_memo_when_file_cache_write_throws(): void
    {
        Cache::shouldReceive('store')
            ->with('file')
            ->andReturnSelf();
        Cache::shouldReceive('put')
            ->andThrow(new \RuntimeException('disk full'));
        Redis::shouldReceive('connection->ping')->never();

        $gate = new RedisHealthGate;

        $gate->markDown();

        $this->assertFalse($gate->isRedisUp());
    }
}
