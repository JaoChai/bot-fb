<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Flow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->owner()->create();
        $this->bot = Bot::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_can_list_bot_flows(): void
    {
        Flow::factory()->count(3)->create(['bot_id' => $this->bot->id]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/flows");

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_cannot_list_flows_for_other_user_bot(): void
    {
        $otherBot = Bot::factory()->create();

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$otherBot->id}/flows");

        $response->assertForbidden();
    }

    public function test_can_create_flow(): void
    {
        $response = $this->actingAs($this->user)->postJson("/api/bots/{$this->bot->id}/flows", [
            'name' => 'Customer Support',
            'system_prompt' => 'You are a helpful customer support agent.',
            'temperature' => 0.7,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Customer Support')
            ->assertJsonPath('data.is_default', true); // First flow is default

        $this->assertDatabaseHas('flows', [
            'bot_id' => $this->bot->id,
            'name' => 'Customer Support',
        ]);
    }

    public function test_first_flow_becomes_default(): void
    {
        $response = $this->actingAs($this->user)->postJson("/api/bots/{$this->bot->id}/flows", [
            'name' => 'First Flow',
            'system_prompt' => 'Test prompt',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_default', true);
    }

    public function test_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)->postJson("/api/bots/{$this->bot->id}/flows", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'system_prompt']);
    }

    public function test_can_view_flow(): void
    {
        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/flows/{$flow->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $flow->id);
    }

    public function test_cannot_view_flow_from_other_bot(): void
    {
        $otherBot = Bot::factory()->create(['user_id' => $this->user->id]);
        $flow = Flow::factory()->create(['bot_id' => $otherBot->id]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/flows/{$flow->id}");

        $response->assertNotFound();
    }

    public function test_can_update_flow(): void
    {
        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);

        $response = $this->actingAs($this->user)->putJson("/api/bots/{$this->bot->id}/flows/{$flow->id}", [
            'name' => 'Updated Name',
            'temperature' => 0.5,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.temperature', 0.5);
    }

    public function test_can_delete_flow(): void
    {
        Flow::factory()->count(2)->create(['bot_id' => $this->bot->id]);
        $flow = $this->bot->flows()->first();

        $response = $this->actingAs($this->user)->deleteJson("/api/bots/{$this->bot->id}/flows/{$flow->id}");

        $response->assertOk();
        $this->assertSoftDeleted('flows', ['id' => $flow->id]);
    }

    public function test_cannot_delete_only_flow(): void
    {
        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);

        $response = $this->actingAs($this->user)->deleteJson("/api/bots/{$this->bot->id}/flows/{$flow->id}");

        $response->assertUnprocessable()
            ->assertJsonPath('error', 'Cannot delete the only flow. Create another flow first.');
    }

    public function test_can_set_flow_as_default(): void
    {
        $flow1 = Flow::factory()->default()->create(['bot_id' => $this->bot->id]);
        $flow2 = Flow::factory()->create(['bot_id' => $this->bot->id]);

        $response = $this->actingAs($this->user)->postJson("/api/bots/{$this->bot->id}/flows/{$flow2->id}/set-default");

        $response->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($flow1->fresh()->is_default);
        $this->assertTrue($flow2->fresh()->is_default);
    }

    public function test_can_duplicate_flow(): void
    {
        $flow = Flow::factory()->create([
            'bot_id' => $this->bot->id,
            'name' => 'Original Flow',
            'system_prompt' => 'Original prompt',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/bots/{$this->bot->id}/flows/{$flow->id}/duplicate");

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Original Flow (Copy)')
            ->assertJsonPath('data.is_default', false);

        $this->assertEquals(2, $this->bot->flows()->count());
    }

    public function test_can_get_flow_templates(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/flow-templates');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'description', 'system_prompt', 'temperature'],
                ],
            ]);

        $templates = $response->json('data');
        $this->assertGreaterThanOrEqual(4, count($templates));
    }

    public function test_setting_new_default_unsets_old_default(): void
    {
        $flow1 = Flow::factory()->create(['bot_id' => $this->bot->id, 'is_default' => true]);

        $response = $this->actingAs($this->user)->postJson("/api/bots/{$this->bot->id}/flows", [
            'name' => 'New Default',
            'system_prompt' => 'Test prompt',
            'is_default' => true,
        ]);

        $response->assertCreated();
        $this->assertFalse($flow1->fresh()->is_default);
    }

    public function test_cannot_delete_default_flow(): void
    {
        $flow1 = Flow::factory()->default()->create(['bot_id' => $this->bot->id]);
        Flow::factory()->create(['bot_id' => $this->bot->id]);

        $response = $this->actingAs($this->user)->deleteJson("/api/bots/{$this->bot->id}/flows/{$flow1->id}");

        $response->assertUnprocessable();
        $this->assertNotSoftDeleted('flows', ['id' => $flow1->id]);
    }

    public function test_protected_prompt_and_default_flow_api_bypasses_are_rejected(): void
    {
        $bot = Bot::factory()->create(['id' => 26, 'user_id' => $this->user->id]);
        $default = Flow::factory()->create(['bot_id' => 26, 'is_default' => true]);
        $other = Flow::factory()->create(['bot_id' => 26]);
        $bot->update(['default_flow_id' => $default->id]);
        $base = '/api/bots/26/flows';
        foreach ([$default, $other] as $flow) {
            $this->actingAs($this->user)->putJson("{$base}/{$flow->id}", ['system_prompt' => 'bypass'])->assertUnprocessable();
        }
        $this->actingAs($this->user)->putJson("{$base}/{$default->id}", ['is_default' => false])->assertUnprocessable();
        $this->actingAs($this->user)->putJson("{$base}/{$other->id}", ['is_default' => true])->assertUnprocessable();
        $this->actingAs($this->user)->postJson("{$base}/{$other->id}/set-default")->assertUnprocessable();
        $this->actingAs($this->user)->postJson($base, ['name' => 'bypass', 'system_prompt' => 'new', 'is_default' => true])->assertUnprocessable();
        $this->actingAs($this->user)->putJson("{$base}/{$default->id}", ['name' => 'unrelated'])->assertOk();
        $this->actingAs($this->user)->putJson("{$base}/{$default->id}", ['system_prompt' => $default->system_prompt, 'is_default' => true])->assertOk();
        $this->actingAs($this->user)->postJson("{$base}/{$default->id}/set-default")->assertOk();
        $this->actingAs($this->user)->postJson($base, ['name' => 'nondefault', 'system_prompt' => 'new', 'is_default' => false])->assertCreated();
        $this->assertSame($default->system_prompt, $default->fresh()->system_prompt);
        $this->assertSame($default->id, $bot->fresh()->default_flow_id);
        $this->actingAs($this->user)->deleteJson("{$base}/{$default->id}")->assertUnprocessable();
    }

    public function test_protected_first_flow_cannot_change_effective_default(): void
    {
        Bot::factory()->create(['id' => 26, 'user_id' => $this->user->id]);
        $this->actingAs($this->user)->postJson('/api/bots/26/flows', ['name' => 'first', 'system_prompt' => 'bypass'])->assertUnprocessable();
        $this->assertDatabaseMissing('flows', ['bot_id' => 26]);
    }

    public function test_bot_27_retains_prompt_and_default_flow_api_behavior(): void
    {
        $bot = Bot::factory()->create(['id' => 27, 'user_id' => $this->user->id]);
        $default = Flow::factory()->create(['bot_id' => 27, 'is_default' => true]);
        $other = Flow::factory()->create(['bot_id' => 27]);
        $bot->update(['default_flow_id' => $default->id]);
        $this->actingAs($this->user)->putJson("/api/bots/27/flows/{$default->id}", ['system_prompt' => 'editable'])->assertOk();
        $this->actingAs($this->user)->postJson("/api/bots/27/flows/{$other->id}/set-default")->assertOk();
        $this->assertSame('editable', $default->fresh()->system_prompt);
        $this->assertSame($other->id, $bot->fresh()->default_flow_id);
    }
}
