<?php

namespace Tests\Unit\Services\Webhook\Steps;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\FlowPluginService;
use App\Services\Webhook\Steps\FlowPluginStep;
use App\Services\Webhook\WebhookContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class FlowPluginStepTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bot = Bot::factory()->active()->facebook()->create();
        $this->conversation = Conversation::create(['bot_id' => $this->bot->id, 'external_customer_id' => 'PSID-1', 'channel_type' => 'facebook', 'status' => 'active', 'message_count' => 1]);
    }

    private function ctx(array $metadata = []): WebhookContext
    {
        $ctx = new WebhookContext($this->bot, ['type' => 'message', 'message' => ['type' => 'text', 'text' => 'hi']], 'facebook');
        $ctx->conversation = $this->conversation;
        $ctx->metadata = $metadata;

        return $ctx;
    }

    public function test_runs_plugins_when_bot_message_was_persisted(): void
    {
        $botMessage = Message::create(['conversation_id' => $this->conversation->id, 'sender' => 'bot', 'content' => 'hello', 'type' => 'text']);
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldReceive('executePlugins')
            ->once()
            ->with(
                Mockery::on(fn ($bot) => $bot->is($this->bot)),
                Mockery::on(fn ($conversation) => $conversation->is($this->conversation)),
                Mockery::on(fn ($message) => $message->id === $botMessage->id)
            );
        $ctx = $this->ctx(['bot_message_id' => $botMessage->id]);
        $called = false;

        (new FlowPluginStep($plugins))->handle($ctx, function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function test_skips_when_no_bot_message_id(): void
    {
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldNotReceive('executePlugins');
        $ctx = $this->ctx();
        $called = false;

        (new FlowPluginStep($plugins))->handle($ctx, function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function test_swallows_plugin_exception_and_continues(): void
    {
        $botMessage = Message::create(['conversation_id' => $this->conversation->id, 'sender' => 'bot', 'content' => 'hello', 'type' => 'text']);
        Log::shouldReceive('warning')->once();
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldReceive('executePlugins')->once()->andThrow(new \RuntimeException('boom'));
        $ctx = $this->ctx(['bot_message_id' => $botMessage->id]);
        $called = false;

        (new FlowPluginStep($plugins))->handle($ctx, function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }
}
