<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('auth');
        RateLimiter::clear('api');
    }

    public function test_auth_routes_are_rate_limited(): void
    {
        // Auth routes allow 5 attempts per minute
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'test@example.com',
                'password' => 'wrong-password',
            ]);

            // Should get 401 (unauthorized) or 422 (validation), not 429 yet
            $this->assertNotEquals(429, $response->status());
        }

        // 6th attempt should be rate limited
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertEquals(429, $response->status());
        $this->assertArrayHasKey('retry_after', $response->json());
    }

    public function test_api_routes_include_rate_limit_headers(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/bots');

        $response->assertHeader('X-RateLimit-Limit');
        $response->assertHeader('X-RateLimit-Remaining');
    }

    public function test_health_endpoint_is_not_rate_limited(): void
    {
        // Health endpoint should work without authentication or rate limiting
        for ($i = 0; $i < 100; $i++) {
            $response = $this->getJson('/api/health');
            $this->assertEquals(200, $response->status());
        }
    }

    public function test_rate_limit_response_format(): void
    {
        // Exhaust auth rate limit
        for ($i = 0; $i <= 5; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'test@example.com',
                'password' => 'wrong',
            ]);
        }

        $response->assertStatus(429);
        $response->assertJsonStructure(['message', 'retry_after']);
    }
}
