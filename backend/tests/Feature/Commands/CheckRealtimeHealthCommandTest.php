<?php

namespace Tests\Feature\Commands;

use App\Console\Commands\CheckRealtimeHealth;
use App\Services\Health\RealtimeHealthProbe;
use App\Services\Health\RealtimeHealthSnapshotStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CheckRealtimeHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    private RealtimeHealthSnapshotStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new RealtimeHealthSnapshotStore;
        @unlink($this->store->path());
    }

    protected function tearDown(): void
    {
        @unlink($this->store->path());

        parent::tearDown();
    }

    private function bindLiveProbe(): void
    {
        $this->app->instance(RealtimeHealthProbe::class, new class extends RealtimeHealthProbe
        {
            public function check(): array
            {
                return ['live' => true, 'error_class' => null, 'latency_ms' => 4];
            }
        });
    }

    public function test_writes_snapshot_when_redis_is_unreachable(): void
    {
        // Simulate a dead Redis: both the cache store and the queue connection
        // point at Redis. The manager-level binding throws, so the queue
        // connection cannot even be resolved (phpredis silently reports 0 on
        // a dead server, so a dead-port config is not deterministic). The
        // command must not touch the cache at all and must survive the queue
        // sampling failure.
        config([
            'cache.default' => 'redis',
            'queue.default' => 'redis',
        ]);
        $this->app->bind('redis', fn () => throw new \RuntimeException('Redis is unreachable'));
        $this->app->instance('cache', new class
        {
            public function __call(string $name, array $arguments): mixed
            {
                throw new \RuntimeException('Redis is unreachable (cache trap)');
            }
        });

        $this->bindLiveProbe();

        DB::table('failed_jobs')->insert([
            [
                'uuid' => 'recent-one',
                'connection' => 'redis',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'Fresh',
                'failed_at' => now(),
            ],
            [
                'uuid' => 'old-one',
                'connection' => 'redis',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'Old',
                'failed_at' => now()->subHour(),
            ],
        ]);

        $this->artisan(CheckRealtimeHealth::class)->assertExitCode(0);

        $snapshot = $this->store->read();
        $this->assertNotNull($snapshot, 'the command must write a snapshot even with Redis down');
        $this->assertTrue($snapshot['broadcasting']['live']);
        $this->assertSame(2, $snapshot['queue']['failed_total']);
        $this->assertSame(1, $snapshot['queue']['failed_recent_5m']);

        // Redis queues could not be sampled: recorded as null, not fabricated.
        $redisBacklog = $snapshot['queue']['backlog']['redis'];
        $this->assertSame(['llm', 'webhooks', 'default', 'low'], array_keys($redisBacklog));
        $this->assertNull($redisBacklog['llm']);
        $this->assertNull($redisBacklog['webhooks']);
        $this->assertNull($redisBacklog['default']);
        $this->assertNull($redisBacklog['low']);

        // SQL jobs table is still reachable and sampled.
        $this->assertSame(0, $snapshot['queue']['backlog']['database']['ready']);
    }

    public function test_records_live_false_when_the_probe_fails(): void
    {
        $this->app->instance(RealtimeHealthProbe::class, new class extends RealtimeHealthProbe
        {
            public function check(): array
            {
                return ['live' => false, 'error_class' => 'RuntimeException', 'latency_ms' => 3000];
            }
        });

        $this->artisan(CheckRealtimeHealth::class)->assertExitCode(0);

        $snapshot = $this->store->read();
        $this->assertNotNull($snapshot);
        $this->assertFalse($snapshot['broadcasting']['live']);
        $this->assertSame('RuntimeException', $snapshot['broadcasting']['error_class']);
        $this->assertSame(0, $snapshot['queue']['failed_total']);
        $this->assertArrayHasKey('checked_at', $snapshot);
    }
}
