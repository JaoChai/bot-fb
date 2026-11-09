<?php

namespace Tests\Unit;

use App\Services\RedisFallbackSwitch;
use App\Services\RedisHealthGate;
use Closure;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
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

    /**
     * The store the RateLimiter singleton currently fronts (the property is
     * protected on purpose — read it bound to the limiter's scope).
     */
    private function limiterStore(): object
    {
        $limiter = app(RateLimiter::class);

        return Closure::bind(fn () => $this->cache->getStore(), $limiter, RateLimiter::class)();
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

    /**
     * Review round 1 item 1, option (b): the RateLimiter singleton keeps the
     * cache repository it was built with at boot — the config switch cannot
     * reach it — so every throttle middleware (webhook, api, auth, bot-test,
     * uploads) would keep calling dead Redis behind the gate-down swap. The
     * switch must rebind the singleton onto the database store, in place, so
     * counting continues instead of failing open or stalling.
     */
    public function test_apply_rebinds_the_rate_limiter_to_the_database_store_when_down(): void
    {
        $this->resolveLimiterOnBaseline('redis');
        config(['session.driver' => 'redis']);
        $this->assertInstanceOf(RedisStore::class, $this->limiterStore(), 'boot binds the limiter to the redis store');

        $this->switchWithGate(false)->apply();

        $this->assertSame('database', config('cache.default'));
        $this->assertInstanceOf(
            DatabaseStore::class,
            $this->limiterStore(),
            'the gate-down swap must rebind the RateLimiter singleton onto the database store'
        );
    }

    public function test_refresh_rebinds_the_rate_limiter_back_to_the_baseline_store_on_recovery(): void
    {
        $this->resolveLimiterOnBaseline('database');
        $this->assertInstanceOf(DatabaseStore::class, $this->limiterStore());

        $this->switchWithGate(true)->refresh('redis', 'redis');

        $this->assertSame('redis', config('cache.default'));
        $this->assertInstanceOf(
            RedisStore::class,
            $this->limiterStore(),
            'recovery must hand the limiter its baseline (redis) store back'
        );
    }

    public function test_rebinding_keeps_the_registered_named_limiters(): void
    {
        config(['cache.default' => 'redis', 'session.driver' => 'redis']);

        $limiterBefore = app(RateLimiter::class);
        $this->assertNotNull($limiterBefore->limiter('webhook'), 'boot registers the webhook named limiter');

        $this->switchWithGate(false)->apply();

        $limiterAfter = app(RateLimiter::class);
        $this->assertSame(
            $limiterBefore,
            $limiterAfter,
            'the rebind must be in-place on the boot instance — the instance is what carries the named limiters'
        );
        $this->assertSame(
            Cache::store('database')->getStore(),
            $this->limiterStore(),
            'the rebind must actually swap the limiter onto the database store'
        );
    }
}
