<?php

namespace Tests\Unit\Events;

use App\Events\AdminNotification;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

class AdminNotificationTest extends TestCase
{
    public function test_event_broadcasts_on_user_notifications_channel(): void
    {
        $userId = 123;
        $event = new AdminNotification(
            userId: $userId,
            type: 'info',
            title: 'Test Title',
            message: 'Test Message'
        );

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertEquals('private-user.'.$userId.'.notifications', $channels[0]->name);
    }

    public function test_event_has_correct_broadcast_name(): void
    {
        $event = new AdminNotification(
            userId: 1,
            type: 'info',
            title: 'Test',
            message: 'Test'
        );

        $this->assertEquals('notification', $event->broadcastAs());
    }

    public function test_event_broadcasts_correct_data(): void
    {
        $event = new AdminNotification(
            userId: 1,
            type: 'handover_request',
            title: 'Human Support Requested',
            message: 'Customer needs help',
            data: ['conversation_id' => 42]
        );

        $data = $event->broadcastWith();

        $this->assertEquals('handover_request', $data['type']);
        $this->assertEquals('Human Support Requested', $data['title']);
        $this->assertEquals('Customer needs help', $data['message']);
        $this->assertEquals(['conversation_id' => 42], $data['data']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function test_handover_request_factory_method(): void
    {
        $event = AdminNotification::handoverRequest(
            userId: 1,
            conversationId: 42,
            customerName: 'John Doe'
        );

        $data = $event->broadcastWith();

        $this->assertEquals('handover_request', $data['type']);
        $this->assertEquals('Human Handover Request', $data['title']);
        $this->assertStringContainsString('John Doe', $data['message']);
        $this->assertEquals(['conversation_id' => 42], $data['data']);
    }

    public function test_new_conversation_factory_method(): void
    {
        $event = AdminNotification::newConversation(
            userId: 1,
            botId: 5,
            botName: 'My Support Bot'
        );

        $data = $event->broadcastWith();

        $this->assertEquals('new_conversation', $data['type']);
        $this->assertEquals('New Conversation', $data['title']);
        $this->assertStringContainsString('My Support Bot', $data['message']);
        $this->assertEquals(['bot_id' => 5], $data['data']);
    }
}
