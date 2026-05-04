<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->owner()->create();
        $this->bot = Bot::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_conversations_sync_returns_updated_since_timestamp(): void
    {
        $old = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        DB::table('conversations')->where('id', $old->id)->update(['updated_at' => now()->subHours(2)]);

        $recent = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        DB::table('conversations')->where('id', $recent->id)->update(['updated_at' => now()->subMinutes(5)]);

        $since = now()->subHour()->toISOString();

        $response = $this->actingAs($this->user)
            ->getJson("/api/bots/{$this->bot->id}/conversations/sync?since={$since}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $recent->id);
    }

    public function test_conversations_sync_without_since_returns_latest(): void
    {
        Conversation::factory()->count(5)->create(['bot_id' => $this->bot->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/bots/{$this->bot->id}/conversations/sync");

        $response->assertOk();
        $response->assertJsonStructure(['data' => [['id', 'updated_at']]]);
    }

    public function test_conversations_sync_requires_auth(): void
    {
        $this->getJson("/api/bots/{$this->bot->id}/conversations/sync")
            ->assertUnauthorized();
    }

    public function test_messages_sync_returns_messages_after_since_id(): void
    {
        $conversation = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        $msg1 = $conversation->messages()->create([
            'sender' => 'user', 'content' => 'first', 'type' => 'text',
        ]);
        $msg2 = $conversation->messages()->create([
            'sender' => 'bot', 'content' => 'second', 'type' => 'text',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/bots/{$this->bot->id}/conversations/{$conversation->id}/messages/sync?since_id={$msg1->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $msg2->id);
    }
}
