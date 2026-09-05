<?php

namespace Tests\Unit\Services\Webhook\Steps;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Webhook\Steps\PersistUserMessageStep;
use App\Services\Webhook\WebhookContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PersistUserMessageStepTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'facebook', 'status' => 'active', 'total_messages' => 0, 'total_conversations' => 0]);
        $this->conversation = Conversation::create([
            'bot_id' => $this->bot->id,
            'external_customer_id' => 'PSID-1',
            'channel_type' => 'facebook',
            'status' => 'active',
        ]);
    }

    private function ctx(array $metadata, string $eventType = 'message', ?string $text = 'hi'): WebhookContext
    {
        $raw = $eventType === 'postback'
            ? ['postback' => ['payload' => $metadata['postback_payload'] ?? 'P', 'title' => $metadata['postback_title'] ?? null]]
            : ['message' => ['type' => 'text', 'text' => $text]];
        $ctx = new WebhookContext($this->bot, $raw + ['type' => $eventType], 'facebook');
        $ctx->conversation = $this->conversation;
        $ctx->metadata = $metadata + ['is_new_conversation' => false, 'sender_id' => 'PSID-1', 'media_url' => null, 'media_metadata' => null];

        return $ctx;
    }

    public function test_saves_text_message_and_bumps_conversation_and_bot_stats(): void
    {
        $ctx = $this->ctx(['mid' => 'm-1']);
        $called = false;

        (new PersistUserMessageStep)->handle($ctx, function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
        $this->assertNotNull($ctx->userMessage);
        $this->assertDatabaseHas('messages', ['conversation_id' => $this->conversation->id, 'sender' => 'user', 'content' => 'hi', 'external_message_id' => 'm-1']);
        $this->conversation->refresh();
        $this->assertSame(1, (int) $this->conversation->unread_count);
        $this->assertSame(1, (int) $this->conversation->message_count);
        $this->assertSame($ctx->userMessage->id, (int) $this->conversation->last_message_id);
        $this->bot->refresh();
        $this->assertSame(1, (int) $this->bot->total_messages);
        $this->assertNotNull($this->bot->last_active_at);
    }

    public function test_new_conversation_increments_bot_total_conversations(): void
    {
        $ctx = $this->ctx(['mid' => 'm-2', 'is_new_conversation' => true]);

        (new PersistUserMessageStep)->handle($ctx, fn () => null);

        $this->assertSame(1, (int) $this->bot->refresh()->total_conversations);
    }

    public function test_facebook_postback_saves_title_as_content_with_postback_type(): void
    {
        // The messages.type enum is widened with 'postback' only on pgsql/mysql
        // (2026_08_26_000000); sqlite cannot ALTER a CHECK in place, so this
        // end-to-end save skips there — same convention as
        // tests/Unit/Jobs/ProcessFacebookWebhookPostbackTest.php.
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped("messages.type widened only on pgsql/mysql — sqlite can't ALTER CHECK (mirrors ProcessFacebookWebhookPostbackTest convention)");
        }

        $ctx = $this->ctx(['mid' => null, 'postback_payload' => 'BUY_NOW', 'postback_title' => 'ซื้อเลย'], 'postback', null);

        (new PersistUserMessageStep)->handle($ctx, fn () => null);

        $this->assertDatabaseHas('messages', ['conversation_id' => $this->conversation->id, 'type' => 'postback', 'content' => 'ซื้อเลย']);
        $this->assertSame(['postback_payload' => 'BUY_NOW'], $ctx->userMessage->media_metadata);
    }

    public function test_telegram_placeholder_and_media_are_used_when_present(): void
    {
        // Telegram contexts carry channelType 'telegram'; built directly rather
        // than through the Facebook helper above.
        $tg = new WebhookContext($this->bot, ['message' => ['type' => 'image']], 'telegram');
        $tg->conversation = $this->conversation;
        $tg->metadata = [
            'message_id' => 77,
            'reply_to_message_id' => null,
            'media' => ['url' => 'https://s3/x.jpg', 'mime_type' => 'image/jpeg', 'metadata' => ['file_id' => 'f1']],
            'placeholder' => '[Photo]',
        ];

        (new PersistUserMessageStep)->handle($tg, fn () => null);

        $this->assertDatabaseHas('messages', ['conversation_id' => $this->conversation->id, 'content' => '[Photo]', 'type' => 'image', 'media_url' => 'https://s3/x.jpg', 'media_type' => 'image/jpeg', 'external_message_id' => '77']);
    }
}
