<?php

namespace Tests\Unit\Services\Webhook\Steps;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Webhook\Steps\DedupUserMessageStep;
use App\Services\Webhook\WebhookContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DedupUserMessageStepTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'facebook', 'status' => 'active']);
        $this->conversation = Conversation::create([
            'bot_id' => $this->bot->id,
            'external_customer_id' => 'PSID-1',
            'channel_type' => 'facebook',
            'status' => 'active',
        ]);
    }

    private function ctx(string $channel, array $metadata): WebhookContext
    {
        $ctx = new WebhookContext($this->bot, ['type' => 'message', 'message' => ['type' => 'text', 'text' => 'hi']], $channel);
        $ctx->conversation = $this->conversation;
        $ctx->metadata = $metadata;

        return $ctx;
    }

    public function test_duplicate_facebook_mid_short_circuits(): void
    {
        Message::create(['conversation_id' => $this->conversation->id, 'sender' => 'user', 'content' => 'old', 'type' => 'text', 'external_message_id' => 'dup']);
        $called = false;

        (new DedupUserMessageStep)->handle($this->ctx('facebook', ['mid' => 'dup']), function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
    }

    public function test_duplicate_telegram_message_id_short_circuits(): void
    {
        Message::create(['conversation_id' => $this->conversation->id, 'sender' => 'user', 'content' => 'old', 'type' => 'text', 'external_message_id' => '77']);
        $called = false;

        (new DedupUserMessageStep)->handle($this->ctx('telegram', ['message_id' => 77]), function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
    }

    public function test_new_message_continues(): void
    {
        $called = false;

        (new DedupUserMessageStep)->handle($this->ctx('facebook', ['mid' => 'fresh']), function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function test_missing_external_id_continues_without_querying(): void
    {
        $called = false;

        (new DedupUserMessageStep)->handle($this->ctx('facebook', ['mid' => null]), function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
        $this->assertNull(DedupUserMessageStep::externalMessageId($this->ctx('facebook', ['mid' => ''])));
        $this->assertSame('77', DedupUserMessageStep::externalMessageId($this->ctx('telegram', ['message_id' => 77])));
    }
}
