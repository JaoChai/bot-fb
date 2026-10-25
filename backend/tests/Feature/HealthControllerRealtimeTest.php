<?php

namespace Tests\Feature;

use App\Console\Commands\CheckRealtimeHealth;
use App\Services\Health\RealtimeHealthProbe;
use App\Services\Health\RealtimeHealthSnapshotStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthControllerRealtimeTest extends TestCase
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

    private function seedSnapshot(bool $live = true, int $ageSeconds = 0): void
    {
        $this->store->write([
            'checked_at' => now()->subSeconds($ageSeconds)->toIso8601String(),
            'broadcasting' => [
                'driver' => config('broadcasting.default'),
                'live' => $live,
                'error_class' => null,
                'latency_ms' => 12,
            ],
            'queue' => [
                'backlog' => [(string) config('queue.default') => ['default' => 0]],
                'failed_recent_5m' => 0,
                'failed_total' => 0,
            ],
        ]);
    }

    /**
     * Run the real snapshot command with the Reverb probe replaced by a fake
     * that reports the given liveness (no network in tests).
     */
    private function runSnapshotCommand(bool $live = true): void
    {
        $this->app->instance(RealtimeHealthProbe::class, new class($live) extends RealtimeHealthProbe
        {
            public function __construct(private readonly bool $fakeLive) {}

            public function check(): array
            {
                return [
                    'live' => $this->fakeLive,
                    'error_class' => $this->fakeLive ? null : 'RuntimeException',
                    'latency_ms' => 5,
                ];
            }
        });

        $this->artisan(CheckRealtimeHealth::class)->assertExitCode(0);
    }

    public function test_realtime_health_returns_json(): void
    {
        $response = $this->getJson('/api/health/realtime');
        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'checks' => [
                'broadcasting' => ['ok'],
                'queue' => ['ok', 'depth', 'failed'],
            ],
        ]);
    }

    public function test_realtime_health_becomes_degraded_at_queue_backlog_threshold(): void
    {
        config(['broadcasting.default' => 'reverb']);

        $job = [
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ];
        DB::table('jobs')->insert(array_fill(0, 99, $job));

        // The card changes the status rule: without a snapshot the endpoint is
        // degraded by design (a dead scheduler must be visible), so "healthy"
        // states now require the snapshot pipeline to have run.
        $this->runSnapshotCommand();

        $this->getJson('/api/health/realtime')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.queue.ok', true)
            ->assertJsonPath('checks.queue.depth', 99);

        DB::table('jobs')->insert($job);
        $this->runSnapshotCommand();

        $this->getJson('/api/health/realtime')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.queue.ok', false)
            ->assertJsonPath('checks.queue.depth', 100);
    }

    public function test_realtime_health_reports_failed_jobs_as_unhealthy_queue(): void
    {
        config(['broadcasting.default' => 'reverb']);

        for ($i = 0; $i < 10; $i++) {
            if ($i === 9) {
                $this->runSnapshotCommand();

                $this->getJson('/api/health/realtime')
                    ->assertOk()
                    ->assertJsonPath('status', 'healthy')
                    ->assertJsonPath('checks.queue.ok', true)
                    ->assertJsonPath('checks.queue.failed', 9);
            }

            DB::table('failed_jobs')->insert([
                'uuid' => 'health-test-'.$i,
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'Test failure',
                'failed_at' => now(),
            ]);
        }

        $this->runSnapshotCommand();

        $this->getJson('/api/health/realtime')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.queue.ok', false)
            ->assertJsonPath('checks.queue.failed', 10);
    }

    public function test_realtime_health_is_degraded_when_broadcasting_is_disabled(): void
    {
        config(['broadcasting.default' => 'null']);

        $this->getJson('/api/health/realtime')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.broadcasting.ok', false)
            ->assertJsonPath('checks.queue.ok', true);
    }

    public function test_realtime_health_does_not_count_future_jobs_as_ready_backlog(): void
    {
        config(['broadcasting.default' => 'reverb']);

        DB::table('jobs')->insert(array_fill(0, 100, [
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->addHour()->timestamp,
            'created_at' => now()->timestamp,
        ]));

        $this->runSnapshotCommand();

        $this->getJson('/api/health/realtime')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.queue.ok', true)
            ->assertJsonPath('checks.queue.depth', 0);
    }

    public function test_failing_probe_snapshot_makes_broadcasting_live_false_and_status_degraded(): void
    {
        config(['broadcasting.default' => 'reverb']);
        $this->seedSnapshot(live: false);

        $this->getJson('/api/health/realtime')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.broadcasting.ok', true)
            ->assertJsonPath('checks.broadcasting.live', false);
    }

    public function test_snapshot_older_than_180_seconds_is_degraded(): void
    {
        config(['broadcasting.default' => 'reverb']);
        $this->seedSnapshot(live: true, ageSeconds: 181);

        $this->getJson('/api/health/realtime')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.broadcasting.live', true);

        $age = $this->getJson('/api/health/realtime')->json('checks.broadcasting.age_seconds');
        $this->assertIsInt($age);
        $this->assertGreaterThanOrEqual(181, $age);
    }

    public function test_missing_snapshot_is_degraded_with_null_broadcast_details(): void
    {
        config(['broadcasting.default' => 'reverb']);

        $response = $this->getJson('/api/health/realtime');

        $response->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.broadcasting.live', null)
            ->assertJsonPath('checks.broadcasting.checked_at', null)
            ->assertJsonPath('checks.broadcasting.age_seconds', null);
    }

    public function test_295_old_failed_jobs_with_live_probe_are_healthy(): void
    {
        config(['broadcasting.default' => 'reverb']);

        DB::table('failed_jobs')->insert(array_map(fn (int $i): array => [
            'uuid' => "old-failure-{$i}",
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Historical failure',
            'failed_at' => now()->subMinutes(30),
        ], range(1, 295)));

        $this->runSnapshotCommand(live: true);

        $this->getJson('/api/health/realtime')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.queue.ok', true)
            ->assertJsonPath('checks.queue.failed', 295)
            ->assertJsonPath('checks.queue.failed_total', 295)
            ->assertJsonPath('checks.queue.failed_recent_5m', 0)
            ->assertJsonPath('checks.broadcasting.live', true);
    }

    public function test_ten_failures_in_the_last_five_minutes_are_degraded(): void
    {
        config(['broadcasting.default' => 'reverb']);

        DB::table('failed_jobs')->insert(array_map(fn (int $i): array => [
            'uuid' => "recent-failure-{$i}",
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Fresh failure',
            'failed_at' => now(),
        ], range(1, 10)));

        $this->runSnapshotCommand(live: true);

        $this->getJson('/api/health/realtime')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.queue.ok', false)
            ->assertJsonPath('checks.queue.failed_total', 10)
            ->assertJsonPath('checks.queue.failed_recent_5m', 10);
    }

    public function test_all_legacy_keys_are_present_with_a_fresh_healthy_snapshot(): void
    {
        config(['broadcasting.default' => 'reverb']);
        $this->seedSnapshot(live: true);

        $this->getJson('/api/health/realtime')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonStructure([
                'status',
                'checks' => [
                    'broadcasting' => ['ok', 'driver', 'live', 'checked_at', 'age_seconds', 'latency_ms'],
                    'queue' => ['ok', 'depth', 'failed', 'connection', 'failed_recent_5m', 'failed_total', 'backlog'],
                ],
            ]);
    }

    public function test_health_check_realtime_is_scheduled_every_minute_without_overlap(): void
    {
        $this->artisan('schedule:list');

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'health:check-realtime'));

        $this->assertNotNull($event, 'health:check-realtime is not scheduled');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertFalse($event->runInBackground);
    }
}
