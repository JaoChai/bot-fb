<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_list_user_bots(): void
    {
        Bot::factory()->count(3)->create(['user_id' => $this->user->id]);
        Bot::factory()->create(); // Another user's bot

        $response = $this->actingAs($this->user)->getJson('/api/bots');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_can_create_a_bot(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/bots', [
            'name' => 'Test Bot',
            'description' => 'A test bot',
            'channel_type' => 'line',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Test Bot')
            ->assertJsonPath('data.channel_type', 'line')
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('bots', [
            'name' => 'Test Bot',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_validates_required_fields_when_creating_bot(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/bots', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'channel_type']);
    }

    public function test_can_view_own_bot(): void
    {
        $bot = Bot::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$bot->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $bot->id);
    }

    public function test_cannot_view_other_user_bot(): void
    {
        $bot = Bot::factory()->create(); // Another user's bot

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$bot->id}");

        $response->assertForbidden();
    }

    public function test_can_update_own_bot(): void
    {
        $bot = Bot::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->putJson("/api/bots/{$bot->id}", [
            'name' => 'Updated Name',
            'status' => 'active',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.status', 'active');
    }

    public function test_cannot_update_other_user_bot(): void
    {
        $bot = Bot::factory()->create();

        $response = $this->actingAs($this->user)->putJson("/api/bots/{$bot->id}", [
            'name' => 'Hacked',
        ]);

        $response->assertForbidden();
    }

    public function test_can_delete_own_bot(): void
    {
        $bot = Bot::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->deleteJson("/api/bots/{$bot->id}");

        $response->assertOk();
        $this->assertSoftDeleted('bots', ['id' => $bot->id]);
    }

    public function test_cannot_delete_other_user_bot(): void
    {
        $bot = Bot::factory()->create();

        $response = $this->actingAs($this->user)->deleteJson("/api/bots/{$bot->id}");

        $response->assertForbidden();
    }

    public function test_can_get_webhook_url(): void
    {
        $bot = Bot::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$bot->id}/webhook-url");

        $response->assertOk()
            ->assertJsonStructure(['webhook_url', 'channel_type']);
    }

    public function test_can_regenerate_webhook_url(): void
    {
        $bot = Bot::factory()->create(['user_id' => $this->user->id]);
        $oldWebhook = $bot->webhook_url;

        $response = $this->actingAs($this->user)->postJson("/api/bots/{$bot->id}/regenerate-webhook");

        $response->assertOk();
        $this->assertNotEquals($oldWebhook, $bot->fresh()->webhook_url);
    }

    public function test_can_test_bot_with_message(): void
    {
        $bot = Bot::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson("/api/bots/{$bot->id}/test", [
            'message' => 'Hello bot!',
        ]);

        $response->assertOk()
            ->assertJsonPath('input', 'Hello bot!')
            ->assertJsonStructure(['message', 'input', 'response', 'bot_id']);
    }
}
