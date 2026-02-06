<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API Contract Tests
 *
 * Validates that API response structures remain consistent.
 * If these tests fail, it means the API contract has changed
 * and frontend may break.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->owner()->create();
    }

    public function test_health_endpoint_returns_expected_structure(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'timestamp',
            ]);
    }

    public function test_login_returns_expected_structure(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('test-password'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'test-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'role',
                ],
                'token',
                'token_type',
            ]);
    }

    public function test_auth_user_returns_expected_structure(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/auth/user');

        $response->assertOk()
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'name',
                    'email',
                    'role',
                ],
            ]);
    }

    public function test_bots_index_returns_expected_structure(): void
    {
        Bot::factory()->count(2)->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/bots');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'status',
                        'channel_type',
                    ],
                ],
            ]);
    }

    public function test_bot_show_returns_expected_structure(): void
    {
        $bot = Bot::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/bots/{$bot->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'description',
                    'status',
                    'channel_type',
                ],
            ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/auth/user');

        $response->assertUnauthorized()
            ->assertJsonStructure([
                'message',
            ]);
    }

    public function test_login_with_invalid_credentials_returns_error_structure(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors',
            ]);
    }

    public function test_validation_error_returns_expected_structure(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => '',
            'password' => '',
        ]);

        $response->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors',
            ]);
    }
}
