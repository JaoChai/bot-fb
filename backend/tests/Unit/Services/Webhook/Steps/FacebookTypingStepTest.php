<?php

namespace Tests\Unit\Services\Webhook\Steps;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\FacebookService;
use App\Services\Webhook\Steps\Facebook\FacebookTypingStep;
use App\Services\Webhook\WebhookContext;
use Mockery;
use Tests\TestCase;

class FacebookTypingStepTest extends TestCase
{
    private function ctx(array $metadata, string $messageType = 'text', string $botStatus = 'active'): WebhookContext
    {
        $bot = new Bot(['id' => 1, 'status' => $botStatus]);
        $ctx = new WebhookContext($bot, ['type' => 'message', 'message' => ['type' => $messageType, 'text' => 'hi']], 'facebook');
        $ctx->conversation = new Conversation(['id' => 5]);
        $ctx->userMessage = new Message(['id' => 9, 'content' => 'hi', 'type' => $messageType]);
        $ctx->metadata = $metadata + ['sender_id' => 'PSID-1', 'is_handover' => false];

        return $ctx;
    }

    public function test_typing_on_is_sent_only_when_a_reply_will_be_generated(): void
    {
        $fb = Mockery::mock(FacebookService::class);
        $fb->shouldReceive('sendTypingIndicator')->once()->with(Mockery::type(Bot::class), 'PSID-1', 'typing_on');
        $called = false;

        (new FacebookTypingStep($fb, 'typing_on'))->handle($this->ctx([]), function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function test_typing_on_is_skipped_on_handover_inactive_bot_or_image(): void
    {
        $fb = Mockery::mock(FacebookService::class);
        $fb->shouldNotReceive('sendTypingIndicator');

        (new FacebookTypingStep($fb, 'typing_on'))->handle($this->ctx(['is_handover' => true]), fn () => null);
        (new FacebookTypingStep($fb, 'typing_on'))->handle($this->ctx([], 'text', 'inactive'), fn () => null);
        (new FacebookTypingStep($fb, 'typing_on'))->handle($this->ctx([], 'image'), fn () => null);

        $this->addToAssertionCount(1);
    }

    public function test_typing_off_is_sent_only_after_a_bot_message_was_produced(): void
    {
        $fb = Mockery::mock(FacebookService::class);
        $fb->shouldReceive('sendTypingIndicator')->once()->with(Mockery::type(Bot::class), 'PSID-1', 'typing_off');

        (new FacebookTypingStep($fb, 'typing_off'))->handle($this->ctx(['bot_message_id' => 42]), fn () => null);
        (new FacebookTypingStep($fb, 'typing_off'))->handle($this->ctx([]), fn () => null); // generation failed → stays on, like legacy

        $this->addToAssertionCount(1);
    }
}
