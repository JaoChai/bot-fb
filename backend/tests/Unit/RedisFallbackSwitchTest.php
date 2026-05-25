<?php

namespace Tests\Unit;

use App\Services\RedisFallbackSwitch;
use App\Services\RedisHealthGate;
use Mockery;
use Tests\TestCase;

class RedisFallbackSwitchTest extends TestCase
{
    private function switchWithGate(bool $up): RedisFallbackSwitch
    {
        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturn($up);

        return new RedisFallbackSwitch($gate);
    }

    public function test_swaps_cache_and_session_to_database_when_down(): void
    {
        config(['cache.default' => 'redis', 'session.driver' => 'redis']);

        $this->switchWithGate(false)->apply();

        $this->assertSame('database', config('cache.default'));
        $this->assertSame('database', config('session.driver'));
    }

    public function test_leaves_redis_when_up(): void
    {
        config(['cache.default' => 'redis', 'session.driver' => 'redis']);

        $this->switchWithGate(true)->apply();

        $this->assertSame('redis', config('cache.default'));
        $this->assertSame('redis', config('session.driver'));
    }

    public function test_guards_are_independent(): void
    {
        config(['cache.default' => 'database', 'session.driver' => 'redis']);

        $this->switchWithGate(false)->apply();

        $this->assertSame('database', config('cache.default'));
        $this->assertSame('database', config('session.driver'));
    }

    public function test_noop_when_neither_uses_redis(): void
    {
        config(['cache.default' => 'database', 'session.driver' => 'database']);

        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldNotReceive('isRedisUp');

        (new RedisFallbackSwitch($gate))->apply();

        $this->assertSame('database', config('cache.default'));
    }

    public function test_refresh_swaps_to_database_when_down(): void
    {
        config(['cache.default' => 'redis', 'session.driver' => 'redis']);

        $this->switchWithGate(false)->refresh('redis', 'redis');

        $this->assertSame('database', config('cache.default'));
        $this->assertSame('database', config('session.driver'));
    }

    public function test_refresh_restores_baseline_when_redis_recovers(): void
    {
        // Simulate a prior loop that already swapped to database during an outage.
        config(['cache.default' => 'database', 'session.driver' => 'database']);

        $this->switchWithGate(true)->refresh('redis', 'redis');

        $this->assertSame('redis', config('cache.default'));
        $this->assertSame('redis', config('session.driver'));
    }
}
