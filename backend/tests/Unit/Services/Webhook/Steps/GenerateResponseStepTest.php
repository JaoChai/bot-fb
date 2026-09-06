<?php

namespace Tests\Unit\Services\Webhook\Steps;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AIService;
use App\Services\Webhook\Steps\GenerateResponseStep;
use App\Services\Webhook\WebhookContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GenerateResponseStepTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    private Message $userMessage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bot = Bot::factory()->active()->facebook()->create(['total_messages' => 0]);
        $this->conversation = Conversation::create(['bot_id' => $this->bot->id, 'external_customer_id' => 'PSID-1', 'channel_type' => 'facebook', 'status' => 'active', 'message_count' => 1]);
        $this->userMessage = Message::create(['conversation_id' => $this->conversation->id, 'sender' => 'user', 'content' => 'hi', 'type' => 'text']);
    }

    private function ctx(array $metadata = [], ?array $rawEvent = null): WebhookContext
    {
        $ctx = new WebhookContext($this->bot, $rawEvent ?? ['type' => 'message', 'message' => ['type' => 'text', 'text' => 'hi']], 'facebook');
        $ctx->conversation = $this->conversation;
        $ctx->userMessage = $this->userMessage;
        $ctx->metadata = $metadata + ['is_handover' => false];

        return $ctx;
    }

    public function test_generates_and_bumps_bot_message_stats(): void
    {
        $botMessage = Message::create(['conversation_id' => $this->conversation->id, 'sender' => 'bot', 'content' => 'hello', 'type' => 'text']);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateAndSaveResponse')->once()->andReturn($botMessage);
        $ctx = $this->ctx();

        (new GenerateResponseStep($ai))->handle($ctx, fn () => null);

        $this->assertSame($botMessage->id, $ctx->metadata['bot_message_id']);
        $this->assertSame('hello', $ctx->metadata['bot_message']['content']);
        $this->conversation->refresh();
        $this->assertSame(2, (int) $this->conversation->message_count);
        $this->assertSame($botMessage->id, (int) $this->conversation->last_message_id);
        $this->assertSame(1, (int) $this->bot->refresh()->total_messages);
    }

    public function test_skips_when_handover(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldNotReceive('generateAndSaveResponse');
        $ctx = $this->ctx(['is_handover' => true]);
        $called = false;

        (new GenerateResponseStep($ai))->handle($ctx, function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
        $this->assertArrayNotHasKey('bot_message', $ctx->metadata);
    }

    public function test_skips_when_bot_inactive(): void
    {
        $this->bot->update(['status' => 'inactive']);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldNotReceive('generateAndSaveResponse');
        $ctx = $this->ctx();

        (new GenerateResponseStep($ai))->handle($ctx, fn () => null);
        $this->assertArrayNotHasKey('bot_message', $ctx->metadata);
    }

    public function test_skips_non_text_non_postback(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldNotReceive('generateAndSaveResponse');
        $ctx = $this->ctx([], ['type' => 'message', 'message' => ['type' => 'image']]);

        (new GenerateResponseStep($ai))->handle($ctx, fn () => null);
        $this->assertArrayNotHasKey('bot_message', $ctx->metadata);
    }

    public function test_generation_exception_is_swallowed_like_legacy(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateAndSaveResponse')->once()->andThrow(new \RuntimeException('boom'));
        $ctx = $this->ctx();
        $called = false;

        (new GenerateResponseStep($ai))->handle($ctx, function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
        $this->assertArrayNotHasKey('bot_message', $ctx->metadata);
    }
}
