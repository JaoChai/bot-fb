<?php

namespace Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Predis\Connection\Resource\Exception\StreamInitException;
use Tests\Support\DeadRedisStoreSpy;
use Tests\TestCase;

/**
 * Spec item 5: a CacheFailedOver event becomes exactly ONE Log::warning per
 * failing streak, carrying only the store name and the exception class —
 * never a cache key or value (customer data must not leak into logs).
 */
class CacheFailedOverWarningTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_KEY = 'rf_secret_key_123';

    private const SECRET_VALUE = 'customer_secret_value';

    private DeadRedisStoreSpy $spy;

    protected function setUp(): void
    {
        parent::setUp();

        Log::spy();

        $this->spy = new DeadRedisStoreSpy;

        // Capture the spy as a LOCAL value: CacheManager::extend() rebinds the
        // creator closure to the CacheManager, so `$this` inside it is NOT the
        // test (same pattern WebhookRedisBlipTest uses).
        $spyStore = $this->spy;
        $cacheManager = $this->app['cache'];
        $cacheManager->extend('redis', fn () => new Repository($spyStore));
        $cacheManager->forgetDriver('failover');
        config(['cache.default' => 'failover']);
    }

    public function test_one_warning_per_failing_streak_without_cache_key_or_value(): void
    {
        // Streak 1: two failing operations back-to-back...
        Cache::store('failover')->get(self::SECRET_KEY);
        Cache::store('failover')->put(self::SECRET_KEY, self::SECRET_VALUE, 10);

        // ...then the redis sub-store recovers, which resets the streak...
        $this->app['cache']->extend('redis', fn () => new Repository(new ArrayStore));
        $this->app['cache']->forgetDriver('redis');
        Cache::store('failover')->get(self::SECRET_KEY);

        // ...then Redis breaks again: a new streak must warn again.
        $spyStore = $this->spy;
        $this->app['cache']->extend('redis', fn () => new Repository($spyStore));
        $this->app['cache']->forgetDriver('redis');
        Cache::store('failover')->get(self::SECRET_KEY);

        Log::shouldHaveReceived('warning')->twice();

        // Both streaks fail the same way, so both warnings carry the same
        // minimal shape: store name + exception class, no key, no value.
        Log::shouldHaveReceived('warning')->twice()->with(
            'Cache store failed over',
            Mockery::on(function (array $context): bool {
                $encoded = json_encode($context);

                return ($context['store'] ?? null) === 'redis'
                    && ($context['exception'] ?? null) === StreamInitException::class
                    && ! str_contains($encoded, self::SECRET_KEY)
                    && ! str_contains($encoded, self::SECRET_VALUE);
            }),
        );
    }
}
