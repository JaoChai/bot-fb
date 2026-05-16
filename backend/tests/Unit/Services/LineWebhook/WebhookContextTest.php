<?php

namespace Tests\Unit\Services\LineWebhook;

use App\Models\Bot;
use App\Services\LineWebhook\WebhookContext;
use Tests\TestCase;

class WebhookContextTest extends TestCase
{
    public function test_constructor_carries_bot_and_event(): void
    {
        $bot = new Bot;
        $bot->id = 26;
        $event = ['type' => 'message', 'source' => ['userId' => 'U123']];

        $ctx = new WebhookContext($bot, $event);

        $this->assertSame(26, $ctx->bot->id);
        $this->assertSame('message', $ctx->event['type']);
        $this->assertNull($ctx->profile);
        $this->assertNull($ctx->conversation);
        $this->assertNull($ctx->userMessage);
        $this->assertNull($ctx->response);
        $this->assertSame([], $ctx->metadata);
    }

    public function test_event_type_helper(): void
    {
        $bot = new Bot;
        $ctx = new WebhookContext($bot, ['message' => ['type' => 'sticker']]);

        $this->assertSame('sticker', $ctx->messageType());
    }

    public function test_event_type_returns_null_when_missing(): void
    {
        $bot = new Bot;
        $ctx = new WebhookContext($bot, []);

        $this->assertNull($ctx->messageType());
    }

    public function test_user_id_helper(): void
    {
        $bot = new Bot;
        $ctx = new WebhookContext($bot, ['source' => ['userId' => 'U_abc']]);

        $this->assertSame('U_abc', $ctx->userId());
    }
}
