<?php

namespace Tests\Unit;

use App\Services\RedisFallbackSwitch;
use App\Services\RedisHealthGate;
use Mockery;
use Tests\TestCase;

/**
 * Spec item 4: the fallback switch must treat the new `failover` cache
 * baseline exactly like `redis` — swap to database when the health gate is
 * down, restore the baseline on recovery.
 */
class RedisFallbackSwitchFailoverTest extends TestCase
{
    private function switchWithGate(bool $up): RedisFallbackSwitch
    {
        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturn($up);

        return new RedisFallbackSwitch($gate);
    }

    public function test_treats_failover_baseline_like_redis_when_down(): void
    {
        config(['cache.default' => 'failover']);

        $this->switchWithGate(false)->apply();

        $this->assertSame('database', config('cache.default'), 'a failover baseline must swap to database while Redis is down');
    }

    public function test_leaves_failover_baseline_when_up(): void
    {
        config(['cache.default' => 'failover']);

        $this->switchWithGate(true)->apply();

        $this->assertSame('failover', config('cache.default'));
    }

    public function test_refresh_restores_failover_baseline_on_recovery(): void
    {
        // A prior loop already swapped this worker to database during the outage.
        config(['cache.default' => 'database']);

        $this->switchWithGate(true)->refresh('failover', 'database');

        $this->assertSame('failover', config('cache.default'), 'the worker must restore the failover baseline, not redis');
    }

    public function test_refresh_swaps_failover_baseline_to_database_when_down(): void
    {
        config(['cache.default' => 'failover']);

        $this->switchWithGate(false)->refresh('failover', 'database');

        $this->assertSame('database', config('cache.default'));
    }
}
