<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotUtilityModelApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_set_utility_model_via_api(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->putJson("/api/bots/{$bot->id}", [
            'utility_model' => 'anthropic/claude-3-haiku',
        ]);

        $response->assertOk()->assertJsonPath('data.bot.utility_model', 'anthropic/claude-3-haiku');
        $this->assertSame('anthropic/claude-3-haiku', $bot->fresh()->utility_model);
    }

    public function test_bot_resource_returns_utility_model_field(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'utility_model' => 'anthropic/claude-3-haiku',
        ]);

        $response = $this->actingAs($user)->getJson("/api/bots/{$bot->id}");

        $response->assertOk()->assertJsonPath('data.utility_model', 'anthropic/claude-3-haiku');
    }
}
