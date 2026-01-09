<?php

namespace Tests\Unit\Events;

use App\Events\ConversationUpdated;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationUpdatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_broadcasts_on_correct_channels(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->for($user)->create();
        $conversation = Conversation::factory()->for($bot)->create();

        $event = new ConversationUpdated($conversation, 'updated');
        $channels = $event->broadcastOn();

        $this->assertCount(2, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertInstanceOf(PrivateChannel::class, $channels[1]);

        $channelNames = array_map(fn ($c) => $c->name, $channels);
        $this->assertContains('private-conversation.'.$conversation->id, $channelNames);
        $this->assertContains('private-bot.'.$bot->id, $channelNames);
    }

    public function test_event_broadcast_name_includes_update_type(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->for($user)->create();
        $conversation = Conversation::factory()->for($bot)->create();

        $event = new ConversationUpdated($conversation, 'created');
        $this->assertEquals('conversation.created', $event->broadcastAs());

        $event = new ConversationUpdated($conversation, 'handover');
        $this->assertEquals('conversation.handover', $event->broadcastAs());

        $event = new ConversationUpdated($conversation, 'message_received');
        $this->assertEquals('conversation.message_received', $event->broadcastAs());
    }

    public function test_event_broadcasts_correct_data(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->for($user)->create();
        $conversation = Conversation::factory()->for($bot)->create([
            'status' => 'active',
            'is_handover' => false,
            'assigned_user_id' => null,
            'message_count' => 5,
        ]);

        $event = new ConversationUpdated($conversation, 'message_received');
        $data = $event->broadcastWith();

        $this->assertEquals($conversation->id, $data['id']);
        $this->assertEquals($bot->id, $data['bot_id']);
        $this->assertEquals('active', $data['status']);
        $this->assertFalse($data['is_handover']);
        $this->assertNull($data['assigned_user_id']);
        $this->assertEquals(5, $data['message_count']);
        $this->assertEquals('message_received', $data['update_type']);
        $this->assertArrayHasKey('updated_at', $data);
    }

    public function test_handover_conversation_includes_assigned_user(): void
    {
        $owner = User::factory()->create();
        $agent = User::factory()->create();
        $bot = Bot::factory()->for($owner)->create();
        $conversation = Conversation::factory()->for($bot)->create([
            'is_handover' => true,
            'assigned_user_id' => $agent->id,
        ]);

        $event = new ConversationUpdated($conversation, 'handover');
        $data = $event->broadcastWith();

        $this->assertTrue($data['is_handover']);
        $this->assertEquals($agent->id, $data['assigned_user_id']);
    }
}
