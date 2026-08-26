<?php

namespace Tests\Unit\Services\Webhook;

use App\Models\Bot;
use App\Services\Webhook\WebhookContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_accessors_pull_from_raw_event(): void
    {
        $bot = Bot::factory()->make();
        $ctx = new WebhookContext($bot, ['type' => 'message', 'text' => 'hi'], 'facebook');

        $this->assertSame('facebook', $ctx->channelType);
        $this->assertSame('message', $ctx->eventType());
        $this->assertSame('hi', $ctx->text());
        $this->assertNull($ctx->conversation);
    }

    public function test_line_shape_accessors(): void
    {
        $bot = Bot::factory()->make();
        $ctx = new WebhookContext($bot, [
            'type' => 'message',
            'replyToken' => 'tok123',
            'source' => ['userId' => 'U123'],
            'message' => ['type' => 'text', 'text' => 'hello'],
        ], 'line');

        $this->assertSame('tok123', $ctx->replyToken());
        $this->assertSame('U123', $ctx->userId());
        $this->assertSame('text', $ctx->messageType());
        $this->assertSame('hello', $ctx->text());
    }
}
