<?php

namespace Tests\Unit;

use Illuminate\Cache\FailoverStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DeadRedisStoreSpy;
use Tests\TestCase;

/**
 * Spec item 2: the failover cache store becomes [redis, database]. When the
 * redis store throws (the 2026-11-09 Sentry shape), reads/writes must land
 * on the database store.
 */
class CacheFailoverStoreConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_failover_store_is_redis_then_database(): void
    {
        $this->assertSame(
            ['redis', 'database'],
            config('cache.stores.failover.stores'),
            'failover must try redis first, then fall back to the database store'
        );
        $this->assertSame('failover', config('cache.stores.failover.driver'));
    }

    public function test_failover_store_reads_and_writes_through_database_when_redis_throws(): void
    {
        $spy = new DeadRedisStoreSpy;

        $cacheManager = $this->app['cache'];
        $cacheManager->extend('redis', fn () => new \Illuminate\Cache\Repository($spy));
        $cacheManager->forgetDriver('failover');

        config(['cache.default' => 'failover']);

        $store = Cache::store('failover')->getStore();
        $this->assertInstanceOf(FailoverStore::class, $store);

        Cache::store('failover')->put('rf_probe_key', 'rf_probe_value', 60);
        $this->assertSame('rf_probe_value', Cache::store('failover')->get('rf_probe_key'));

        $this->assertGreaterThanOrEqual(2, $spy->touches, 'the dead redis store must actually be attempted (and failed over)');
    }
}
