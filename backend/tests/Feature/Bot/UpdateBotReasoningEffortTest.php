<?php

// backend/tests/Feature/Bot/UpdateBotReasoningEffortTest.php

namespace Tests\Feature\Bot;

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateBotReasoningEffortTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_update_reasoning_effort(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->for($user)->create();

        $this->actingAs($user)
            ->putJson("/api/bots/{$bot->id}", ['reasoning_effort' => 'high'])
            ->assertOk()
            ->assertJsonPath('data.bot.reasoning_effort', 'high');

        $this->assertSame('high', $bot->fresh()->reasoning_effort);
    }

    public function test_rejects_invalid_reasoning_effort(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->for($user)->create();

        $this->actingAs($user)
            ->putJson("/api/bots/{$bot->id}", ['reasoning_effort' => 'ultra'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('reasoning_effort');
    }

    public function test_defaults_to_medium(): void
    {
        $bot = Bot::factory()->create();
        $this->assertSame('medium', $bot->fresh()->reasoning_effort);
    }
}
