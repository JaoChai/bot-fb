<?php

namespace Tests\Unit\Services\LineWebhook;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\FlowPluginService;
use App\Services\LeadRecoveryService;
use App\Services\LINEService;
use App\Services\LineWebhook\LineWebhookOutputService;
use App\Services\LineWebhook\ResponseEnvelope;
use App\Services\LineWebhook\WebhookContext;
use App\Services\MultipleBubblesService;
use App\Services\PaymentFlexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class LineWebhookOutputServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Event builders
    // -------------------------------------------------------------------------

    private function makeTextEvent(string $userId = 'U_test'): array
    {
        return [
            'type' => 'message',
            'replyToken' => 'rt_test',
            'source' => ['userId' => $userId],
            'message' => ['type' => 'text', 'text' => 'hello', 'id' => 'msg_001'],
            'webhookEventId' => 'evt_001',
            'timestamp' => 1700000000000,
            'deliveryContext' => ['isRedelivery' => false],
        ];
    }

    private function makeStickerEvent(): array
    {
        return [
            'type' => 'message',
            'replyToken' => 'rt_sticker',
            'source' => ['userId' => 'U_test'],
            'message' => ['type' => 'sticker', 'id' => 'stk_001', 'sticker_id' => '1', 'package_id' => '1'],
            'webhookEventId' => 'evt_002',
            'timestamp' => 1700000000000,
            'deliveryContext' => ['isRedelivery' => false],
        ];
    }

    private function makeImageEvent(): array
    {
        return [
            'type' => 'message',
            'replyToken' => 'rt_image',
            'source' => ['userId' => 'U_test'],
            'message' => ['type' => 'image', 'id' => 'img_001'],
            'webhookEventId' => 'evt_003',
            'timestamp' => 1700000000000,
            'deliveryContext' => ['isRedelivery' => false],
        ];
    }

    private function makeVideoEvent(): array
    {
        return [
            'type' => 'message',
            'replyToken' => 'rt_video',
            'source' => ['userId' => 'U_test'],
            'message' => ['type' => 'video', 'id' => 'vid_001'],
            'webhookEventId' => 'evt_004',
            'timestamp' => 1700000000000,
            'deliveryContext' => ['isRedelivery' => false],
        ];
    }

    // -------------------------------------------------------------------------
    // Model helpers
    // -------------------------------------------------------------------------

    private function makeBot(array $attrs = []): Bot
    {
        return Bot::factory()->create(array_merge([
            'status' => 'active',
        ], $attrs));
    }

    private function makeConversationMock(Bot $bot): Conversation
    {
        $conv = Mockery::mock(Conversation::class)->makePartial();
        $conv->id = 9999;
        $conv->bot_id = $bot->id;
        $conv->external_customer_id = 'U_test';
        $conv->channel_type = 'line';
        $conv->status = 'active';
        $conv->is_handover = false;
        $conv->message_count = 5;
        $conv->unread_count = 1;
        $conv->last_message_at = now();
        $conv->shouldReceive('update')->andReturn(true);
        $conv->shouldReceive('refresh')->andReturnSelf();

        return $conv;
    }

    private function makeMessage(int $convId = 9999, string $sender = 'bot', string $content = 'Hello!'): Message
    {
        return Message::factory()->make([
            'id' => rand(1, 9999),
            'conversation_id' => $convId,
            'sender' => $sender,
            'content' => $content,
            'type' => 'text',
        ]);
    }

    // -------------------------------------------------------------------------
    // Service builder
    // -------------------------------------------------------------------------

    private function makeService(
        ?LINEService $line = null,
        ?LeadRecoveryService $leadRecovery = null,
        ?MultipleBubblesService $bubbles = null,
        ?PaymentFlexService $paymentFlex = null,
        ?FlowPluginService $flowPlugin = null,
    ): LineWebhookOutputService {
        return new LineWebhookOutputService(
            line: $line ?? Mockery::mock(LINEService::class),
            leadRecovery: $leadRecovery ?? Mockery::mock(LeadRecoveryService::class),
            bubbles: $bubbles ?? Mockery::mock(MultipleBubblesService::class),
            paymentFlex: $paymentFlex ?? Mockery::mock(PaymentFlexService::class),
            flowPlugin: $flowPlugin ?? Mockery::mock(FlowPluginService::class),
        );
    }

    // =========================================================================
    // Test 1: Text + bubbles disabled + no Flex match → plain replyWithFallback
    // =========================================================================

    public function test_text_plain_push_when_no_flex_no_bubbles(): void
    {
        Event::fake();

        $bot = $this->makeBot();
        $conv = $this->makeConversationMock($bot);
        $botMessage = $this->makeMessage(9999, 'bot', 'Hello!');
        $userMessage = $this->makeMessage(9999, 'user', 'hi');

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->once()->andReturn('rk_1');
        $line->shouldReceive('replyWithFallback')
            ->once()
            ->with($bot, 'rt_test', 'U_test', ['Hello!'], 'rk_1');

        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->with($bot)->andReturn(false);

        $paymentFlex = Mockery::mock(PaymentFlexService::class);
        $paymentFlex->shouldReceive('tryConvertToFlex')
            ->with('Hello!', $conv)
            ->andReturn('Hello!'); // string → no Flex

        $flowPlugin = Mockery::mock(FlowPluginService::class);
        $flowPlugin->shouldReceive('executePlugins')->once();

        $leadRecovery = Mockery::mock(LeadRecoveryService::class);
        $leadRecovery->shouldReceive('markCustomerResponded')->once()->with($conv);

        $ctx = new WebhookContext($bot, $this->makeTextEvent());
        $ctx->conversation = $conv;
        $ctx->userMessage = $userMessage;
        $ctx->response = ResponseEnvelope::text('Hello!');
        $ctx->metadata['bot_message'] = $botMessage;
        $ctx->metadata['is_new_conversation'] = false;

        $svc = $this->makeService(
            line: $line,
            bubbles: $bubbles,
            paymentFlex: $paymentFlex,
            flowPlugin: $flowPlugin,
            leadRecovery: $leadRecovery,
        );
        $svc->dispatch($ctx);

        Event::assertDispatched(MessageSent::class);
        Event::assertDispatched(ConversationUpdated::class);
    }

    // =========================================================================
    // Test 2: Text + bubbles enabled → sendBubbles called
    // =========================================================================

    public function test_text_sends_bubbles_when_enabled(): void
    {
        Event::fake();

        $bot = $this->makeBot();
        $conv = $this->makeConversationMock($bot);
        $botMessage = $this->makeMessage(9999, 'bot', 'Multi-bubble reply');
        $userMessage = $this->makeMessage(9999, 'user', 'hi');

        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->with($bot)->andReturn(true);
        $bubbles->shouldReceive('parseIntoBubbles')
            ->with('Multi-bubble reply', $bot)
            ->andReturn([['type' => 'bubble']]);
        $bubbles->shouldReceive('sendBubbles')
            ->once()
            ->with($bot, 'U_test', 'rt_test', [['type' => 'bubble']], $conv);

        $paymentFlex = Mockery::mock(PaymentFlexService::class);
        $paymentFlex->shouldReceive('tryConvertToFlex')
            ->with('Multi-bubble reply', $conv)
            ->andReturn('Multi-bubble reply'); // no Flex match

        $flowPlugin = Mockery::mock(FlowPluginService::class);
        $flowPlugin->shouldReceive('executePlugins')->once();

        $leadRecovery = Mockery::mock(LeadRecoveryService::class);
        $leadRecovery->shouldReceive('markCustomerResponded')->once();

        $ctx = new WebhookContext($bot, $this->makeTextEvent());
        $ctx->conversation = $conv;
        $ctx->userMessage = $userMessage;
        $ctx->response = ResponseEnvelope::text('Multi-bubble reply');
        $ctx->metadata['bot_message'] = $botMessage;
        $ctx->metadata['is_new_conversation'] = false;

        $svc = $this->makeService(bubbles: $bubbles, paymentFlex: $paymentFlex, flowPlugin: $flowPlugin, leadRecovery: $leadRecovery);
        $svc->dispatch($ctx);

        Event::assertDispatched(MessageSent::class);
    }

    // =========================================================================
    // Test 3: Text + PaymentFlex match → replyWithFallback with Flex array
    // =========================================================================

    public function test_text_sends_flex_when_payment_flex_matches(): void
    {
        Event::fake();

        $bot = $this->makeBot();
        $conv = $this->makeConversationMock($bot);
        $botMessage = $this->makeMessage(9999, 'bot', 'Pay now');
        $userMessage = $this->makeMessage(9999, 'user', 'order');

        $flexArray = ['type' => 'flex', 'altText' => 'Payment'];

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->once()->andReturn('rk_flex');
        $line->shouldReceive('replyWithFallback')
            ->once()
            ->with($bot, 'rt_test', 'U_test', [$flexArray], 'rk_flex');

        $paymentFlex = Mockery::mock(PaymentFlexService::class);
        $paymentFlex->shouldReceive('tryConvertToFlex')
            ->with('Pay now', $conv)
            ->andReturn($flexArray); // array → Flex matched

        $bubbles = Mockery::mock(MultipleBubblesService::class);
        // isEnabled should NOT be called when Flex matches
        $bubbles->shouldNotReceive('isEnabled');

        $flowPlugin = Mockery::mock(FlowPluginService::class);
        $flowPlugin->shouldReceive('executePlugins')->once();

        $leadRecovery = Mockery::mock(LeadRecoveryService::class);
        $leadRecovery->shouldReceive('markCustomerResponded')->once();

        $ctx = new WebhookContext($bot, $this->makeTextEvent());
        $ctx->conversation = $conv;
        $ctx->userMessage = $userMessage;
        $ctx->response = ResponseEnvelope::text('Pay now');
        $ctx->metadata['bot_message'] = $botMessage;
        $ctx->metadata['is_new_conversation'] = false;

        Cache::lock("ai_response:{$conv->id}", 30)->forceRelease();

        $svc = $this->makeService(line: $line, bubbles: $bubbles, paymentFlex: $paymentFlex, flowPlugin: $flowPlugin, leadRecovery: $leadRecovery);
        $svc->dispatch($ctx);

        Event::assertDispatched(MessageSent::class);
    }

    // =========================================================================
    // Test 4 (moved to ProcessLINEWebhookPipelineTest): lock-held fallback is
    // now runPipeline()'s responsibility, not OutputService's.
    // =========================================================================

    // =========================================================================
    // Test 5: Text + push throws → exception re-thrown
    // =========================================================================

    public function test_text_exception_during_push_is_rethrown(): void
    {
        Event::fake();

        $bot = $this->makeBot();
        $conv = $this->makeConversationMock($bot);
        $botMessage = $this->makeMessage(9999, 'bot', 'Hello!');
        $userMessage = $this->makeMessage(9999, 'user', 'hi');

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->andReturn('rk_x');
        $line->shouldReceive('replyWithFallback')->andThrow(new \RuntimeException('LINE API error'));

        $paymentFlex = Mockery::mock(PaymentFlexService::class);
        $paymentFlex->shouldReceive('tryConvertToFlex')->andReturn('Hello!');

        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->andReturn(false);

        $ctx = new WebhookContext($bot, $this->makeTextEvent());
        $ctx->conversation = $conv;
        $ctx->userMessage = $userMessage;
        $ctx->response = ResponseEnvelope::text('Hello!');
        $ctx->metadata['bot_message'] = $botMessage;
        $ctx->metadata['is_new_conversation'] = false;

        $svc = $this->makeService(line: $line, bubbles: $bubbles, paymentFlex: $paymentFlex);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LINE API error');

        $svc->dispatch($ctx);
    }

    // =========================================================================
    // Test 6: Sticker → replyWithFallback called once with sticker content
    // =========================================================================

    public function test_sticker_calls_reply_with_fallback(): void
    {
        Event::fake();

        $bot = $this->makeBot();
        $conv = $this->makeConversationMock($bot);
        $botMessage = $this->makeMessage(9999, 'bot', '[sticker response]');
        $userMessage = $this->makeMessage(9999, 'user', '[สติกเกอร์]');

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->once()->andReturn('rk_sticker');
        $line->shouldReceive('replyWithFallback')
            ->once()
            ->with($bot, 'rt_sticker', 'U_test', ['[sticker response]'], 'rk_sticker');

        $leadRecovery = Mockery::mock(LeadRecoveryService::class);
        $leadRecovery->shouldReceive('markCustomerResponded')->once()->with($conv);

        $ctx = new WebhookContext($bot, $this->makeStickerEvent());
        $ctx->conversation = $conv;
        $ctx->userMessage = $userMessage;
        $ctx->response = ResponseEnvelope::text('[sticker response]');
        $ctx->metadata['bot_message'] = $botMessage;
        $ctx->metadata['is_new_conversation'] = false;

        $svc = $this->makeService(line: $line, leadRecovery: $leadRecovery);
        $svc->dispatch($ctx);

        Event::assertDispatched(MessageSent::class);
    }

    // =========================================================================
    // Test 7: Image + bubbles enabled → sendBubbles called
    // =========================================================================

    public function test_image_sends_bubbles_when_enabled(): void
    {
        Event::fake();

        $bot = $this->makeBot();
        $conv = $this->makeConversationMock($bot);
        $botMessage = $this->makeMessage(9999, 'bot', 'Image reply');
        $userMessage = $this->makeMessage(9999, 'user', '[รูปภาพ]');

        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->with($bot)->andReturn(true);
        $bubbles->shouldReceive('parseIntoBubbles')
            ->with('Image reply', $bot)
            ->andReturn([['type' => 'bubble']]);
        $bubbles->shouldReceive('sendBubbles')
            ->once()
            ->with($bot, 'U_test', 'rt_image', [['type' => 'bubble']], $conv);

        $flowPlugin = Mockery::mock(FlowPluginService::class);
        $flowPlugin->shouldReceive('executePlugins')->once();

        $leadRecovery = Mockery::mock(LeadRecoveryService::class);
        $leadRecovery->shouldReceive('markCustomerResponded')->once();

        $ctx = new WebhookContext($bot, $this->makeImageEvent());
        $ctx->conversation = $conv;
        $ctx->userMessage = $userMessage;
        $ctx->response = ResponseEnvelope::text('Image reply');
        $ctx->metadata['bot_message'] = $botMessage;
        $ctx->metadata['is_new_conversation'] = false;

        $svc = $this->makeService(bubbles: $bubbles, flowPlugin: $flowPlugin, leadRecovery: $leadRecovery);
        $svc->dispatch($ctx);

        Event::assertDispatched(MessageSent::class);
        Event::assertDispatched(ConversationUpdated::class);
    }

    // =========================================================================
    // Test 8: Image + bubbles disabled → PaymentFlex + replyWithFallback
    // =========================================================================

    public function test_image_uses_payment_flex_when_bubbles_disabled(): void
    {
        Event::fake();

        $bot = $this->makeBot();
        $conv = $this->makeConversationMock($bot);
        $botMessage = $this->makeMessage(9999, 'bot', 'Image plain');
        $userMessage = $this->makeMessage(9999, 'user', '[รูปภาพ]');

        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->with($bot)->andReturn(false);

        $paymentFlex = Mockery::mock(PaymentFlexService::class);
        $paymentFlex->shouldReceive('tryConvertToFlex')
            ->with('Image plain', $conv)
            ->andReturn('Image plain'); // no Flex

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->once()->andReturn('rk_img');
        $line->shouldReceive('replyWithFallback')
            ->once()
            ->with($bot, 'rt_image', 'U_test', ['Image plain'], 'rk_img');

        $flowPlugin = Mockery::mock(FlowPluginService::class);
        $flowPlugin->shouldReceive('executePlugins')->once();

        $leadRecovery = Mockery::mock(LeadRecoveryService::class);
        $leadRecovery->shouldReceive('markCustomerResponded')->once();

        $ctx = new WebhookContext($bot, $this->makeImageEvent());
        $ctx->conversation = $conv;
        $ctx->userMessage = $userMessage;
        $ctx->response = ResponseEnvelope::text('Image plain');
        $ctx->metadata['bot_message'] = $botMessage;
        $ctx->metadata['is_new_conversation'] = false;

        $svc = $this->makeService(line: $line, bubbles: $bubbles, paymentFlex: $paymentFlex, flowPlugin: $flowPlugin, leadRecovery: $leadRecovery);
        $svc->dispatch($ctx);

        Event::assertDispatched(MessageSent::class);
    }

    // =========================================================================
    // Test 9: Response null + non-text → no push, broadcast user MessageSent,
    //         LeadRecovery::markCustomerResponded called
    // =========================================================================

    public function test_null_response_broadcasts_user_message_and_marks_lead_recovery(): void
    {
        Event::fake();

        $bot = $this->makeBot();
        $conv = $this->makeConversationMock($bot);
        $userMessage = $this->makeMessage(9999, 'user', '[วิดีโอ]');

        $line = Mockery::mock(LINEService::class);
        $line->shouldNotReceive('replyWithFallback');

        $leadRecovery = Mockery::mock(LeadRecoveryService::class);
        $leadRecovery->shouldReceive('markCustomerResponded')->once()->with($conv);

        $ctx = new WebhookContext($bot, $this->makeVideoEvent());
        $ctx->conversation = $conv;
        $ctx->userMessage = $userMessage;
        $ctx->response = null; // no bot response

        $svc = $this->makeService(line: $line, leadRecovery: $leadRecovery);
        $svc->dispatch($ctx);

        Event::assertDispatched(MessageSent::class, fn ($e) => $e->message->sender === 'user');
        Event::assertDispatched(ConversationUpdated::class, fn ($e) => $e->updateType === 'message_received');
    }

    // =========================================================================
    // Test 10: Response null + no conversation → nothing called, no crash
    // =========================================================================

    public function test_null_response_with_no_conversation_does_nothing(): void
    {
        Event::fake();

        $bot = $this->makeBot();

        $leadRecovery = Mockery::mock(LeadRecoveryService::class);
        $leadRecovery->shouldNotReceive('markCustomerResponded');

        $ctx = new WebhookContext($bot, $this->makeVideoEvent());
        $ctx->conversation = null;
        $ctx->userMessage = null;
        $ctx->response = null;

        $svc = $this->makeService(leadRecovery: $leadRecovery);
        $svc->dispatch($ctx); // must not throw

        Event::assertNotDispatched(MessageSent::class);
    }
}
