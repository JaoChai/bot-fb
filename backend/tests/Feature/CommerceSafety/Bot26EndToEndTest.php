<?php

namespace Tests\Feature\CommerceSafety;

use App\Exceptions\RecentManualConfirmException;
use App\Jobs\ReserveAccountStock;
use App\Jobs\RunPaymentEffect;
use App\Jobs\SendDeliveryCard;
use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Order;
use App\Models\PaymentEffect;
use App\Models\ProductStock;
use App\Models\SlipVerification;
use App\Models\User;
use App\Models\VerifiedPaymentEvent;
use App\Services\CommerceSafety\CheckoutAuthority;
use App\Services\CommerceSafety\PaymentEffectDispatcher;
use App\Services\Delivery\StockPoolService;
use App\Services\IntentAnalysisService;
use App\Services\LineWebhook\LineWebhookOutputService;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\LineWebhook\WebhookContext;
use App\Services\ModelCapabilityService;
use App\Services\Payment\ManualPaymentConfirmService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

/**
 * In-process E2E: persisted LINE events through real response/output, AI, payment
 * and effect services, with HTTP/stock fakes. Not deployed-stage, live vision or
 * concurrent PostgreSQL proof. T17 recognition remains a separate blocked gate.
 */
class Bot26EndToEndTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithStockPool;

    private Bot $bot;

    private Conversation $conversation;

    private ProductStock $personal;

    private Bot26TransportFake $transport;

    // Effects must run after commits, outside RefreshDatabase's outer transaction.
    public function runDatabaseMigrations(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertSuccessful();
        RefreshDatabaseState::$migrated = false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new Bot26TransportFake;
        $this->transport->install();
        Queue::fake();
        Event::fake();
        $this->setUpStockPool();
        config([
            'commerce_safety.bots.26.mode' => 'enforce', 'delivery.order_payload_enabled' => true,
            'delivery.enabled' => true, 'rag.semantic_cache.enabled' => false,
            'circuit-breaker.enabled' => false, 'services.openrouter.api_key' => 'synthetic-not-a-key',
            'services.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'services.openrouter.provider_preferences' => [],
        ]);
        $owner = User::factory()->owner()->create(['email' => 'c4@example.invalid']);
        $owner->getOrCreateSettings()->update(['easyslip_api_token' => 'synthetic-token']);
        $this->bot = Bot::factory()->active()->line()->create([
            'id' => 26, 'user_id' => $owner->id, 'channel_access_token' => 'synthetic-token',
            'primary_chat_model' => 'openai/gpt-5.6-luna', 'fallback_chat_model' => null,
            'reasoning_effort' => 'medium', 'context_window' => 40, 'auto_delivery_enabled' => true,
        ]);
        $flow = Flow::factory()->default()->create([
            'id' => 24, 'bot_id' => 26, 'system_prompt' => file_get_contents(dirname(__DIR__, 3).'/resources/prompts/bot26/v28.txt'),
        ]);
        $this->bot->update(['default_flow_id' => $flow->id]);
        $this->bot->settings()->updateOrCreate(['bot_id' => 26], [
            'slip_verification_enabled' => true, 'slip_receiver_account' => '0000000026',
        ]);
        $plugin = FlowPlugin::create([
            'id' => 1, 'flow_id' => 24, 'name' => 'Synthetic C4 finance', 'type' => 'telegram',
            'enabled' => true, 'trigger_condition' => 'always',
            'config' => ['access_token' => 'synthetic-token', 'chat_id' => 'c4-finance', 'message_template' => '{amount} {product}'],
        ]);
        config(['commerce_safety.bots.26.payment_plugin_ids' => [$plugin->id]]);
        $this->conversation = Conversation::factory()->create([
            'bot_id' => 26, 'current_flow_id' => 24, 'customer_profile_id' => null,
            'external_customer_id' => 'c4-user', 'channel_type' => 'line', 'status' => 'active',
            'is_handover' => false, 'last_message_at' => now(), 'memory_notes' => [],
        ]);
        $this->personal = ProductStock::create([
            'name' => 'Nolimit Level Up+ Personal', 'slug' => 'personal', 'stock_code' => 'NLMP',
            'aliases' => ['Personal'], 'price' => '1100.00', 'vip_price' => '1000.00',
            'delivery_method' => 'stock', 'in_stock' => true, 'manual_off' => false,
            'available_count' => 10, 'display_order' => 1,
        ]);
        $this->seedAvailable(1, 'NLMP', 'synthetic-stock-only');
        $this->mock(IntentAnalysisService::class)->shouldReceive('analyzeIntent')
            ->andReturn(['intent' => 'chat', 'confidence' => 1, 'usage' => null]);
        $capabilities = $this->mock(ModelCapabilityService::class);
        $capabilities->shouldReceive('supportsReasoning', 'supportsVision', 'supportsStructuredOutput')->andReturn(true);
    }

    public function test_new_customer_changed_cart_reaccepts_support_and_terms_and_duplicate_slip_is_idempotent(): void
    {
        $other = Conversation::factory()->create(['bot_id' => 26, 'memory_notes' => []]);
        $otherBefore = $other->fresh()->getAttributes();
        $checkout = $this->quote(2);
        $this->assertSame(1, $checkout->revision);
        $this->consent(false);
        $this->quote(1);
        $this->assertDatabaseCount('checkout_sessions', 1);
        $this->assertSame(2, $checkout->fresh()->revision);
        $this->assertSame([], $checkout->fresh()->accepted);
        $this->assertSame('awaiting_confirm', $checkout->fresh()->state);
        $this->consent(false);
        $this->transport->pass('1100.00', 'C4-CHANGED');
        $ctx = $this->inbound('[synthetic original slip]', 'image');
        $event = VerifiedPaymentEvent::sole();
        $this->assertSame($event->id, $checkout->fresh()->settled_event_id);
        $this->assertSame($event->receipt_message_id, $ctx->metadata['bot_message']->id);
        $this->runEffects();
        $counts = $this->transport->counts;
        app(LineWebhookOutputService::class)->dispatch($ctx);
        $this->inbound('[duplicate synthetic slip]', 'image');
        $this->runEffects();
        foreach (['reservation', 'line_receipt', 'telegram_payment'] as $kind) {
            $this->assertSame($counts[$kind], $this->transport->counts[$kind]);
        }
        $this->assertSettled($checkout, 110000);
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        foreach (['checkout_sessions', 'verified_payment_events', 'orders'] as $table) {
            $this->assertSame(0, DB::table($table)->where('conversation_id', $other->id)->count());
        }
        $this->assertSame(1, AccountDelivery::sole()->items()->count());
    }

    public function test_vip_manual_confirmation_uses_trusted_price_and_repeated_confirmation_has_no_effect(): void
    {
        $this->conversation->update(['memory_notes' => [[
            'id' => '00000000-0000-0000-0000-000000000026',
            'type' => 'memory', 'source' => 'vip_manual', 'content' => 'Synthetic trusted entitlement',
        ]]]);
        $checkout = $this->quote(1, 1000);
        $this->consent(true);
        $service = app(ManualPaymentConfirmService::class);
        $result = $service->confirm($this->bot, $this->conversation, '1000.00', $this->bot->user_id,
            null, $checkout->id, $checkout->revision);
        $this->assertTrue($result['order_created']);
        $this->runEffects();
        try {
            $service->confirm($this->bot, $this->conversation, '1000.00', $this->bot->user_id,
                null, $checkout->id, $checkout->revision);
            $this->fail('Repeated owner confirmation must be rejected.');
        } catch (RecentManualConfirmException) {
            $this->assertSame('manual', VerifiedPaymentEvent::sole()->source);
        }
        $this->runEffects();
        $this->assertSettled($checkout, 100000);
        $this->assertSame(0, $this->transport->counts['easyslip']);
        $this->assertSame(1, $checkout->fresh()->revision);
        $this->assertSame(['confirm'], array_keys($checkout->fresh()->accepted));
    }

    public function test_money_after_stock_closes_preserves_proof_on_paid_hold_without_fulfillment(): void
    {
        $checkout = $this->quote(1);
        $this->consent(false);
        $this->personal->update(['manual_off' => true, 'in_stock' => false, 'available_count' => 0]);
        $this->transport->pass('1100.00', 'C4-STOCK-CLOSED');
        $this->inbound('[synthetic paid slip]', 'image');
        $this->runEffects();
        $event = VerifiedPaymentEvent::sole();
        $this->assertSame('paid_hold', $checkout->fresh()->state);
        $this->assertSame('manual_hold', $event->disposition);
        $this->assertSame($checkout->id, $event->checkout_id);
        $this->assertSame('passed', SlipVerification::findOrFail($event->slip_verification_id)->status);
        $this->assertNotNull($event->receiptMessage);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('account_deliveries', 0);
        $this->assertSame(['line_receipt'], PaymentEffect::pluck('kind')->all());
        $this->assertSame(1, $this->transport->counts['line_receipt']);
        $this->assertSame(0, $this->transport->counts['telegram_payment']);
        $this->assertSame(0, $this->transport->counts['reservation']);
        Queue::assertNotPushed(ReserveAccountStock::class);
        Queue::assertNotPushed(SendDeliveryCard::class);
    }

    public function test_ambiguous_telegram_leaves_receipt_and_stock_independent_and_is_never_blindly_resent(): void
    {
        $checkout = $this->quote(1);
        $this->consent(false);
        $this->transport->pass('1100.00', 'C4-TIMEOUT');
        $this->transport->telegramTimeout = true;
        $this->inbound('[synthetic slip]', 'image');
        $this->runEffects();
        $telegram = PaymentEffect::where('kind', 'telegram_payment')->sole();
        $this->assertSame('uncertain', $telegram->state);
        $this->assertSame(1, PaymentEffect::where('state', 'uncertain')->count());
        $this->travel(1)->days();
        Queue::fake();
        $this->artisan('payment-effects:reconcile')->expectsOutputToContain('uncertain=1')->assertSuccessful();
        $this->runEffects();
        Queue::assertNotPushed(RunPaymentEffect::class, fn ($job) => $job->effectId === $telegram->id);
        $this->assertSame(1, $telegram->fresh()->attempt_count);
        $this->assertSettled($checkout, 110000, 'uncertain');
    }

    #[DataProvider('unverifiedImages')]
    public function test_failed_slips_and_image_classifications_never_authorize_money(string $classification, string $providerError, string $expectedStatus): void
    {
        $this->quote(1);
        $this->consent(false);
        $this->transport->modelOutput = $classification;
        $this->transport->slip = ['message' => $providerError];
        $this->transport->slipStatus = $providerError === 'SLIP_NOT_FOUND' ? 404 : 400;
        $ctx = $this->inbound('[synthetic image; no payment proof]', 'image');
        $this->runEffects();
        foreach (['verified_payment_events', 'orders', 'payment_effects', 'account_deliveries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame('payable', CheckoutSession::sole()->state);
        if ($expectedStatus === 'not_slip') {
            $this->assertDatabaseCount('slip_verifications', 0);
        } else {
            $this->assertDatabaseHas('slip_verifications', ['status' => $expectedStatus]);
        }
        $this->assertStringNotContainsString('[ยืนยันชำระเงิน]', $ctx->metadata['bot_message']->fresh()->content);
        $this->assertStringNotContainsString('เงินเข้าแล้ว', (string) $ctx->response->payload);
        foreach (['reservation', 'line_receipt', 'telegram_payment'] as $kind) {
            $this->assertSame(0, $this->transport->counts[$kind]);
        }
        $this->assertSame(1, $this->transport->counts['easyslip']);
        Queue::assertNotPushed(ReserveAccountStock::class);
        Queue::assertNotPushed(SendDeliveryCard::class);
    }

    public static function unverifiedImages(): array
    {
        return [
            'failed EasySlip' => ['{"is_slip":true,"reply":""}', 'SLIP_NOT_FOUND', 'fake'],
            'T16 original unreadable image' => ['{"is_slip":true,"reply":""}', 'INVALID_IMAGE_TYPE', 'unreadable'],
            'T17 camera photo canned classification' => ['{"is_slip":false,"reply":"กรุณาส่งรูปต้นฉบับจากแอปธนาคารครับ"}', 'INVALID_IMAGE_TYPE', 'not_slip'],
            'T30 Meta screenshot' => ['{"is_slip":false,"reply":"ติดต่อฝ่ายเทคนิค https://lin.ee/sTD5TQL ครับ"}', 'INVALID_IMAGE_TYPE', 'not_slip'],
        ];
    }

    private function quote(int $quantity, int $price = 1100): CheckoutSession
    {
        $this->transport->modelOutput = 'ยืนยันรายการนี้ไหมครับ'."\n".'[[ORDER]]'.json_encode([
            'items' => [['name' => 'Nolimit Level Up+ Personal (ผูกบัตร)', 'qty' => $quantity, 'price' => $price]],
            'total' => $quantity * $price,
        ], JSON_UNESCAPED_UNICODE).'[[/ORDER]]';
        $this->inbound("เอา Personal ผูกบัตร {$quantity} ตัว ไม่เอาเพจ");
        $checkout = CheckoutSession::sole();
        $this->assertNotNull($checkout->presented_at);
        $this->assertSame($quantity * $price * 100, $checkout->total_minor);
        $this->assertGreaterThan(0, $this->transport->counts['openrouter']);

        return $checkout;
    }

    private function consent(bool $vip): void
    {
        $steps = $vip ? [['ยืนยัน', 'payable', 'confirm']] : [
            ['ยืนยัน', 'awaiting_support', 'confirm'], ['ตกลง', 'awaiting_terms', 'support_delay'], ['ยอมรับ', 'payable', 'terms'],
        ];
        foreach ($steps as [$text, $state, $stage]) {
            $ctx = $this->inbound($text);
            $checkout = CheckoutSession::sole();
            $this->assertSame($state, $checkout->state);
            $this->assertSame($ctx->userMessage->id, $checkout->accepted[$stage]);
            $this->assertSame(1, DB::table('checkout_consent_acceptances')->where([
                'checkout_id' => $checkout->id, 'revision' => $checkout->revision, 'stage' => $stage,
            ])->count());
            app(CheckoutAuthority::class)->accept($this->bot, $this->conversation, $ctx->userMessage);
            $this->assertSame($checkout->accepted, $checkout->fresh()->accepted);
        }
    }

    private function inbound(string $text, string $type = 'text'): WebhookContext
    {
        $this->travel(1)->seconds();
        $this->bot->refresh();
        $this->conversation->refresh();
        $id = 'c4-event-'.($this->conversation->messages()->count() + 1);
        $this->transport->inboundId = $id;
        $event = [
            'type' => 'message', 'replyToken' => $id, 'source' => ['type' => 'user', 'userId' => 'c4-user'],
            'message' => ['id' => $id, 'type' => $type] + ($type === 'text' ? ['text' => $text] : []),
            'webhookEventId' => $id, 'deliveryContext' => ['isRedelivery' => false], 'timestamp' => now()->getTimestampMs(),
        ];
        $ctx = new WebhookContext($this->bot, $event);
        $ctx->conversation = $this->conversation;
        $ctx->userMessage = $this->conversation->messages()->create([
            'sender' => 'user', 'type' => $type, 'content' => $text, 'external_message_id' => $id,
            'media_url' => $type === 'image' ? 'https://fixtures.invalid/'.$id.'.jpg' : null,
            'event_timestamp' => $event['timestamp'],
        ]);
        app(LineWebhookResponseService::class)->generate($ctx);
        $this->assertNotNull($ctx->response);
        app(LineWebhookOutputService::class)->dispatch($ctx);
        $this->assertLessThanOrEqual(1, $this->transport->lineReplies[$id] ?? 0);
        $this->assertLessThanOrEqual(1, $this->transport->telegramCalls[$id] ?? 0);

        return $ctx;
    }

    private function runEffects(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        foreach (PaymentEffect::orderBy('kind')->get() as $effect) {
            app(PaymentEffectDispatcher::class)->run($effect->id);
        }
    }

    private function assertSettled(CheckoutSession $checkout, int $total, string $telegramState = 'succeeded'): void
    {
        foreach (['checkout_sessions', 'verified_payment_events', 'orders'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $event = VerifiedPaymentEvent::sole();
        $this->assertSame($checkout->id, $event->checkout_id);
        $this->assertSame(Order::sole()->id, $event->order_id);
        $this->assertSame($total, $checkout->fresh()->total_minor);
        $this->assertEquals($total / 100, Order::sole()->total_amount);
        $this->assertSame('paid', $checkout->fresh()->state);
        $this->assertSame(['line_receipt', 'reserve_stock', 'telegram_payment'], PaymentEffect::orderBy('kind')->pluck('kind')->all());
        foreach (PaymentEffect::all() as $effect) {
            $this->assertSame($event->id, $effect->event_id);
            $this->assertSame(1, $effect->attempt_count);
            $this->assertSame($effect->kind === 'telegram_payment' ? $telegramState : 'succeeded', $effect->state);
        }
        foreach (['line_receipt', 'telegram_payment', 'reservation'] as $kind) {
            $this->assertSame(1, $this->transport->counts[$kind], $kind);
        }
        $this->assertDatabaseCount('account_deliveries', 1);
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_reserved')->count());
    }
}

/** Test-local transports: synthetic responses only, with counters including lost replies. */
final class Bot26TransportFake
{
    public string $modelOutput = 'สวัสดีครับ';

    public array $slip = ['message' => 'INVALID_IMAGE_TYPE'];

    public int $slipStatus = 400;

    public bool $telegramTimeout = false;

    public string $inboundId = 'none';

    public array $lineReplies = [];

    public array $telegramCalls = [];

    public array $counts = ['openrouter' => 0, 'easyslip' => 0, 'line_receipt' => 0, 'telegram_payment' => 0, 'reservation' => 0];

    public function install(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => function () {
                $this->counts['openrouter']++;

                return Http::response([
                    'id' => 'c4-model-'.$this->counts['openrouter'], 'model' => 'openai/gpt-5.6-luna',
                    'choices' => [['message' => ['content' => $this->modelOutput], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
                ]);
            },
            'api.easyslip.com/*' => function () {
                $this->counts['easyslip']++;

                return Http::response($this->slip, $this->slipStatus);
            },
            'api.line.me/*' => function (Request $request) {
                if (PaymentEffect::where('kind', 'line_receipt')->where('retry_key', $request->header('X-Line-Retry-Key')[0] ?? 'none')->exists()) {
                    $this->counts['line_receipt']++;
                } elseif (str_ends_with($request->url(), '/reply') || str_ends_with($request->url(), '/push')) {
                    $this->lineReplies[$this->inboundId] = ($this->lineReplies[$this->inboundId] ?? 0) + 1;
                }

                return Http::response(['sentMessages' => [['id' => 'c4-line-message']]]);
            },
            'api.telegram.org/*' => function (Request $request) {
                $this->telegramCalls[$this->inboundId] = ($this->telegramCalls[$this->inboundId] ?? 0) + 1;
                // Payment notification uses the frozen minimal template. Admin failure
                // alerts are separate nonfinancial diagnostics and may also be sent.
                if (preg_match('/^\d[\d,.]* Nolimit/u', $request['text'] ?? '') === 1) {
                    $this->counts['telegram_payment']++;
                    if ($this->telegramTimeout) {
                        throw new ConnectionException('Synthetic response lost after send');
                    }
                }

                return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
            },
        ]);
        $pool = Mockery::mock(StockPoolService::class)->makePartial();
        $pool->shouldReceive('reserveOne')->andReturnUsing(function (string $code, string $reference): ?array {
            $this->counts['reservation']++;

            // Real reservation logic against InteractsWithStockPool's private SQLite DB.
            return (new StockPoolService)->reserveOne($code, $reference);
        });
        app()->instance(StockPoolService::class, $pool);
    }

    public function pass(string $amount, string $reference): void
    {
        $this->slipStatus = 200;
        $this->slip = ['data' => ['amountInSlip' => $amount, 'rawSlip' => [
            'transRef' => $reference, 'receiver' => ['account' => ['bank' => ['account' => '0000000026']]],
        ]]];
    }
}
