<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotSupportRouterSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_set_support_router_settings_via_api(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->putJson("/api/bots/{$bot->id}", [
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
            'support_handover_message' => 'กำลังต่อสายให้ทีมซัพพอร์ต กรุณารอสักครู่',
        ]);

        $response->assertOk()->assertJsonPath('data.bot.support_router_mode', 'on');

        $this->assertSame('on', $bot->fresh()->support_router_mode);
        $this->assertSame('openai/gpt-6-luna-decisions', $bot->fresh()->support_router_model);
        $this->assertSame('กำลังต่อสายให้ทีมซัพพอร์ต กรุณารอสักครู่', $bot->fresh()->support_handover_message);
    }

    public function test_support_router_mode_defaults_to_off_in_resource(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson("/api/bots/{$bot->id}");

        $response->assertOk()
            ->assertJsonPath('data.support_router_mode', 'off')
            ->assertJsonPath('data.support_router_model', null)
            ->assertJsonPath('data.support_handover_message', null);
    }

    public function test_decisions_model_is_rejected_in_every_chat_field(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        foreach (['primary_chat_model', 'fallback_chat_model', 'utility_model'] as $field) {
            $response = $this->actingAs($user)->putJson("/api/bots/{$bot->id}", [
                $field => 'openai/gpt-6-luna-decisions',
            ]);

            $response->assertStatus(422)->assertJsonValidationErrors([$field]);
        }
    }

    public function test_non_decisions_router_model_is_rejected(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->putJson("/api/bots/{$bot->id}", [
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-4o-mini',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['support_router_model']);
    }

    public function test_mode_on_requires_handover_message(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->putJson("/api/bots/{$bot->id}", [
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['support_handover_message']);
    }

    public function test_models_endpoint_lists_decisions_model(): void
    {
        config(['services.openrouter.api_key' => 'test-key']);
        Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => []])]);

        $user = User::factory()->owner()->create();

        $response = $this->actingAs($user)->getJson('/api/models?search=decisions');

        $response->assertOk();

        $luna = collect($response->json('data'))->firstWhere('model_id', 'openai/gpt-6-luna-decisions');

        $this->assertNotNull($luna);
        $this->assertTrue($luna['is_decisions_model']);
    }
}
