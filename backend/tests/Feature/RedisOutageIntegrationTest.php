<?php

namespace Tests\Feature;

use App\Http\Middleware\RedisFallbackMiddleware;
use App\Services\RedisHealthGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class RedisOutageIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_uses_database_and_does_not_500_when_redis_down(): void
    {
        // Prove the middleware is wired in bootstrap/app.php (Laravel 12 style).
        $bootstrapContents = file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString(
            RedisFallbackMiddleware::class,
            $bootstrapContents,
            'RedisFallbackMiddleware must be registered in bootstrap/app.php'
        );

        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturnFalse();
        $this->app->instance(RedisHealthGate::class, $gate);

        config(['cache.default' => 'redis']);

        $response = $this->getJson('/api/health');

        $this->assertNotSame(500, $response->status());
        $this->assertSame('database', config('cache.default'));
        $this->assertTrue(Cache::store('database')->set('probe_key', 'ok', 5));
    }
}
