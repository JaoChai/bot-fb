<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Flow;
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
        $this->user = User::factory()->owner()->create();
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
            ->assertJsonPath('data.bot.name', 'Test Bot')
            ->assertJsonPath('data.bot.channel_type', 'line')
            ->assertJsonPath('data.bot.status', 'inactive');

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
            ->assertJsonPath('data.bot.name', 'Updated Name')
            ->assertJsonPath('data.bot.status', 'active');
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
            ->assertJsonStructure(['data' => ['webhook_url', 'channel_type']]);
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
            ->assertJsonPath('data.input', 'Hello bot!')
            ->assertJsonPath('data.bot_id', $bot->id)
            ->assertJsonStructure(['data' => ['input', 'response', 'bot_id']]);
    }

    public function test_protected_bot_rejects_prompt_and_default_changes_but_allows_unrelated_edits(): void
    {
        $bot = Bot::factory()->create(['id' => 26, 'user_id' => $this->user->id]);
        $flow = Flow::factory()->create(['bot_id' => 26, 'is_default' => true]);
        $other = Flow::factory()->create(['bot_id' => 26]);
        $bot->update(['default_flow_id' => $flow->id]);
        foreach ([['system_prompt' => 'override'], ['default_flow_id' => $other->id], ['default_flow_id' => null]] as $changes) {
            $this->actingAs($this->user)->putJson('/api/bots/26', $changes)->assertUnprocessable();
        }
        $this->actingAs($this->user)->putJson('/api/bots/26', ['name' => 'unrelated', 'default_flow_id' => $flow->id, 'system_prompt' => null])->assertOk();
        $this->assertNull($bot->fresh()->system_prompt);
        $this->assertSame($flow->id, $bot->fresh()->default_flow_id);
        $this->assertSame('unrelated', $bot->fresh()->name);
    }

    public function test_bot_27_prompt_and_default_flow_remain_editable(): void
    {
        $bot = Bot::factory()->create(['id' => 27, 'user_id' => $this->user->id]);
        $flow = Flow::factory()->create(['bot_id' => 27]);
        $this->actingAs($this->user)->putJson('/api/bots/27', ['system_prompt' => 'editable', 'default_flow_id' => $flow->id])->assertOk();
        $this->assertSame('editable', $bot->fresh()->system_prompt);
        $this->assertSame($flow->id, $bot->fresh()->default_flow_id);
    }
}
