<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok_when_healthy(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
            ])
            ->assertJson([
                'status' => 'healthy',
            ]);
    }

    public function test_health_detailed_requires_authentication(): void
    {
        $response = $this->getJson('/api/health/detailed');

        $response->assertStatus(401);
    }

    public function test_health_detailed_returns_full_status_when_authenticated(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/health/detailed');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'checks' => [
                    'database' => ['status'],
                    'cache' => ['status'],
                    'queue' => ['status'],
                ],
                'circuit_breakers',
            ]);
    }

    public function test_health_returns_degraded_when_queue_backlog_high(): void
    {
        config(['queue.default' => 'database']);
        $user = User::factory()->create();

        $job = [
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ];
        DB::table('jobs')->insert(array_fill(0, 1000, $job));

        $this->actingAs($user)->getJson('/api/health/detailed')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.queue.pending_jobs', 1000);

        DB::table('jobs')->insert($job);

        $this->getJson('/api/health/detailed')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.queue.status', 'degraded')
            ->assertJsonPath('checks.queue.pending_jobs', 1001);

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded');

        $this->artisan('health:check', ['--json' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('degraded');
    }

    public function test_health_returns_circuit_breaker_status(): void
    {
        $user = User::factory()->create();

        // Clear any existing circuit state
        Cache::flush();

        $response = $this->actingAs($user)
            ->getJson('/api/health/detailed');

        $response->assertStatus(200);

        $circuitBreakers = $response->json('circuit_breakers');
        $this->assertIsArray($circuitBreakers);

        // Should have database circuit breaker status
        $this->assertArrayHasKey('database', $circuitBreakers);
        $this->assertEquals('closed', $circuitBreakers['database']['state']);
    }

    public function test_health_check_cli_command_returns_correct_exit_codes(): void
    {
        // Test healthy status (exit code 0)
        $this->artisan('health:check')
            ->assertExitCode(0);
    }

    public function test_health_check_cli_with_json_flag(): void
    {
        $this->artisan('health:check', ['--json' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('status');
    }

    public function test_health_check_cli_with_detailed_flag(): void
    {
        $this->artisan('health:check', ['--detailed' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Circuit Breaker Status');
    }

    public function test_health_response_includes_latency_metrics(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/health/detailed');

        $response->assertStatus(200);

        $dbCheck = $response->json('checks.database');
        $this->assertArrayHasKey('latency_ms', $dbCheck);
        $this->assertIsNumeric($dbCheck['latency_ms']);
    }
}
