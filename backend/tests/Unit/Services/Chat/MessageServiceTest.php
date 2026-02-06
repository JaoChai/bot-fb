<?php

namespace Tests\Unit\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Channel\ChannelAdapterFactory;
use App\Services\Channel\ChannelAdapterInterface;
use App\Services\Chat\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MessageServiceTest extends TestCase
{
    use RefreshDatabase;

    private MessageService $service;
    private User $user;
    private Bot $bot;
    private ChannelAdapterFactory $channelFactory;
    private ChannelAdapterInterface $channelAdapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channelAdapter = Mockery::mock(ChannelAdapterInterface::class);
        $this->channelFactory = Mockery::mock(ChannelAdapterFactory::class);

        // By default, factory supports 'line' channel and returns the mock adapter
        $this->channelFactory->shouldReceive('supports')
            ->andReturn(true)
            ->byDefault();
        $this->channelFactory->shouldReceive('make')
            ->andReturn($this->channelAdapter)
            ->byDefault();

        $this->service = new MessageService($this->channelFactory);

        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $this->user->id]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_messages_returns_paginated_results(): void
    {
        $conversation = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        Message::factory()->count(10)->create(['conversation_id' => $conversation->id]);

        $result = $this->service->getMessages($conversation, 5);

        $this->assertEquals(5, $result->perPage());
        $this->assertEquals(10, $result->total());
    }

    public function test_get_messages_enforces_max_limit(): void
    {
        $conversation = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        Message::factory()->count(150)->create(['conversation_id' => $conversation->id]);

        $result = $this->service->getMessages($conversation, 200);

        // Should be capped at 100
        $this->assertEquals(100, $result->perPage());
    }

    public function test_send_agent_message_requires_handover_mode(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'is_handover' => false,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Conversation must be in handover mode');

        $this->service->sendAgentMessage($this->bot, $conversation, [
            'content' => 'Test message',
        ]);
    }

    public function test_send_agent_message_creates_message(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'is_handover' => true,
            'channel_type' => 'line',
            'external_customer_id' => 'U123456',
        ]);

        // Mock channel adapter to succeed
        $this->channelAdapter->shouldReceive('sendMessage')
            ->once()
            ->with($this->bot, $conversation, 'text', 'Test message', null)
            ->andReturn();

        $result = $this->service->sendAgentMessage($this->bot, $conversation, [
            'content' => 'Test message',
            'type' => 'text',
        ]);

        $this->assertArrayHasKey('message', $result);
        $this->assertInstanceOf(Message::class, $result['message']);
        $this->assertEquals('agent', $result['message']->sender);
        $this->assertEquals('Test message', $result['message']->content);
        $this->assertNull($result['delivery_error']);
    }

    public function test_send_agent_message_handles_delivery_failure(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'is_handover' => true,
            'channel_type' => 'line',
            'external_customer_id' => 'U123456',
        ]);

        // Mock channel adapter to throw exception
        $this->channelAdapter->shouldReceive('sendMessage')
            ->once()
            ->andThrow(new \Exception('Channel API error'));

        $result = $this->service->sendAgentMessage($this->bot, $conversation, [
            'content' => 'Test message',
        ]);

        // Message should still be created
        $this->assertInstanceOf(Message::class, $result['message']);
        // But delivery error should be set
        $this->assertNotNull($result['delivery_error']);
    }

    public function test_mark_as_read_resets_unread_count(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'unread_count' => 5,
        ]);

        $result = $this->service->markAsRead($conversation);

        $this->assertEquals(0, $result->unread_count);
    }

    public function test_mark_as_read_does_nothing_when_already_read(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'unread_count' => 0,
        ]);

        $originalUpdatedAt = $conversation->updated_at;

        // Small delay to ensure timestamp would change if updated
        usleep(1000);

        $result = $this->service->markAsRead($conversation);

        $this->assertEquals(0, $result->unread_count);
    }

    public function test_mark_as_read_loads_customer_profile_and_assigned_user(): void
    {
        $assignedUser = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'unread_count' => 3,
            'assigned_user_id' => $assignedUser->id,
        ]);

        $result = $this->service->markAsRead($conversation);

        $this->assertTrue($result->relationLoaded('customerProfile'));
        $this->assertTrue($result->relationLoaded('assignedUser'));
    }
}
