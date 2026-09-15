<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthControllerRealtimeTest extends TestCase
{
    use RefreshDatabase;

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

        $this->getJson('/api/health/realtime')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.queue.ok', true)
            ->assertJsonPath('checks.queue.depth', 99);

        DB::table('jobs')->insert($job);

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

        $this->getJson('/api/health/realtime')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.queue.ok', true)
            ->assertJsonPath('checks.queue.depth', 0);
    }
}
