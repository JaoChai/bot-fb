<?php

namespace Tests\Feature\Api;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationAssignmentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggle_handover_without_auto_enable_minutes_sets_permanent_disable(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($owner);

        $bot = Bot::factory()->create(['user_id' => $owner->id]);
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'is_handover' => false,
            'status' => 'active',
            'bot_auto_enable_at' => null,
        ]);

        $response = $this->postJson("/api/bots/{$bot->id}/conversations/{$conversation->id}/toggle-handover");

        $response->assertStatus(200);

        $fresh = $conversation->fresh();
        $this->assertTrue($fresh->is_handover);
        $this->assertNull($fresh->bot_auto_enable_at);
        $this->assertSame('handover', $fresh->status);
    }

    public function test_toggle_handover_with_explicit_minutes_sets_timer(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($owner);

        $bot = Bot::factory()->create(['user_id' => $owner->id]);
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'is_handover' => false,
            'status' => 'active',
            'bot_auto_enable_at' => null,
        ]);

        $before = now();

        $response = $this->postJson(
            "/api/bots/{$bot->id}/conversations/{$conversation->id}/toggle-handover",
            ['auto_enable_minutes' => 30]
        );

        $response->assertStatus(200);

        $fresh = $conversation->fresh();
        $this->assertTrue($fresh->is_handover);
        $this->assertNotNull($fresh->bot_auto_enable_at);

        $expected = $before->copy()->addMinutes(30);
        $this->assertEqualsWithDelta(
            $expected->timestamp,
            $fresh->bot_auto_enable_at->timestamp,
            10,
            'bot_auto_enable_at should be ~30 minutes from request time'
        );
    }

    public function test_toggle_handover_with_explicit_zero_sets_permanent_disable(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($owner);

        $bot = Bot::factory()->create(['user_id' => $owner->id]);
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'is_handover' => false,
            'status' => 'active',
            'bot_auto_enable_at' => null,
        ]);

        $response = $this->postJson(
            "/api/bots/{$bot->id}/conversations/{$conversation->id}/toggle-handover",
            ['auto_enable_minutes' => 0]
        );

        $response->assertStatus(200);

        $fresh = $conversation->fresh();
        $this->assertTrue($fresh->is_handover);
        $this->assertNull($fresh->bot_auto_enable_at);
        $this->assertSame('handover', $fresh->status);
    }

    public function test_toggle_handover_re_enable_clears_timer(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($owner);

        $bot = Bot::factory()->create(['user_id' => $owner->id]);
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'is_handover' => true,
            'status' => 'handover',
            'assigned_user_id' => $owner->id,
            'bot_auto_enable_at' => now()->addMinutes(15),
        ]);

        $response = $this->postJson("/api/bots/{$bot->id}/conversations/{$conversation->id}/toggle-handover");

        $response->assertStatus(200);

        $fresh = $conversation->fresh();
        $this->assertFalse($fresh->is_handover);
        $this->assertNull($fresh->bot_auto_enable_at);
        $this->assertSame('active', $fresh->status);
    }
}
