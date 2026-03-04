<?php

namespace Tests\Unit\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ConversationContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ConversationContextServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConversationContextService $service;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ConversationContextService::class);
        $user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $user->id]);
    }

    public function test_auto_clear_clears_idle_conversation(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'last_message_at' => now()->subHours(7),
            'context_cleared_at' => null,
        ]);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($message) => $message === 'Auto-cleared context for idle conversation');

        $result = $this->service->autoClearIfIdle($conversation);

        $this->assertTrue($result);
        $this->assertNotNull($conversation->fresh()->context_cleared_at);
    }

    public function test_auto_clear_skips_recent_conversation(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'last_message_at' => now()->subHours(2),
            'context_cleared_at' => null,
        ]);

        $result = $this->service->autoClearIfIdle($conversation);

        $this->assertFalse($result);
        $this->assertNull($conversation->fresh()->context_cleared_at);
    }

    public function test_auto_clear_skips_new_conversation(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'last_message_at' => null,
            'context_cleared_at' => null,
        ]);

        $result = $this->service->autoClearIfIdle($conversation);

        $this->assertFalse($result);
    }

    public function test_auto_clear_skips_already_cleared(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'last_message_at' => now()->subHours(10),
            'context_cleared_at' => now()->subHours(5), // cleared after last message
        ]);

        $result = $this->service->autoClearIfIdle($conversation);

        $this->assertFalse($result);
    }

    public function test_auto_clear_respects_custom_threshold(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'last_message_at' => now()->subHours(3),
            'context_cleared_at' => null,
        ]);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($message) => $message === 'Auto-cleared context for idle conversation');

        $result = $this->service->autoClearIfIdle($conversation, idleHours: 2);

        $this->assertTrue($result);
        $this->assertNotNull($conversation->fresh()->context_cleared_at);
    }
}
