<?php

namespace Tests\Unit\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ConversationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConversationService $service;

    private User $user;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ConversationService::class);
        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_list_conversations_returns_paginated_results(): void
    {
        Conversation::factory()->count(5)->create(['bot_id' => $this->bot->id]);

        $request = new Request;
        $result = $this->service->listConversations($this->bot, $request);

        $this->assertArrayHasKey('conversations', $result);
        $this->assertArrayHasKey('status_counts', $result);
        $this->assertEquals(5, $result['conversations']->total());
    }

    public function test_list_conversations_filters_by_status(): void
    {
        Conversation::factory()->count(3)->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
        ]);
        Conversation::factory()->count(2)->create([
            'bot_id' => $this->bot->id,
            'status' => 'closed',
        ]);

        $request = new Request(['status' => 'active']);
        $result = $this->service->listConversations($this->bot, $request);

        $this->assertEquals(3, $result['conversations']->total());
    }

    public function test_get_conversation_loads_relationships(): void
    {
        $conversation = Conversation::factory()->create(['bot_id' => $this->bot->id]);

        $result = $this->service->getConversation($conversation);

        $this->assertTrue($result->relationLoaded('customerProfile'));
        $this->assertTrue($result->relationLoaded('messages'));
    }

    public function test_update_conversation_casts_boolean(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'is_handover' => false,
        ]);

        $result = $this->service->updateConversation($conversation, ['is_handover' => true]);

        $this->assertTrue($result->is_handover);
    }

    public function test_close_conversation_updates_status(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
        ]);

        $result = $this->service->closeConversation($conversation);

        $this->assertEquals('closed', $result->status);
        $this->assertFalse($result->is_handover);
        $this->assertNull($result->assigned_user_id);
    }

    public function test_reopen_conversation_sets_active_status(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'status' => 'closed',
        ]);

        $result = $this->service->reopenConversation($conversation);

        $this->assertEquals('active', $result->status);
    }

    public function test_clear_context_sets_timestamp(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'context_cleared_at' => null,
        ]);

        $result = $this->service->clearContext($conversation);

        $this->assertNotNull($result->context_cleared_at);
    }

    public function test_clear_context_all_updates_active_conversations(): void
    {
        Conversation::factory()->count(3)->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
        ]);
        Conversation::factory()->count(2)->create([
            'bot_id' => $this->bot->id,
            'status' => 'closed',
        ]);

        $count = $this->service->clearContextAll($this->bot);

        $this->assertEquals(3, $count);
    }

    /**
     * @group postgres
     */
    public function test_get_stats_returns_correct_structure(): void
    {
        // Skip on SQLite - uses PostgreSQL-specific syntax
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('This test requires PostgreSQL');
        }

        Conversation::factory()->count(2)->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
        ]);

        $stats = $this->service->getStats($this->bot);

        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('active', $stats);
        $this->assertArrayHasKey('closed', $stats);
        $this->assertArrayHasKey('handover', $stats);
        $this->assertArrayHasKey('messages_today', $stats);
        $this->assertArrayHasKey('avg_messages_per_conversation', $stats);
        $this->assertArrayHasKey('by_channel', $stats);
    }

    /**
     * Test that listConversations returns all count types in status_counts.
     * This verifies the optimized getAllCounts() CTE query works correctly.
     *
     * @group postgres
     */
    public function test_list_conversations_returns_all_counts_structure(): void
    {
        // Skip on SQLite - uses PostgreSQL-specific syntax
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('This test requires PostgreSQL');
        }

        Conversation::factory()->count(2)->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
        ]);

        $request = new Request;
        $result = $this->service->listConversations($this->bot, $request);

        // Verify all count keys exist (from getAllCounts)
        $statusCounts = $result['status_counts'];
        $this->assertArrayHasKey('active', $statusCounts);
        $this->assertArrayHasKey('closed', $statusCounts);
        $this->assertArrayHasKey('handover', $statusCounts);
        $this->assertArrayHasKey('total', $statusCounts);
        $this->assertArrayHasKey('needs_response', $statusCounts);
        $this->assertArrayHasKey('waiting_customer', $statusCounts);

        // Verify counts are correct
        $this->assertEquals(2, $statusCounts['active']);
        $this->assertEquals(0, $statusCounts['closed']);
        $this->assertEquals(2, $statusCounts['total']);
    }

    /**
     * Test getAllCounts with various conversation statuses.
     *
     * @group postgres
     */
    public function test_list_conversations_counts_different_statuses(): void
    {
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('This test requires PostgreSQL');
        }

        // Create conversations with different statuses
        Conversation::factory()->count(3)->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
        ]);
        Conversation::factory()->count(2)->create([
            'bot_id' => $this->bot->id,
            'status' => 'closed',
        ]);
        Conversation::factory()->count(1)->create([
            'bot_id' => $this->bot->id,
            'status' => 'handover',
        ]);

        $request = new Request;
        $result = $this->service->listConversations($this->bot, $request);

        $statusCounts = $result['status_counts'];
        $this->assertEquals(3, $statusCounts['active']);
        $this->assertEquals(2, $statusCounts['closed']);
        $this->assertEquals(1, $statusCounts['handover']);
        $this->assertEquals(6, $statusCounts['total']);
    }

    /**
     * Test response counts with auto_handover bot.
     * Verifies needs_response and waiting_customer are calculated correctly.
     *
     * @group postgres
     */
    public function test_list_conversations_response_counts_with_auto_handover(): void
    {
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('This test requires PostgreSQL');
        }

        // Enable auto_handover for the bot
        $this->bot->update(['auto_handover' => true]);

        // Create conversation where last message is from user (needs_response)
        $conv1 = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
        ]);
        \App\Models\Message::factory()->create([
            'conversation_id' => $conv1->id,
            'sender' => 'user',
        ]);

        // Create conversation where last message is from bot (waiting_customer)
        $conv2 = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
        ]);
        \App\Models\Message::factory()->create([
            'conversation_id' => $conv2->id,
            'sender' => 'bot',
        ]);

        // Create conversation where last message is from agent (waiting_customer)
        $conv3 = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
        ]);
        \App\Models\Message::factory()->create([
            'conversation_id' => $conv3->id,
            'sender' => 'agent',
        ]);

        $request = new Request;
        $result = $this->service->listConversations($this->bot, $request);

        $statusCounts = $result['status_counts'];
        $this->assertEquals(1, $statusCounts['needs_response']);
        $this->assertEquals(2, $statusCounts['waiting_customer']);
    }

    /**
     * Test that counts are cached and invalidated correctly.
     *
     * @group postgres
     */
    public function test_list_conversations_counts_are_cached(): void
    {
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('This test requires PostgreSQL');
        }

        Conversation::factory()->count(2)->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
        ]);

        $request = new Request;

        // First call - should query database
        $result1 = $this->service->listConversations($this->bot, $request);
        $this->assertEquals(2, $result1['status_counts']['active']);

        // Create another conversation
        Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
        ]);

        // Second call - should return cached result (still 2)
        $result2 = $this->service->listConversations($this->bot, $request);
        $this->assertEquals(2, $result2['status_counts']['active']);

        // Clear cache and verify new count
        \Illuminate\Support\Facades\Cache::forget("bot:{$this->bot->id}:conversation:all_counts");
        $result3 = $this->service->listConversations($this->bot, $request);
        $this->assertEquals(3, $result3['status_counts']['active']);
    }
}
