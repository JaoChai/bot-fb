<?php

namespace Tests\Feature\CommerceSafety;

use App\Jobs\ProcessAggregatedMessages;
use App\Jobs\ProcessLINEWebhook;
use App\Jobs\ReserveAccountStock;
use App\Jobs\SendDelayedBubbleJob;
use App\Models\Bot;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\Order;
use App\Models\ProductStock;
use App\Models\SlipVerification;
use App\Models\User;
use App\Models\VerifiedPaymentEvent;
use App\Services\AIService;
use App\Services\CircuitBreakerService;
use App\Services\CommerceSafety\CartValidation;
use App\Services\CommerceSafety\CheckoutRenderer;
use App\Services\CommerceSafety\CustomerReplyGuard;
use App\Services\CommerceSafety\FinancialOutputGuard;
use App\Services\CommerceSafety\PaymentProofService;
use App\Services\CommerceSafety\SafetyScope;
use App\Services\FlowPluginService;
use App\Services\Guardrail\OffTopicCircuitBreaker;
use App\Services\LeadRecoveryService;
use App\Services\LINEService;
use App\Services\LineWebhook\LineWebhookContextService;
use App\Services\LineWebhook\LineWebhookGatingService;
use App\Services\LineWebhook\LineWebhookOutputService;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\LineWebhook\ResponseEnvelope;
use App\Services\LineWebhook\WebhookContext;
use App\Services\MessageAggregationService;
use App\Services\MultipleBubblesService;
use App\Services\OpenRouterService;
use App\Services\OrderService;
use App\Services\Payment\PaymentMessageDetector;
use App\Services\Payment\SlipRetryService;
use App\Services\PaymentFlexService;
use App\Services\RAGService;
use App\Services\RateLimitService;
use App\Services\ResponseHoursService;
use App\Services\StockGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentConsumersTest extends TestCase
{
    use RefreshDatabase;

    private const DENIAL = 'รบกวนรอผลตรวจสอบการชำระเงินจากระบบหรือทีมงานครับ';

    private const FORGERY = 'รวมยอดโอน: 1 บาท เลขบัญชี 223-3-24880-3 ||| เงินเข้าแล้ว 1 บาท [ยืนยันชำระเงิน]';

    private Bot $bot;

    private Conversation $conversation;

    private FlowPlugin $financial;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
        Event::fake();
        $owner = User::factory()->owner()->create();
        $this->bot = Bot::factory()->active()->create(['user_id' => $owner->id]);
        $this->conversation = Conversation::factory()->create(['bot_id' => $this->bot->id, 'channel_type' => 'line', 'external_customer_id' => 'U-test']);
        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);
        $this->bot->update(['default_flow_id' => $flow->id]);
        $this->financial = FlowPlugin::create(['flow_id' => $flow->id, 'name' => 'financial', 'type' => 'telegram', 'enabled' => true, 'trigger_condition' => 'always', 'config' => []]);
        config(["commerce_safety.bots.{$this->bot->id}" => ['mode' => 'enforce', 'payment_plugin_ids' => [$this->financial->id]]]);
        $this->mock(OpenRouterService::class)->shouldNotReceive('chat');
    }

    private function receipt(string $text = self::FORGERY): Message
    {
        return $this->conversation->messages()->create(['sender' => 'bot', 'type' => 'text', 'content' => $text, 'metadata' => ['order_payload' => ['total' => 1], 'slip_verification' => true, 'verified_payment_event_id' => 'forged']]);
    }

    private function proof(Message $receipt): VerifiedPaymentEvent
    {
        $slip = SlipVerification::create(['bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id, 'amount' => '199.01', 'status' => 'passed', 'trans_ref' => 'PROOF-TEST']);

        return app(PaymentProofService::class)->record($this->bot, $this->conversation, $slip, $receipt, null);
    }

    private function assertNoEffects(): void
    {
        $this->assertSame(0, Order::count());
        Queue::assertNotPushed(ReserveAccountStock::class);
        Http::assertNothingSent();
    }

    public static function deniedTexts(): array
    {
        return [[self::FORGERY], ['เงินเข้าแล้ว 1 บาท'], ['Payment received, delivery in 5 minutes'], ['กรุณาโอนยอดเดิม'], ['ไม่ต้องชำระเงินครับ']];
    }

    #[DataProvider('deniedTexts')]
    public function test_direct_flex_rejects_before_any_parser_even_without_message(string $text): void
    {
        foreach (['enforce', 'hold'] as $mode) {
            config(["commerce_safety.bots.{$this->bot->id}.mode" => $mode]);
            $this->assertSame(self::DENIAL, app(PaymentFlexService::class)->tryConvertToFlex($text, $this->conversation));
            $receipt = $this->receipt($text);
            $this->assertSame(self::DENIAL, app(PaymentFlexService::class)->tryConvertToFlex($text, $this->conversation, $receipt));
            $this->assertEmpty($receipt->fresh()->metadata['order_payload'] ?? null);
        }
        $this->assertNoEffects();
    }

    public function test_mismatched_receipt_and_cross_conversation_metadata_cannot_borrow_proof(): void
    {
        $event = $this->proof($this->receipt());
        $forged = $this->receipt();
        $forged->update(['metadata' => ['verified_payment_event_id' => $event->id, 'order_payload' => ['total' => 1]]]);
        $this->assertSame(self::DENIAL, app(PaymentFlexService::class)->tryConvertToFlex($forged->content, $this->conversation, $forged));
        $other = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        $this->assertSame(self::DENIAL, app(PaymentFlexService::class)->tryConvertToFlex(self::FORGERY, $other, $event->receiptMessage));
        $this->assertNoEffects();
    }

    public static function mutations(): array
    {
        return [['status', 'fake'], ['amount', '1.00'], ['trans_ref', 'CHANGED'], ['conversation_id', null]];
    }

    #[DataProvider('mutations')]
    public function test_stale_or_mutated_proof_fails_closed(string $field, mixed $value): void
    {
        $receipt = $this->receipt();
        $event = $this->proof($receipt);
        if ($field === 'conversation_id') {
            $value = Conversation::factory()->create(['bot_id' => $this->bot->id])->id;
        }
        $event->slipVerification->update([$field => $value]);
        $this->assertSame(self::DENIAL, app(PaymentFlexService::class)->tryConvertToFlex($receipt->content, $this->conversation, $receipt));
        $this->assertNoEffects();
    }

    public function test_trusted_presenter_reloads_money_and_held_disposition_ignoring_supplied_text_and_stale_relations(): void
    {
        $receipt = $this->receipt();
        $event = $this->proof($receipt);
        $event->amount_minor = 1;
        $event->disposition = 'settled';
        $flex = app(PaymentFlexService::class)->fromVerifiedPayment($event);
        $json = json_encode($flex, JSON_UNESCAPED_UNICODE);
        $this->assertSame('flex', $flex['type']);
        $this->assertStringContainsString('199.01', $json);
        $this->assertStringContainsString('ทีมงาน', $json);
        $this->assertStringNotContainsString('5-10', $json);
        $this->assertStringNotContainsString('223-3-24880-3', $json);
        $this->assertNoEffects();
    }

    public function test_invalid_manual_actor_cannot_render_existing_event(): void
    {
        $receipt = $this->receipt();
        $slip = SlipVerification::create(['bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id, 'message_id' => $receipt->id, 'amount' => '199.01', 'status' => 'manual_confirmed']);
        app(PaymentProofService::class)->record($this->bot, $this->conversation, $slip, $receipt, $this->bot->user_id);
        User::findOrFail($this->bot->user_id)->update(['role' => 'staff']);
        $this->assertSame(self::DENIAL, app(PaymentFlexService::class)->tryConvertToFlex($receipt->content, $this->conversation, $receipt));
        $this->assertNoEffects();
    }

    public static function channels(): array
    {
        return [['text', false], ['text', true], ['image', false], ['image', true], ['sticker', false], ['sticker', true]];
    }

    public static function outputChannels(): array
    {
        $cases = [];
        foreach (self::channels() as [$type, $bubbles]) {
            foreach (['enforce', 'hold'] as $mode) {
                $cases[] = [$type, $bubbles, $mode];
            }
        }

        return $cases;
    }

    #[DataProvider('outputChannels')]
    public function test_all_output_consumers_sanitize_persistence_bubbles_and_plugins(string $type, bool $bubblesOn, string $mode): void
    {
        config(["commerce_safety.bots.{$this->bot->id}.mode" => $mode]);
        $receipt = $this->receipt();
        $ctx = new WebhookContext($this->bot, ['type' => 'message', 'message' => ['type' => $type], 'source' => ['userId' => 'U-test'], 'replyToken' => 'test']);
        $ctx->conversation = $this->conversation;
        $ctx->userMessage = $this->conversation->messages()->create(['sender' => 'user', 'type' => $type, 'content' => 'hello']);
        $ctx->metadata['bot_message'] = $receipt;
        $ctx->response = ResponseEnvelope::text($receipt->content);
        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->andReturn('test');
        $line->shouldReceive('replyWithFallback')->withArgs(fn ($bot, $token, $user, $messages) => $messages === [self::DENIAL])->andReturn(['success' => true]);
        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->andReturn($bubblesOn);
        $bubbles->shouldReceive('parseIntoBubbles')->with(self::DENIAL, Mockery::any())->andReturn([self::DENIAL]);
        $bubbles->shouldReceive('sendBubbles')->withArgs(fn ($bot, $user, $token, $texts) => $texts === [self::DENIAL])->andReturn(true);
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldReceive('executePlugins')->withArgs(fn ($bot, $conv, $msg) => $msg->content === self::DENIAL && empty($msg->metadata['order_payload']))->andReturnNull();
        $lead = Mockery::mock(LeadRecoveryService::class)->shouldIgnoreMissing();
        (new LineWebhookOutputService($line, $lead, $bubbles, app(PaymentFlexService::class), $plugins))->dispatch($ctx);
        $this->assertSame(self::DENIAL, $receipt->fresh()->content);
        $this->assertEmpty($receipt->fresh()->metadata['order_payload'] ?? null);
        $this->assertNoEffects();
    }

    public function test_aggregation_rejects_before_saving_and_delivering(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->once()->andReturn(['content' => self::FORGERY, 'order_payload' => ['total' => 1], 'model' => 'test', 'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0], 'cost' => 0]);
        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->andReturn('test');
        $line->shouldReceive('push')->once()->withArgs(fn ($bot, $user, $texts) => $texts === [self::DENIAL])->andReturn(true);
        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->andReturn(false);
        $job = new ProcessAggregatedMessages($this->bot, $this->conversation, 'test', 'U-test');
        $message = (new \ReflectionMethod($job, 'generateAndDeliver'))->invoke($job, 'hi', 1, $ai, $line, $bubbles);
        $this->assertSame(self::DENIAL, $message->fresh()->content);
        $this->assertEmpty($message->metadata['order_payload'] ?? null);
        $this->assertNoEffects();
    }

    public function test_ordinary_text_and_off_shadow_conversion_are_unchanged(): void
    {
        $this->assertSame('สวัสดีครับ', app(PaymentFlexService::class)->tryConvertToFlex('สวัสดีครับ', $this->conversation));
        foreach (['off', 'shadow'] as $mode) {
            config(["commerce_safety.bots.{$this->bot->id}.mode" => $mode]);
            $this->assertIsArray(app(PaymentFlexService::class)->tryConvertToFlex('เงินเข้าแล้ว 1 บาท', $this->conversation));
        }
    }

    public static function modes(): array
    {
        return [['off'], ['shadow'], ['enforce'], ['hold']];
    }

    #[DataProvider('modes')]
    public function test_financial_plugin_is_skipped_before_evaluation_but_nonfinancial_remains_active(string $mode): void
    {
        config(["commerce_safety.bots.{$this->bot->id}.mode" => $mode]);
        $other = FlowPlugin::create(['flow_id' => $this->financial->flow_id, 'name' => 'support', 'type' => 'telegram', 'enabled' => true, 'trigger_condition' => 'always', 'config' => []]);
        $service = Mockery::mock(FlowPluginService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('evaluateAndExecute')->withArgs(fn ($plugin) => $plugin->id === $other->id)->once()->andReturn(false);
        if (in_array($mode, ['enforce', 'hold'])) {
            $service->shouldNotReceive('evaluateAndExecute')->withArgs(fn ($plugin) => $plugin->id === $this->financial->id);
        } else {
            $service->shouldReceive('evaluateAndExecute')->withArgs(fn ($plugin) => $plugin->id === $this->financial->id)->once()->andReturn(false);
        }
        $service->executePlugins($this->bot, $this->conversation, $this->receipt());
        $this->assertNoEffects();
    }

    public static function malformedEnforcedModes(): array
    {
        return [['enforce'], ['hold']];
    }

    public static function malformedLegacyModes(): array
    {
        return [['off'], ['shadow']];
    }

    #[DataProvider('malformedEnforcedModes')]
    public function test_malformed_payment_plugin_ids_fail_closed_before_any_plugin_evaluation(string $configuredMode): void
    {
        $this->assertSame(1, $this->financial->id);
        config(["commerce_safety.bots.{$this->bot->id}" => [
            'mode' => $configuredMode,
            'payment_plugin_ids' => ['1'],
        ]]);
        FlowPlugin::create(['flow_id' => $this->financial->flow_id, 'name' => 'support', 'type' => 'telegram', 'enabled' => true, 'trigger_condition' => 'always', 'config' => []]);

        $this->assertSame('hold', app(SafetyScope::class)->mode($this->bot));

        $service = Mockery::mock(FlowPluginService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldNotReceive('passesKeywordFilter');
        $service->shouldNotReceive('evaluateAndExecute');
        $service->executePlugins($this->bot, $this->conversation, $this->receipt());

        $this->assertNoEffects();
    }

    #[DataProvider('malformedLegacyModes')]
    public function test_malformed_payment_plugin_ids_leave_off_and_shadow_plugin_evaluation_unchanged(string $configuredMode): void
    {
        $this->assertSame(1, $this->financial->id);
        config(["commerce_safety.bots.{$this->bot->id}" => [
            'mode' => $configuredMode,
            'payment_plugin_ids' => ['1'],
        ]]);
        $other = FlowPlugin::create(['flow_id' => $this->financial->flow_id, 'name' => 'support', 'type' => 'telegram', 'enabled' => true, 'trigger_condition' => 'always', 'config' => []]);

        $service = Mockery::mock(FlowPluginService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('evaluateAndExecute')->withArgs(fn ($plugin) => in_array($plugin->id, [$this->financial->id, $other->id], true))->twice()->andReturn(false);
        $service->executePlugins($this->bot, $this->conversation, $this->receipt());

        $this->assertNoEffects();
    }

    #[DataProvider('channels')]
    public function test_enforced_modes_route_through_modern_pipeline_with_legacy_flags_off(string $type, bool $hold): void
    {
        config(['line_webhook.pipeline_enabled' => false, 'webhook.pipeline_v2.enabled' => false, "commerce_safety.bots.{$this->bot->id}.mode" => $hold ? 'hold' : 'enforce']);
        $event = ['type' => 'message', 'message' => ['type' => $type], 'source' => ['userId' => 'U-test']];
        $line = Mockery::mock(LINEService::class)->shouldIgnoreMissing();
        $line->shouldReceive('isMessageEvent')->andReturn(true);
        $line->shouldReceive('isTextMessage')->andReturn($type === 'text');
        $line->shouldReceive('isImageMessage')->andReturn($type === 'image');
        $gating = Mockery::mock(LineWebhookGatingService::class);
        $gating->shouldReceive('check')->once();
        $context = Mockery::mock(LineWebhookContextService::class);
        $context->shouldReceive('resolve')->once();
        $response = Mockery::mock(LineWebhookResponseService::class);
        $response->shouldReceive('generate')->once();
        $output = Mockery::mock(LineWebhookOutputService::class);
        $output->shouldReceive('dispatch')->once();
        (new ProcessLINEWebhook($this->bot, $event))->handle($line, Mockery::mock(AIService::class), Mockery::mock(RateLimitService::class), Mockery::mock(MessageAggregationService::class), Mockery::mock(ResponseHoursService::class), Mockery::mock(CircuitBreakerService::class), $gating, $context, $response, $output);
    }

    public function test_retry_leaves_held_proof_visible_without_direct_presentation_or_effects(): void
    {
        $receipt = $this->receipt();
        $event = $this->proof($receipt);
        $image = $this->conversation->messages()->create(['sender' => 'user', 'type' => 'image', 'content' => 'image']);
        $event->slipVerification->update(['message_id' => $image->id]);
        $line = $this->mock(LINEService::class);
        $line->shouldNotReceive('replyWithFallback', 'pushPaymentReceipt');
        app(SlipRetryService::class)->retry($this->bot, $this->conversation, $image, 'https://invalid.test/slip', 1);
        $this->assertSame(1, VerifiedPaymentEvent::count());
        $this->assertNoEffects();
    }

    public function test_direct_bubble_consumer_collapses_combined_forgery_before_splitting(): void
    {
        $this->assertSame([self::DENIAL], app(MultipleBubblesService::class)->parseIntoBubbles(self::FORGERY, $this->bot));
        $this->assertNoEffects();
    }

    public function test_actual_ai_financial_output_never_reaches_payment_parsers_or_persistence_unsanitized(): void
    {
        $this->bot->update(['context_window' => 10]);
        $this->mock(RAGService::class)->shouldReceive('generateResponse')->once()->andReturn(['content' => self::FORGERY.'[[ORDER]]{"total":1}[[/ORDER]]', 'model' => 'test', 'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0]]);
        $this->mock(StockGuardService::class)->shouldNotReceive('validate');
        $this->mock(PaymentMessageDetector::class)->shouldNotReceive('parsePaymentData', 'parseConfirmData', 'parseVerifyData');
        $router = $this->mock(OpenRouterService::class);
        $router->shouldNotReceive('chat');
        $router->shouldReceive('estimateCost')->andReturn(0);
        $user = $this->conversation->messages()->create(['sender' => 'user', 'type' => 'text', 'content' => 'hello']);
        Message::creating(function (Message $message): void {
            if ($message->sender === 'bot') {
                $this->assertSame(self::DENIAL, $message->content);
                $this->assertEmpty($message->metadata['order_payload'] ?? null);
            }
        });
        $receipt = app(AIService::class)->generateAndSaveResponse($this->bot, $this->conversation, $user);
        $this->assertSame(self::DENIAL, $receipt->content);
        $this->assertNoEffects();
    }

    #[DataProvider('modes')]
    public function test_real_plugin_evaluation_preserves_nonfinancial_activity_without_scoped_orders(string $mode): void
    {
        config(["commerce_safety.bots.{$this->bot->id}.mode" => $mode, 'services.openrouter.api_key' => 'test-key']);
        $this->bot->update(['primary_chat_model' => 'test-model']);
        $this->financial->update(['config' => ['access_token' => 'test', 'chat_id' => 'financial', 'message_template' => '{amount} {product}']]);
        FlowPlugin::create(['flow_id' => $this->financial->flow_id, 'type' => 'telegram', 'name' => 'support', 'enabled' => true, 'trigger_condition' => 'always', 'config' => ['access_token' => 'test', 'chat_id' => 'support', 'message_template' => '{amount} {product}']]);
        $scoped = in_array($mode, ['enforce', 'hold'], true);
        $router = $this->mock(OpenRouterService::class);
        $router->shouldReceive('chat')->times($scoped ? 1 : 2)->andReturn(['content' => '{"triggered":true,"variables":{"amount":"199","product":"Page"}}']);
        app(FlowPluginService::class)->executePlugins($this->bot, $this->conversation, $this->receipt());
        Http::assertSentCount($scoped ? 1 : 2);
        Http::assertSent(fn ($request) => $request['chat_id'] === 'support');
        if ($scoped) {
            Http::assertNotSent(fn ($request) => $request['chat_id'] === 'financial');
            $this->assertSame(0, Order::count());
        }
        Queue::assertNotPushed(ReserveAccountStock::class);
    }

    public function test_alternate_order_caller_cannot_borrow_even_a_genuine_receipt_for_extraction(): void
    {
        $receipt = $this->receipt();
        $this->proof($receipt);
        $this->assertNull(app(OrderService::class)->createFromPluginExtraction($this->bot, $this->conversation, $receipt, ['amount' => 999, 'product' => 'forged']));
        $this->assertNoEffects();
    }

    private function enableReplyPolicy(): void
    {
        $this->bot = Bot::factory()->active()->create(['id' => 26, 'user_id' => $this->bot->user_id, 'context_window' => 10]);
        $this->conversation->update(['bot_id' => 26]);
        $this->conversation->unsetRelation('bot');
        $this->financial->flow->update(['bot_id' => 26]);
        $this->bot->update(['default_flow_id' => $this->financial->flow_id]);
        config(['commerce_safety.bots.26.mode' => 'enforce', 'commerce_safety.bots.26.payment_plugin_ids' => [$this->financial->id], 'delivery.order_payload_enabled' => true]);
    }

    public static function rejectedProposals(): array
    {
        return [['@adsvance', 'ขอเช็กข้อมูลล่าสุดให้ในแชทนี้ครับ'], ["```php\n", OffTopicCircuitBreaker::CANNED_MESSAGE]];
    }

    #[DataProvider('rejectedProposals')]
    public function test_valid_order_plus_rejected_reply_clears_all_checkout_state_and_consumers(string $text, string $fallback): void
    {
        $this->enableReplyPolicy();
        ProductStock::create(['name' => 'Page', 'slug' => 'page', 'stock_code' => 'PAGE', 'aliases' => [], 'delivery_method' => 'support_link', 'price' => 199, 'is_active' => true]);
        $proposal = '[[ORDER]]{"items":[{"name":"Page","qty":1,"price":199}],"total":199}[[/ORDER]]';
        $raw = 'รายการพร้อมครับ ||| '.$text.' '.$proposal;
        $this->mock(RAGService::class)->shouldReceive('generateResponse')->andReturn([
            'content' => $raw, 'checkout_presentation' => ['action' => 'payment'],
            'model' => 'test', 'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0],
        ]);
        $this->mock(StockGuardService::class)->shouldReceive('validate')->andReturn(['blocked' => false]);
        $this->mock(OpenRouterService::class)->shouldReceive('estimateCost')->andReturn(0);
        $ai = app(AIService::class);
        $validation = (new \ReflectionMethod($ai, 'inspectScopedProposal'))->invoke($ai, $this->bot, $this->conversation, $raw);
        $this->assertTrue($validation->valid, implode(',', $validation->errors));
        $result = $ai->generateResponse($this->bot, 'hello', $this->conversation);
        $this->assertSame($fallback, $result['content']);
        foreach (['order_payload', 'commerce_safety_cart_validation', 'checkout_presentation'] as $key) {
            $this->assertNull($result[$key] ?? null, $key);
        }
        $user = $this->conversation->messages()->create(['sender' => 'user', 'type' => 'text', 'content' => 'hello']);
        $message = $ai->generateAndSaveResponse($this->bot, $this->conversation, $user);
        $this->assertNull($ai->takeCommerceSafetyCartValidation($message));
        $this->assertSame($fallback, $message->fresh()->content);
        $this->assertSame($fallback, app(PaymentFlexService::class)->tryConvertToFlex($message->content, $this->conversation, $message));
        $this->assertSame([$fallback], app(MultipleBubblesService::class)->parseIntoBubbles($message->content, $this->bot));
        app(FlowPluginService::class)->executePlugins($this->bot, $this->conversation, $message);
        $this->assertNull((new \ReflectionMethod(app(LineWebhookResponseService::class), 'checkoutProposal'))->invoke(app(LineWebhookResponseService::class), $this->replyContext($message), $message));
        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->andReturn('test');
        $line->shouldReceive('push')->once()->withArgs(fn ($bot, $user, $texts) => $texts === [$fallback])->andReturn(true);
        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->andReturn(false);
        $job = new ProcessAggregatedMessages($this->bot, $this->conversation, 'test', 'U-test');
        $aggregated = (new \ReflectionMethod($job, 'generateAndDeliver'))->invoke($job, 'hello', 1, $ai, $line, $bubbles);
        $this->assertSame($fallback, $aggregated->fresh()->content);
        $this->assertSame(0, CheckoutSession::count());
        $this->assertNoEffects();
    }

    private function replyContext(Message $message, string $type = 'text'): WebhookContext
    {
        $ctx = new WebhookContext($this->bot, ['type' => 'message', 'message' => ['type' => $type], 'source' => ['userId' => 'U-test'], 'replyToken' => 'test']);
        $ctx->conversation = $this->conversation;
        $ctx->userMessage = $this->conversation->messages()->create(['sender' => 'user', 'type' => $type, 'content' => 'hello']);
        $ctx->metadata['bot_message'] = $message;
        $ctx->response = ResponseEnvelope::text($message->content);

        return $ctx;
    }

    #[DataProvider('channels')]
    public function test_contact_backstop_replaces_full_output_before_bubbles_flex_and_plugins(string $type, bool $bubblesOn): void
    {
        $this->enableReplyPolicy();
        $text = 'https://lin.ee/h5wYpIf ||| @adsvance';
        $fallback = 'ขอเช็กข้อมูลล่าสุดให้ในแชทนี้ครับ';
        $receipt = $this->conversation->messages()->create(['sender' => 'bot', 'type' => 'text', 'content' => $text, 'metadata' => ['commerce_safety_cart_validation' => ['valid' => true]]]);
        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->andReturn('test');
        $line->shouldReceive('replyWithFallback')->withArgs(fn ($bot, $token, $user, $messages) => $messages === [$fallback])->andReturn(['success' => true]);
        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->andReturn($bubblesOn);
        $bubbles->shouldReceive('parseIntoBubbles')->with($fallback, Mockery::any())->andReturn([$fallback]);
        $bubbles->shouldReceive('sendBubbles')->withArgs(fn ($bot, $user, $token, $texts) => $texts === [$fallback])->andReturn(true);
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldReceive('executePlugins')->withArgs(fn ($bot, $conv, $msg) => $msg->content === $fallback && empty($msg->metadata['commerce_safety_cart_validation']))->andReturnNull();
        $lead = Mockery::mock(LeadRecoveryService::class)->shouldIgnoreMissing();
        (new LineWebhookOutputService($line, $lead, $bubbles, app(PaymentFlexService::class), $plugins))->dispatch($this->replyContext($receipt, $type));
        $this->assertSame($fallback, $receipt->fresh()->content);
        $this->assertEmpty($receipt->fresh()->metadata);
        $this->assertSame([$fallback], app(MultipleBubblesService::class)->parseIntoBubbles($text, $this->bot));
        $this->assertNoEffects();
    }

    public function test_direct_bubble_send_and_aggregate_delivery_guard_full_contact_reply(): void
    {
        $this->enableReplyPolicy();
        $fallback = 'ขอเช็กข้อมูลล่าสุดให้ในแชทนี้ครับ';
        $line = $this->mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->andReturn('test');
        $line->shouldReceive('replyWithFallback')->once()->withArgs(fn ($bot, $token, $user, $texts) => $texts === [$fallback])->andReturn(['success' => true]);
        $line->shouldReceive('push')->once()->withArgs(fn ($bot, $user, $texts) => $texts === [$fallback])->andReturn(true);
        $bubbles = app(MultipleBubblesService::class);
        $this->assertTrue($bubbles->sendBubbles($this->bot, 'U-test', 'test', ['สวัสดีครับ', '@adsvance'], $this->conversation));
        $receipt = $this->conversation->messages()->create(['sender' => 'bot', 'type' => 'text', 'content' => 'สวัสดีครับ ||| @adsvance']);
        $job = new ProcessAggregatedMessages($this->bot, $this->conversation, 'test', 'U-test');
        $this->assertTrue((new \ReflectionMethod($job, 'deliverToChannel'))->invoke($job, $receipt, $line, $bubbles));
        $this->assertSame($fallback, $receipt->fresh()->content);
        Queue::assertNotPushed(SendDelayedBubbleJob::class);
        $this->assertNoEffects();
    }

    #[DataProvider('rejectedProposals')]
    public function test_aggregate_and_save_backstops_reject_alternate_ai_results_before_persistence(string $text, string $fallback): void
    {
        $this->enableReplyPolicy();
        $validation = new CartValidation(true, [], [], 19900, false, 'test');
        $result = ['content' => 'สวัสดีครับ ||| '.$text, 'commerce_safety_cart_validation' => $validation,
            'order_payload' => ['total' => 199], 'checkout_presentation' => ['action' => 'payment'],
            'model' => 'test', 'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0], 'cost' => 0];
        $dependencies = array_map(
            fn (\ReflectionParameter $parameter) => app($parameter->getType()->getName()),
            (new \ReflectionClass(AIService::class))->getConstructor()->getParameters(),
        );
        $ai = Mockery::mock(AIService::class, $dependencies)->makePartial();
        $ai->shouldReceive('generateResponse')->andReturn($result);
        $user = $this->conversation->messages()->create(['sender' => 'user', 'type' => 'text', 'content' => 'hello']);
        Message::creating(function (Message $message) use ($fallback): void {
            if ($message->sender === 'bot') {
                $this->assertSame($fallback, $message->content);
                $this->assertEmpty($message->metadata['order_payload'] ?? null);
                $this->assertEmpty($message->metadata['checkout_presentation'] ?? null);
            }
        });
        // Run the real persistence method with a substituted generation result.
        $saved = $ai->generateAndSaveResponse($this->bot, $this->conversation, $user);
        $this->assertSame($fallback, $saved->fresh()->content);
        $this->assertNull($ai->takeCommerceSafetyCartValidation($saved));
        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->andReturn('test');
        $line->shouldReceive('push')->once()->withArgs(fn ($bot, $user, $texts) => $texts === [$fallback])->andReturn(true);
        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->andReturn(false);
        $job = new ProcessAggregatedMessages($this->bot, $this->conversation, 'test', 'U-test');
        $saved = (new \ReflectionMethod($job, 'generateAndDeliver'))->invoke($job, 'hello', 1, $ai, $line, $bubbles);
        $this->assertSame($fallback, $saved->fresh()->content);
        $this->assertSame(0, CheckoutSession::count());
        $this->assertNoEffects();
    }

    public function test_scoped_reply_guard_preserves_server_terms_and_payment_proof(): void
    {
        $this->enableReplyPolicy();
        $receipt = $this->receipt();
        $event = $this->proof($receipt);
        app(FinancialOutputGuard::class)->message($this->bot, $this->conversation, $receipt);
        $canonical = $receipt->content;
        app(CustomerReplyGuard::class)->message($this->bot, $this->conversation, $receipt);
        $this->assertSame($canonical, $receipt->fresh()->content);
        $this->assertSame($event->id, app(PaymentProofService::class)->forReceipt($this->bot, $this->conversation, $receipt)?->id);
        $this->assertSame('flex', app(PaymentFlexService::class)->tryConvertToFlex($receipt->content, $this->conversation, $receipt)['type']);
        $terms = app(CheckoutRenderer::class)->render(new CheckoutSession, 'terms');
        $this->assertSame($terms, app(CustomerReplyGuard::class)->text($this->bot, $terms));
        // Identical model wording remains denied by A2; C1 supplies no financial authority.
        $generated = app(FinancialOutputGuard::class)->generated($this->bot, ['content' => $terms]);
        $this->assertSame(self::DENIAL, app(CustomerReplyGuard::class)->generated($this->bot, $generated)['content']);
        $this->assertNoEffects();
    }
}
