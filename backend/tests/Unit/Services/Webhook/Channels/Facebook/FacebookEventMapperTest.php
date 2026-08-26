<?php

namespace Tests\Unit\Services\Webhook\Channels\Facebook;

use App\Models\Bot;
use App\Services\Webhook\Channels\Facebook\FacebookEventMapper;
use App\Services\Webhook\WebhookContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacebookEventMapperTest extends TestCase
{
    use RefreshDatabase;

    private FacebookEventMapper $mapper;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new FacebookEventMapper;
        $this->bot = Bot::factory()->make();
    }

    private function firstEvent(array $payload): array
    {
        return $payload['entry'][0]['messaging'][0];
    }

    public function test_map_text_message(): void
    {
        $payload = include base_path('tests/fixtures/facebook-text-message.php');
        $event = $this->firstEvent($payload);

        $ctx = $this->mapper->map($event, $this->bot);

        $this->assertInstanceOf(WebhookContext::class, $ctx);
        $this->assertSame('facebook', $ctx->channelType);
        $this->assertSame($this->bot, $ctx->bot);
        $this->assertSame('message', $ctx->eventType());
        $this->assertSame('text', $ctx->messageType());
        $this->assertSame('สวัสดีครับ อยากดูสินค้า', $ctx->text());
        $this->assertSame('PSID_USER_001', $ctx->metadata['sender_id']);
        $this->assertSame('PAGE_ID_001', $ctx->metadata['recipient_id']);
        $this->assertSame('wamid.mid.fb.0001', $ctx->metadata['mid']);
    }

    public function test_map_image_attachment_generates_placeholder(): void
    {
        $payload = include base_path('tests/fixtures/facebook-image-attachment.php');
        $event = $this->firstEvent($payload);

        $ctx = $this->mapper->map($event, $this->bot);

        $this->assertInstanceOf(WebhookContext::class, $ctx);
        $this->assertSame('message', $ctx->eventType());
        $this->assertSame('image', $ctx->messageType());
        $this->assertSame('[Image]', $ctx->text());
        $this->assertSame('PSID_USER_002', $ctx->metadata['sender_id']);
        $this->assertSame('https://example.com/fb-photo.jpg', $ctx->metadata['media_url']);
        $this->assertSame('image', $ctx->metadata['media_metadata']['attachment_type']);
    }

    public function test_map_postback_uses_title_and_payload(): void
    {
        $payload = include base_path('tests/fixtures/facebook-postback.php');
        $event = $this->firstEvent($payload);

        $ctx = $this->mapper->map($event, $this->bot);

        $this->assertInstanceOf(WebhookContext::class, $ctx);
        $this->assertSame('postback', $ctx->eventType());
        $this->assertSame('postback', $ctx->messageType());
        $this->assertSame('ดูเมนู', $ctx->text());
        $this->assertSame('PSID_USER_003', $ctx->metadata['sender_id']);
        $this->assertSame('BUTTON_SHOW_MENU', $ctx->metadata['postback_payload']);
    }

    public function test_map_echo_message_returns_null(): void
    {
        $payload = include base_path('tests/fixtures/facebook-text-message.php');
        $event = $this->firstEvent($payload);
        $event['message']['is_echo'] = true;

        $this->assertNull($this->mapper->map($event, $this->bot));
    }

    public function test_map_missing_sender_returns_null(): void
    {
        $payload = include base_path('tests/fixtures/facebook-text-message.php');
        $event = $this->firstEvent($payload);
        $event['sender'] = ['id' => null];

        $this->assertNull($this->mapper->map($event, $this->bot));
    }

    public function test_map_read_event_returns_null(): void
    {
        $event = [
            'sender' => ['id' => 'PSID_USER_004'],
            'recipient' => ['id' => 'PAGE_ID_001'],
            'timestamp' => 1700000300000,
            'read' => ['watermark' => 1700000300000],
        ];

        $this->assertNull($this->mapper->map($event, $this->bot));
    }

    public function test_map_delivery_event_returns_null(): void
    {
        $event = [
            'sender' => ['id' => 'PSID_USER_005'],
            'recipient' => ['id' => 'PAGE_ID_001'],
            'timestamp' => 1700000400000,
            'delivery' => ['mids' => ['wamid.mid.fb.0001']],
        ];

        $this->assertNull($this->mapper->map($event, $this->bot));
    }

    public function test_map_event_without_sender_or_recipient_returns_null(): void
    {
        $this->assertNull($this->mapper->map(['message' => ['text' => 'x']], $this->bot));
    }
}
