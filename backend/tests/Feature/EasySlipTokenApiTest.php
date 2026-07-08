<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EasySlipTokenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_show_and_clear_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/settings/easyslip', ['token' => 'es-token-9876'])
            ->assertOk();

        $show = $this->actingAs($user)->getJson('/api/settings');
        $show->assertOk()
            ->assertJsonPath('data.easyslip_configured', true);
        $this->assertStringEndsWith('9876', $show->json('data.easyslip_token_masked'));

        $this->actingAs($user)->deleteJson('/api/settings/easyslip')->assertOk();
        $this->actingAs($user)->getJson('/api/settings')
            ->assertJsonPath('data.easyslip_configured', false);
    }

    public function test_test_connection_returns_quota(): void
    {
        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['easyslip_api_token' => 'es-token-1']);

        Http::fake([
            'developer.easyslip.com/api/v1/me' => Http::response([
                'status' => 200,
                'data' => ['application' => 'bot-fb', 'usedQuota' => 16, 'maxQuota' => 250, 'remainingQuota' => 234],
            ]),
        ]);

        $this->actingAs($user)->postJson('/api/settings/test-easyslip')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('quota.remaining', 234);
    }

    public function test_test_connection_without_token_fails(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/settings/test-easyslip')
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_test_connection_invalid_token(): void
    {
        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['easyslip_api_token' => 'bad-token']);

        Http::fake([
            'developer.easyslip.com/api/v1/me' => Http::response(['status' => 401, 'message' => 'unauthorized'], 401),
        ]);

        $this->actingAs($user)->postJson('/api/settings/test-easyslip')
            ->assertOk()
            ->assertJsonPath('success', false);
    }
}
