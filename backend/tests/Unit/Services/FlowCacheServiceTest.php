<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Flow;
use App\Services\FlowCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Wave 2 #4b: the AI reply path reads flow state through this cache service.
 * A Redis outage must degrade to the database query, not kill the reply.
 */
class FlowCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_default_flow_falls_back_to_database_when_cache_throws(): void
    {
        $bot = Bot::factory()->create();
        $flow = Flow::factory()->create([
            'bot_id' => $bot->id,
            'is_default' => true,
        ]);

        Cache::shouldReceive('remember')->andThrow(new RuntimeException('redis down'));

        $resolved = app(FlowCacheService::class)->getDefaultFlow($bot->id);

        $this->assertNotNull($resolved, 'must fall back to the database when the cache throws');
        $this->assertSame($flow->id, $resolved->id);
    }

    public function test_has_flows_falls_back_to_database_when_cache_throws(): void
    {
        $bot = Bot::factory()->create();
        Flow::factory()->create(['bot_id' => $bot->id]);

        Cache::shouldReceive('remember')->andThrow(new RuntimeException('redis down'));

        $this->assertTrue(
            app(FlowCacheService::class)->hasFlows($bot->id),
            'must fall back to the database when the cache throws'
        );
    }

    public function test_invalidate_swallows_cache_errors(): void
    {
        Cache::shouldReceive('forget')->andThrow(new RuntimeException('redis down'));

        app(FlowCacheService::class)->invalidateBot(1);
        app(FlowCacheService::class)->invalidateDefaultFlow(1);

        $this->assertTrue(true);
    }

    /**
     * Review round 1, non-blocking: a forget() failure during a Redis outage
     * leaves a stale cached flow for up to CACHE_TTL (1800 s) after an admin
     * edit. The swallow stays, but the stale window must be visible in logs.
     */
    public function test_invalidate_logs_warning_when_cache_forget_throws(): void
    {
        Cache::shouldReceive('forget')->andThrow(new RuntimeException('redis down'));
        $logged = false;
        $warnings = 0;
        Log::shouldReceive('warning')->andReturnUsing(function (string $message) use (&$logged, &$warnings): void {
            $warnings++;
            $logged = $logged || str_contains($message, 'FlowCache invalidate failed');
        });

        app(FlowCacheService::class)->invalidateBot(1);
        app(FlowCacheService::class)->invalidateDefaultFlow(1);

        $this->assertTrue($logged, 'the stale-flow window must be logged');
        $this->assertSame(3, $warnings, 'one warning per failed forget (2 keys + default flow)');
    }
}
