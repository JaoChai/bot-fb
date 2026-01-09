<?php

namespace Tests\Unit\Events;

use App\Events\MessageSent;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageSentTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_broadcasts_on_correct_channels(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->for($user)->create();
        $conversation = Conversation::factory()->for($bot)->create();
        $message = Message::factory()->for($conversation)->create([
            'sender' => 'user',
            'content' => 'Hello, world!',
            'type' => 'text',
        ]);

        $event = new MessageSent($message);
        $channels = $event->broadcastOn();

        $this->assertCount(2, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertInstanceOf(PrivateChannel::class, $channels[1]);

        // Check channel names
        $channelNames = array_map(fn ($c) => $c->name, $channels);
        $this->assertContains('private-conversation.'.$conversation->id, $channelNames);
        $this->assertContains('private-bot.'.$bot->id, $channelNames);
    }

    public function test_event_has_correct_broadcast_name(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->for($user)->create();
        $conversation = Conversation::factory()->for($bot)->create();
        $message = Message::factory()->for($conversation)->create();

        $event = new MessageSent($message);

        $this->assertEquals('message.sent', $event->broadcastAs());
    }

    public function test_event_broadcasts_correct_data(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->for($user)->create();
        $conversation = Conversation::factory()->for($bot)->create();
        $message = Message::factory()->for($conversation)->create([
            'sender' => 'bot',
            'content' => 'Hello!',
            'type' => 'text',
            'media_url' => null,
            'media_type' => null,
        ]);

        $event = new MessageSent($message);
        $data = $event->broadcastWith();

        $this->assertEquals($message->id, $data['id']);
        $this->assertEquals($conversation->id, $data['conversation_id']);
        $this->assertEquals('bot', $data['sender']);
        $this->assertEquals('Hello!', $data['content']);
        $this->assertEquals('text', $data['type']);
        $this->assertNull($data['media_url']);
        $this->assertNull($data['media_type']);
        $this->assertArrayHasKey('created_at', $data);
    }
}
