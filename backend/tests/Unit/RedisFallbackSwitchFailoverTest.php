<?php

namespace Tests\Unit;

use App\Services\RedisFallbackSwitch;
use App\Services\RedisHealthGate;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * Spec item 4: the fallback switch must treat the new `failover` cache
 * baseline exactly like `redis` — swap to database when the health gate is
 * down, restore the baseline on recovery. Review round 1 item 1 (option b):
 * the swap must also rebind the RateLimiter singleton onto the database
 * store (and back to the failover baseline on recovery), because the
 * singleton keeps its boot-time repository no matter what config says.
 */
class RedisFallbackSwitchFailoverTest extends TestCase
{
    private function switchWithGate(bool $up): RedisFallbackSwitch
    {
        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturn($up);

        return new RedisFallbackSwitch($gate);
    }

    private function limiterStore(): object
    {
        $limiter = app(RateLimiter::class);

        return Closure::bind(fn () => $this->cache->getStore(), $limiter, RateLimiter::class)();
    }

    /**
     * Rebind the RateLimiter singleton onto the given baseline store — what
     * boot did in prod when CACHE_STORE had that value (the test env boots
     * with cache.default=array, so resolve after setting the baseline).
     */
    private function resolveLimiterOnBaseline(string $baseline): void
    {
        config(['cache.default' => $baseline]);
        $this->app->forgetInstance(RateLimiter::class);
        app(RateLimiter::class);
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

    /**
     * Review round 1 item 1 (option b), failover baseline: the gate-down
     * swap must reach the RateLimiter singleton too — it keeps its boot-time
     * FailoverStore (whose FIRST store is dead Redis) no matter what
     * config('cache.default') says, so every throttle call would still try
     * Redis first (measured: 24s per webhook with a paused Redis).
     */
    public function test_apply_rebinds_the_rate_limiter_to_the_database_store_when_down(): void
    {
        $this->resolveLimiterOnBaseline('failover');
        config(['session.driver' => 'database']);
        $this->assertSame(
            Cache::store('failover')->getStore(),
            $this->limiterStore(),
            'boot binds the limiter to the failover store under CACHE_STORE=failover'
        );

        $this->switchWithGate(false)->apply();

        $this->assertSame('database', config('cache.default'));
        $this->assertSame(
            Cache::store('database')->getStore(),
            $this->limiterStore(),
            'the gate-down swap must rebind the RateLimiter singleton onto the database store'
        );
    }

    public function test_refresh_rebinds_the_rate_limiter_back_to_the_failover_store_on_recovery(): void
    {
        // A prior loop already swapped this worker to database during the outage.
        $this->resolveLimiterOnBaseline('database');
        $this->assertSame(Cache::store('database')->getStore(), $this->limiterStore());

        $this->switchWithGate(true)->refresh('failover', 'database');

        $this->assertSame('failover', config('cache.default'));
        $this->assertSame(
            Cache::store('failover')->getStore(),
            $this->limiterStore(),
            'recovery must hand the limiter its baseline (failover) store back'
        );
    }
}
