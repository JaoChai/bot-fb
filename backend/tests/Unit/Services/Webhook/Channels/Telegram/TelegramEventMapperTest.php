<?php

namespace Tests\Unit\Services\Webhook\Channels\Telegram;

use App\Models\Bot;
use App\Services\Webhook\Channels\Telegram\TelegramEventMapper;
use App\Services\Webhook\WebhookContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramEventMapperTest extends TestCase
{
    use RefreshDatabase;
    private TelegramEventMapper $mapper;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new TelegramEventMapper();
        $this->bot = Bot::factory()->make();
    }

    public function test_map_text_message(): void
    {
        $update = include base_path('tests/fixtures/telegram-text-message.php');

        $ctx = $this->mapper->map($update, $this->bot);

        $this->assertInstanceOf(WebhookContext::class, $ctx);
        $this->assertSame('telegram', $ctx->channelType);
        $this->assertSame($this->bot, $ctx->bot);
        $this->assertSame('message', $ctx->eventType());
        $this->assertSame('text', $ctx->messageType());
        $this->assertSame('สวัสดีครับ อยากดูสินค้า', $ctx->text());
        $this->assertSame('777', $ctx->metadata['chat_id']);
        $this->assertSame('777', $ctx->metadata['user_id']);
        $this->assertSame('501', $ctx->metadata['message_id']);
        $this->assertSame('private', $ctx->metadata['chat_type']);
        $this->assertSame(null, $ctx->metadata['media_url']);
        $this->assertSame(null, $ctx->metadata['mime_type']);
    }

    public function test_map_photo_message_with_metadata(): void
    {
        $update = include base_path('tests/fixtures/telegram-photo-message.php');

        $ctx = $this->mapper->map($update, $this->bot);

        $this->assertInstanceOf(WebhookContext::class, $ctx);
        $this->assertSame('message', $ctx->eventType());
        $this->assertSame('photo', $ctx->messageType());
        // Caption becomes the text; a placeholder is generated only when the
        // non-text message has NO content (verbatim job logic), so here it is null.
        $this->assertSame('รูปสินค้าครับ', $ctx->text());
        $this->assertSame('888', $ctx->metadata['chat_id']);
        $this->assertSame('photo_large_id', $ctx->metadata['file_id']);
        $this->assertNull($ctx->metadata['placeholder']);
        $this->assertSame('รูปสินค้าครับ', $ctx->metadata['caption']);
        $this->assertArrayHasKey('width', $ctx->metadata['media_metadata']);
        $this->assertArrayHasKey('height', $ctx->metadata['media_metadata']);
    }

    public function test_map_photo_without_caption_uses_placeholder_text(): void
    {
        $update = include base_path('tests/fixtures/telegram-photo-message.php');
        unset($update['message']['caption']);

        $ctx = $this->mapper->map($update, $this->bot);

        $this->assertInstanceOf(WebhookContext::class, $ctx);
        $this->assertSame('[Photo]', $ctx->text());
        $this->assertSame(null, $ctx->metadata['caption']);
    }

    public function test_map_my_chat_member_returns_null(): void
    {
        $update = include base_path('tests/fixtures/telegram-my-chat-member.php');

        $this->assertNull($this->mapper->map($update, $this->bot));
    }

    public function test_map_empty_update_returns_null(): void
    {
        $this->assertNull($this->mapper->map([], $this->bot));
    }
}
