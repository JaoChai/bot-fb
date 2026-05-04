<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
