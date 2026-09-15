<?php

namespace Tests\Feature\CommerceSafety;

use App\Jobs\ReserveAccountStock;
use App\Jobs\SendDeliveryCard;
use App\Models\AccountDelivery;
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
use App\Services\CommerceSafety\CanonicalCartValidator;
use App\Services\CommerceSafety\CheckoutAuthority;
use App\Services\CommerceSafety\CheckoutConsentPolicy;
use App\Services\CommerceSafety\PaymentProofService;
use App\Services\CommerceSafety\SafetyScope;
use App\Services\Delivery\AccountDeliveryService;
use App\Services\Delivery\ProductMapper;
use App\Services\Delivery\StockPoolService;
use App\Services\FlowPluginService;
use App\Services\LINEService;
use App\Services\OrderService;
use App\Services\Payment\ManualPaymentConfirmService;
use App\Services\Payment\SlipRetryService;
use App\Services\Payment\SlipVerificationResult;
use App\Services\Payment\SlipVerificationService;
use App\Services\Payment\TelegramAlertBotService;
use App\Services\PaymentFlexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class CheckoutSettlementTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    private User $owner;

    private Bot $bot;

    private Conversation $conversation;

    /** @var array<string, ProductStock> */
    private array $products;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake([ReserveAccountStock::class, SendDeliveryCard::class]);
        $this->setUpStockPool();

        $this->owner = User::factory()->owner()->create();
        $this->bot = Bot::factory()->active()->create([
            'user_id' => $this->owner->id,
            'auto_delivery_enabled' => true,
        ]);
        $this->conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => [],
        ]);
        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);
        $plugin = FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'order',
            'name' => 'Settlement fixture',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => [],
        ]);
        config([
            "commerce_safety.bots.{$this->bot->id}" => [
                'mode' => 'enforce',
                'payment_plugin_ids' => [$plugin->id],
            ],
            'delivery.enabled' => true,
            'delivery.max_qty' => 20,
        ]);

        $this->products = [
            'personal' => $this->product([
                'name' => 'Nolimit Level Up+ Personal',
                'slug' => 'personal',
                'stock_code' => 'NLMP',
                'aliases' => ['Personal'],
                'delivery_method' => 'stock',
                'price' => '1100.00',
                'vip_price' => '1000.00',
                'available_count' => 20,
            ]),
            'page' => $this->product([
                'name' => 'Page',
                'slug' => 'page',
                'stock_code' => 'PAGE',
                'aliases' => ['เพจ'],
                'delivery_method' => 'support_link',
                'price' => '199.00',
                'vip_price' => null,
                'available_count' => null,
            ]),
            'g3d' => $this->product([
                'name' => 'G3D',
                'slug' => 'g3d',
                'stock_code' => 'G3D',
                'aliases' => ['ไก่'],
                'delivery_method' => 'stock',
                'price' => '50.00',
                'vip_price' => null,
                'available_count' => 50,
            ]),
        ];
    }

    #[Test]
    public function it_settles_exact_page_and_g3d_prices_into_one_canonical_order(): void
    {
        $checkout = $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
            ['name' => 'G3D', 'method' => 'none', 'qty' => 2, 'price_minor' => 5000],
        ], 29900);
        $event = $this->automaticEvent('299.00', 'TX-EXACT');

        $outcome = app(CheckoutAuthority::class)->settle($checkout, $event);

        $this->assertSame('settled', $outcome->action);
        $this->assertSame('paid', $checkout->fresh()->state);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', ['id' => $event->fresh()->order_id, 'total_amount' => 299]);
        $this->assertDatabaseHas('order_items', [
            'product_name' => 'Page', 'category' => 'page', 'quantity' => 1,
            'unit_price' => 199, 'subtotal' => 199,
        ]);
        $this->assertDatabaseHas('order_items', [
            'product_name' => 'G3D', 'category' => 'g3d', 'quantity' => 2,
            'unit_price' => 50, 'subtotal' => 100,
        ]);
        $this->assertSame($checkout->id, $event->fresh()->checkout_id);
        $this->assertSame($event->id, $checkout->fresh()->settled_event_id);
        Queue::assertNotPushed(ReserveAccountStock::class);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_slip_matching_text_but_not_checkout_total_is_preserved_on_paid_hold(): void
    {
        $checkout = $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);
        $event = $this->automaticEvent('200.00', 'TX-WRONG-AMOUNT');

        $result = app(CheckoutAuthority::class)->settle($checkout, $event);

        $this->assertSame('manual_hold', $result->action);
        $this->assertSame('paid_hold', $checkout->fresh()->state);
        $this->assertDatabaseCount('verified_payment_events', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame($checkout->id, $event->fresh()->checkout_id);
        Queue::assertNotPushed(ReserveAccountStock::class);
    }

    #[Test]
    public function underpriced_page_or_missing_current_consent_cannot_settle(): void
    {
        $checkout = $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);
        $items = $checkout->items;
        $items[0]['price_minor'] = 10000;
        $items[0]['line_total_minor'] = 10000;
        $checkout->forceFill(['items' => $items, 'total_minor' => 10000])->save();

        $result = app(CheckoutAuthority::class)->settle(
            $checkout->fresh(),
            $this->automaticEvent('100.00', 'TX-UNDERPRICED-PAGE'),
        );

        $this->assertSame('manual_hold', $result->action);
        $this->assertSame('paid_hold', $checkout->fresh()->state);
        $this->assertDatabaseCount('orders', 0);

        $second = $this->payable([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000);
        DB::table('checkout_consent_acceptances')->where('checkout_id', $second->id)->delete();
        $this->assertSame('manual_hold', app(CheckoutAuthority::class)
            ->settle($second, $this->automaticEvent('50.00', 'TX-NO-CONSENT'))->action);
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function stock_closed_after_quote_enters_paid_hold(): void
    {
        $checkout = $this->payable([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 2, 'price_minor' => 5000],
        ], 10000);
        $this->products['g3d']->update(['manual_off' => true, 'in_stock' => false]);

        $result = app(CheckoutAuthority::class)
            ->settle($checkout, $this->automaticEvent('100.00', 'TX-CLOSED'));

        $this->assertSame('manual_hold', $result->action);
        $this->assertSame('paid_hold', $checkout->fresh()->state);
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function mutated_slip_status_or_amount_cannot_authorize_a_recorded_event(): void
    {
        $checkout = $this->payable([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000);
        $event = $this->automaticEvent('50.00', 'TX-MUTATED-PROOF');
        SlipVerification::query()->whereKey($event->slip_verification_id)
            ->update(['status' => 'amount_mismatch', 'amount' => '49.00']);

        $result = app(CheckoutAuthority::class)->settle($checkout, $event);

        $this->assertSame('manual_hold', $result->action);
        $this->assertSame('paid_hold', $checkout->fresh()->state);
        $this->assertDatabaseCount('verified_payment_events', 1);
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function revoked_vip_entitlement_before_payment_enters_paid_hold(): void
    {
        $this->conversation->update(['memory_notes' => [[
            'id' => '00000000-0000-0000-0000-000000000026',
            'type' => 'memory',
            'source' => 'vip_manual',
            'content' => 'trusted entitlement',
        ]]]);
        $checkout = $this->payable([
            ['name' => 'Personal', 'method' => 'card', 'qty' => 1, 'price_minor' => 100000],
        ], 100000);
        $this->conversation->update(['memory_notes' => []]);

        $result = app(CheckoutAuthority::class)
            ->settle($checkout, $this->automaticEvent('1000.00', 'TX-REVOKED-VIP'));

        $this->assertSame('manual_hold', $result->action);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('verified_payment_events', 1);
    }

    #[Test]
    public function the_same_provider_event_cannot_settle_a_second_checkout(): void
    {
        $first = $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);
        $event = $this->automaticEvent('199.00', 'TX-REUSED');
        $this->assertSame('settled', app(CheckoutAuthority::class)->settle($first, $event)->action);

        $second = $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900, revision: 2);
        $this->assertSame('manual_hold', app(CheckoutAuthority::class)->settle($second, $event)->action);

        $this->assertSame('paid', $first->fresh()->state);
        $this->assertSame('paid_hold', $second->fresh()->state);
        $this->assertSame($first->id, $event->fresh()->checkout_id);
        $this->assertDatabaseCount('orders', 1);
    }

    #[Test]
    public function repeated_settlement_after_the_old_cache_window_is_idempotent(): void
    {
        $checkout = $this->payable([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000);
        $event = $this->automaticEvent('50.00', 'TX-RETRY');
        $authority = app(CheckoutAuthority::class);

        $first = $authority->settle($checkout, $event);
        $event->timestamps = false;
        DB::table('verified_payment_events')->where('id', $event->id)
            ->update(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $recordedAgain = app(PaymentProofService::class)->record(
            $this->bot,
            $this->conversation,
            SlipVerification::findOrFail($event->slip_verification_id),
            Message::findOrFail($event->receipt_message_id),
            null,
        );
        $second = $authority->settle($checkout->fresh(), $event->fresh());

        $this->assertSame('settled', $first->action);
        $this->assertSame('settled', $second->action);
        $this->assertSame($event->id, $recordedAgain->id);
        $this->assertSame($first->checkout->settled_event_id, $second->checkout->settled_event_id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, Order::first()->items()->count());
    }

    #[Test]
    public function distinct_automatic_and_manual_proofs_for_one_checkout_create_only_one_order(): void
    {
        $checkout = $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);
        $automatic = $this->automaticEvent('199.00', 'TX-AUTO-MANUAL');
        $manual = $this->manualEvent('199.00', $this->owner->id, $checkout);

        $first = app(CheckoutAuthority::class)->settle($checkout, $automatic);
        $second = app(CheckoutAuthority::class)->settle($checkout->fresh(), $manual);

        $this->assertSame('settled', $first->action);
        $this->assertSame('manual_hold', $second->action);
        $this->assertSame($checkout->id, $automatic->fresh()->checkout_id);
        $this->assertSame($checkout->id, $manual->fresh()->checkout_id);
        $this->assertDatabaseCount('orders', 1);
    }

    #[Test]
    public function automatic_path_records_and_settles_before_leaving_effects_for_a3(): void
    {
        $checkout = $this->payable([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000);
        $receipt = $this->conversation->messages()->create([
            'sender' => 'bot',
            'type' => 'text',
            'content' => 'เงินเข้าแล้ว 50 บาท',
            'metadata' => [
                'slip_verification' => true,
                'slip_status' => 'passed',
                'slip_trans_ref' => 'TX-AUTO-PATH',
            ],
        ]);
        $slip = SlipVerification::create([
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'message_id' => null,
            'trans_ref' => 'TX-AUTO-PATH',
            'amount' => '50.00',
            'status' => 'passed',
        ]);
        $result = new SlipVerificationResult(
            isSlip: true,
            passed: true,
            amount: 50,
            transRef: 'TX-AUTO-PATH',
            orderItems: [['name' => 'invented prose item', 'qty' => -9]],
        );
        $result->slipVerificationId = $slip->id;

        ReserveAccountStock::dispatchIfItemsTrusted(
            $this->bot->id,
            $this->conversation->id,
            $result,
        );

        $this->assertSame('paid', $checkout->fresh()->state);
        $this->assertDatabaseCount('verified_payment_events', 1);
        $this->assertDatabaseHas('order_items', ['product_name' => 'G3D', 'quantity' => 1]);
        $this->assertDatabaseMissing('order_items', ['product_name' => 'invented prose item']);
        Queue::assertNotPushed(ReserveAccountStock::class);
        $this->assertNotNull($receipt->id);
    }

    #[Test]
    public function retry_path_settles_once_and_a_repeated_worker_creates_no_second_event_or_order(): void
    {
        $checkout = $this->payable([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000);
        $imageMessage = $this->conversation->messages()->create([
            'sender' => 'user', 'type' => 'image', 'content' => '[image]',
        ]);
        $realVerifier = app(SlipVerificationService::class);
        $verifier = Mockery::mock(SlipVerificationService::class);
        $verifier->shouldReceive('verify')->once()->andReturnUsing(function () {
            $slip = SlipVerification::create([
                'bot_id' => $this->bot->id,
                'conversation_id' => $this->conversation->id,
                'message_id' => $this->conversation->messages()->where('type', 'image')->value('id'),
                'trans_ref' => 'TX-RETRY-PATH',
                'amount' => '50.00',
                'status' => 'passed',
            ]);
            $result = new SlipVerificationResult(
                isSlip: true,
                passed: true,
                amount: 50,
                transRef: 'TX-RETRY-PATH',
                orderSummary: 'G3D x1',
                orderItems: [['name' => 'G3D', 'qty' => 1, 'total' => '50']],
            );
            $result->slipVerificationId = $slip->id;

            return $result;
        });
        $verifier->shouldReceive('settleVerifiedReceipt')->once()
            ->andReturnUsing(fn (...$arguments) => $realVerifier->settleVerifiedReceipt(...$arguments));
        $service = new SlipRetryService(
            $verifier,
            Mockery::mock(PaymentFlexService::class),
            Mockery::mock(LINEService::class),
            Mockery::mock(FlowPluginService::class),
            app(SafetyScope::class),
        );

        $service->retry($this->bot, $this->conversation, $imageMessage, 'https://invalid.test/slip', 1);
        $service->retry($this->bot, $this->conversation, $imageMessage, 'https://invalid.test/slip', 1);

        $this->assertSame('paid', $checkout->fresh()->state);
        $this->assertDatabaseCount('verified_payment_events', 1);
        $this->assertDatabaseCount('orders', 1);
        Queue::assertNotPushed(ReserveAccountStock::class);
        Http::assertNothingSent();
    }

    #[Test]
    public function an_unauthorized_manual_actor_cannot_bind_payment(): void
    {
        $checkout = $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);
        $other = User::factory()->owner()->create();

        $this->expectException(ValidationException::class);
        $this->manualEvent('199.00', $other->id, $checkout);
    }

    #[Test]
    public function manual_service_rejects_an_unauthorized_actor_before_recording_money_in(): void
    {
        $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);
        $other = User::factory()->owner()->create();

        try {
            app(ManualPaymentConfirmService::class)->confirm(
                $this->bot,
                $this->conversation,
                199,
                $other->id,
            );
            $this->fail('Unauthorized actor confirmed received money.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('slip_verifications', 0);
        $this->assertDatabaseCount('verified_payment_events', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function genuine_money_without_a_checkout_or_with_only_a_legacy_order_stays_unlinked_for_review(): void
    {
        Order::factory()->create([
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'completed',
            'total_amount' => 199,
        ]);
        $event = $this->automaticEvent('199.00', 'TX-NO-CHECKOUT');

        $outcome = app(CheckoutAuthority::class)->settleEvent($event);

        $this->assertSame('manual_hold', $outcome->action);
        $this->assertNull($outcome->checkout);
        $this->assertNull($event->fresh()->checkout_id);
        $this->assertNull($event->fresh()->order_id);
        $this->assertDatabaseCount('orders', 1);
    }

    #[Test]
    public function an_old_unmatched_payment_cannot_bind_to_a_checkout_created_later(): void
    {
        $event = $this->automaticEvent('199.00', 'TX-OLD-UNMATCHED');

        $first = app(CheckoutAuthority::class)->settleEvent($event);
        $checkout = $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);
        $retry = app(CheckoutAuthority::class)->settleEvent($event->fresh());

        $this->assertSame('manual_hold', $first->action);
        $this->assertSame('manual_hold', $retry->action);
        $this->assertNull($retry->checkout);
        $this->assertNull($event->fresh()->checkout_id);
        $this->assertSame('manual_hold', $event->fresh()->disposition);
        $this->assertSame('no_eligible_checkout', $event->fresh()->hold_reason);
        $this->assertNotNull($event->fresh()->held_at);
        $this->assertSame('payable', $checkout->fresh()->state);
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function manual_amount_and_item_overrides_cannot_invent_or_replace_the_persisted_cart(): void
    {
        $checkout = $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);

        $result = app(ManualPaymentConfirmService::class)->confirm(
            $this->bot,
            $this->conversation,
            199,
            $this->owner->id,
            [['name' => 'G3D', 'qty' => 999, 'total' => '199']],
        );

        $this->assertTrue($result['order_created']);
        $this->assertSame('paid', $checkout->fresh()->state);
        $this->assertDatabaseHas('order_items', ['product_name' => 'Page', 'quantity' => 1]);
        $this->assertDatabaseMissing('order_items', ['product_name' => 'G3D']);
        Http::assertNothingSent();
        Queue::assertNotPushed(ReserveAccountStock::class);
    }

    #[Test]
    public function manual_api_without_checkout_records_money_proof_but_invents_no_cart(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/conversations/{$this->conversation->id}/confirm-payment", ['amount' => 199]);

        $response->assertOk()->assertJsonPath('order_created', false);
        $this->assertDatabaseHas('slip_verifications', [
            'conversation_id' => $this->conversation->id,
            'status' => 'manual_confirmed',
            'amount' => 199,
        ]);
        $this->assertDatabaseHas('verified_payment_events', [
            'conversation_id' => $this->conversation->id,
            'source' => 'manual',
            'checkout_id' => null,
            'order_id' => null,
        ]);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    #[Test]
    public function telegram_manual_callback_uses_the_same_checkout_settlement_authority(): void
    {
        $checkout = $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);
        $flow = Flow::query()->where('bot_id', $this->bot->id)->firstOrFail();
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'Settlement callback',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => [
                'access_token' => 'SETTLE-TOK',
                'chat_id' => '999',
                'authorized_user_mappings' => ['77' => $this->owner->id],
            ],
        ]);
        config(['services.telegram_alert.secret' => 'SETTLE-SECRET']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SETTLE-SECRET'])
            ->postJson('/api/webhook/telegram-alert/SETTLE-TOK', ['callback_query' => [
                'id' => 'settle-callback',
                'from' => ['id' => 77, 'first_name' => 'Owner'],
                'message' => ['message_id' => 88, 'chat' => ['id' => 999]],
                'data' => "pc|{$checkout->id}|{$checkout->revision}|199",
            ]])
            ->assertOk();

        $this->assertSame('paid', $checkout->fresh()->state);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('verified_payment_events', 1);
        Queue::assertNotPushed(ReserveAccountStock::class);
    }

    #[Test]
    public function scoped_telegram_button_encodes_the_exact_checkout_revision(): void
    {
        $checkout = $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900, revision: 7);
        $flow = Flow::query()->where('bot_id', $this->bot->id)->firstOrFail();
        $this->bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'Settlement callback',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'SETTLE-BUTTON', 'chat_id' => '999'],
        ]);
        $captured = null;
        $this->mock(TelegramAlertBotService::class, function ($mock) use (&$captured): void {
            $mock->shouldReceive('sendMessage')->once()
                ->andReturnUsing(function ($token, $chat, $text, $keyboard) use (&$captured): array {
                    $captured = $keyboard;

                    return [];
                });
        });

        app(SlipVerificationService::class)->notifyAdmin(
            $this->bot,
            $this->conversation,
            new SlipVerificationResult(
                isSlip: true,
                passed: false,
                failReason: 'unreadable',
                expectedAmount: 199.0,
            ),
        );

        $this->assertSame("pc|{$checkout->id}|7|199", $captured[0][0]['callback_data']);
    }

    #[Test]
    public function scoped_telegram_confirmation_fails_closed_for_unmapped_or_stale_buttons(): void
    {
        $checkout = $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);
        $flow = Flow::query()->where('bot_id', $this->bot->id)->firstOrFail();
        $plugin = FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'Settlement callback',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'SETTLE-DENY', 'chat_id' => '999'],
        ]);
        config(['services.telegram_alert.secret' => 'SETTLE-SECRET']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $post = fn (string $data) => $this
            ->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SETTLE-SECRET'])
            ->postJson('/api/webhook/telegram-alert/SETTLE-DENY', ['callback_query' => [
                'id' => bin2hex(random_bytes(4)),
                'from' => ['id' => 77, 'first_name' => 'Group member'],
                'message' => ['message_id' => 88, 'chat' => ['id' => 999]],
                'data' => $data,
            ]]);

        $post('pc|'.$this->conversation->id.'|199')->assertOk();
        $this->assertSame('payable', $checkout->fresh()->state);
        $this->assertDatabaseCount('slip_verifications', 0);

        $otherOwner = User::factory()->owner()->create();
        $plugin->update(['config' => array_merge($plugin->config, [
            'authorized_user_mappings' => ['77' => $otherOwner->id],
        ])]);
        $post("pc|{$checkout->id}|{$checkout->revision}|199")->assertOk();
        $this->assertSame('payable', $checkout->fresh()->state);
        $this->assertDatabaseCount('slip_verifications', 0);

        $plugin->update(['config' => array_merge($plugin->config, [
            'authorized_user_mappings' => ['77' => $this->owner->id],
        ])]);
        $post("pc|{$checkout->id}|2|199")->assertOk();
        $this->assertSame('payable', $checkout->fresh()->state);
        $this->assertDatabaseCount('slip_verifications', 0);
    }

    #[Test]
    public function scoped_manual_api_rejects_more_than_two_decimals_without_recording_money(): void
    {
        $checkout = $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);
        $messagesBefore = Message::count();

        foreach (['198.996', 198.996, '1e2', 'NaN', '1,000', true] as $invalid) {
            $this->actingAs($this->owner)
                ->postJson("/api/conversations/{$this->conversation->id}/confirm-payment", ['amount' => $invalid])
                ->assertUnprocessable();
        }

        try {
            app(ManualPaymentConfirmService::class)->confirm(
                $this->bot,
                $this->conversation,
                INF,
                $this->owner->id,
            );
            $this->fail('Non-finite scoped amount was accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('payable', $checkout->fresh()->state);
        $this->assertDatabaseCount('slip_verifications', 0);
        $this->assertSame($messagesBefore, Message::count());
        $this->assertDatabaseCount('verified_payment_events', 0);
    }

    #[Test]
    public function scoped_manual_confirmation_rolls_back_all_local_rows_when_proof_recording_crashes(): void
    {
        $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);
        $messagesBefore = Message::count();
        $this->mock(PaymentProofService::class, function ($mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('crash boundary'));
        });

        try {
            app(ManualPaymentConfirmService::class)->confirm(
                $this->bot,
                $this->conversation,
                199,
                $this->owner->id,
            );
            $this->fail('Expected the simulated proof-recording crash.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('crash boundary', $exception->getMessage());
        }

        $this->assertDatabaseCount('slip_verifications', 0);
        $this->assertSame($messagesBefore, Message::count());
        $this->assertDatabaseCount('verified_payment_events', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function scoped_plugin_extraction_cannot_fabricate_a_legacy_paid_order(): void
    {
        $message = $this->conversation->messages()->create([
            'sender' => 'bot', 'type' => 'text', 'content' => 'เงินเข้าแล้ว Page 1,500 บาท',
        ]);

        $order = app(OrderService::class)->createFromPluginExtraction(
            $this->bot,
            $this->conversation,
            $message,
            ['amount' => 1500, 'product' => 'Page'],
        );

        $this->assertNull($order);
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function reservation_revalidates_stock_and_rejects_negative_or_clamped_quantities(): void
    {
        $checkout = $this->settledCheckout([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 2, 'price_minor' => 5000],
        ], 10000, 'TX-BOUNDARY');
        $slipId = $checkout->settledEvent->slip_verification_id;

        $negative = app(AccountDeliveryService::class)->createFromPayment(
            $this->bot, $this->conversation, $slipId, 100.0,
            [['name' => 'G3D', 'qty' => -2, 'total' => '100']],
        );
        $this->assertNull($negative);
        $this->assertSame('paid_hold', $checkout->fresh()->state);
        $this->assertDatabaseCount('account_deliveries', 0);

        $oversizedCheckout = $this->settledCheckout([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000, 'TX-BOUNDARY-CLAMP', revision: 2);
        $oversized = app(AccountDeliveryService::class)->createFromPayment(
            $this->bot,
            $this->conversation,
            $oversizedCheckout->settledEvent->slip_verification_id,
            50.0,
            [['name' => 'G3D', 'qty' => 21, 'total' => '1050']],
        );
        $this->assertNull($oversized);
        $this->assertSame('paid_hold', $oversizedCheckout->fresh()->state);
        $this->assertDatabaseCount('account_deliveries', 0);

        $second = $this->settledCheckout([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000, 'TX-BOUNDARY-CLOSED', revision: 3);
        $this->products['g3d']->update(['available_count' => 0]);

        $job = new ReserveAccountStock(
            $this->bot->id,
            $this->conversation->id,
            $second->settledEvent->slip_verification_id,
            50.0,
            $second->items,
        );
        $job->handle(app(AccountDeliveryService::class), app(CheckoutAuthority::class));

        $this->assertSame('paid_hold', $second->fresh()->state);
        $this->assertDatabaseCount('account_deliveries', 0);
    }

    #[Test]
    public function enforce_settlement_changed_to_hold_cannot_remain_fulfillment_authorized(): void
    {
        $checkout = $this->settledCheckout([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000, 'TX-MODE-HOLD');
        config(["commerce_safety.bots.{$this->bot->id}.mode" => 'hold']);

        (new ReserveAccountStock(
            $this->bot->id,
            $this->conversation->id,
            $checkout->settledEvent->slip_verification_id,
            50.0,
            $checkout->items,
        ))->handle(app(AccountDeliveryService::class), app(CheckoutAuthority::class));

        $this->assertSame('paid_hold', $checkout->fresh()->state);
        $this->assertDatabaseCount('account_deliveries', 0);

        config(["commerce_safety.bots.{$this->bot->id}.mode" => 'enforce']);
        $second = $this->settledCheckout([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000, 'TX-MODE-OFF', revision: 2);
        config(["commerce_safety.bots.{$this->bot->id}.mode" => 'off']);
        (new ReserveAccountStock(
            $this->bot->id,
            $this->conversation->id,
            $second->settledEvent->slip_verification_id,
            50.0,
            $second->items,
        ))->handle(app(AccountDeliveryService::class), app(CheckoutAuthority::class));

        $this->assertSame('paid_hold', $second->fresh()->state);
        $this->assertDatabaseCount('account_deliveries', 0);
    }

    #[Test]
    public function a_tampered_or_swapped_same_scope_order_cannot_authorize_reservation(): void
    {
        $checkout = $this->settledCheckout([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000, 'TX-TAMPERED-ORDER');
        $event = $checkout->settledEvent;
        DB::table('order_items')->where('order_id', $event->order_id)->update(['quantity' => 2]);

        $authorized = app(CheckoutAuthority::class)->authorizeReservation(
            $this->bot,
            $this->conversation,
            $event->slip_verification_id,
        );

        $this->assertNull($authorized);
        $this->assertSame('paid_hold', $checkout->fresh()->state);

        $second = $this->settledCheckout([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900, 'TX-SWAPPED-ORDER', revision: 2);
        $foreignSameScope = Order::factory()->create([
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'message_id' => $second->settledEvent->receipt_message_id,
            'total_amount' => '199.00',
            'status' => 'completed',
        ]);
        DB::table('verified_payment_events')->where('id', $second->settledEvent->id)
            ->update(['order_id' => $foreignSameScope->id]);

        $result = app(CheckoutAuthority::class)->settle($second->fresh(), $second->settledEvent->fresh());

        $this->assertSame('manual_hold', $result->action);
        $this->assertSame('paid_hold', $second->fresh()->state);
    }

    #[Test]
    public function partial_remote_shortage_preserves_reserved_units_and_one_delivery_identity(): void
    {
        $checkout = $this->settledCheckout([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 2, 'price_minor' => 5000],
        ], 10000, 'TX-SHORTAGE');
        $this->seedAvailable(700, 'G3D');
        $slipId = $checkout->settledEvent->slip_verification_id;

        $first = app(AccountDeliveryService::class)->createFromPayment(
            $this->bot, $this->conversation, $slipId, 100.0, $checkout->items,
        );
        $second = app(AccountDeliveryService::class)->createFromPayment(
            $this->bot, $this->conversation, $slipId, 100.0, $checkout->items,
        );

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, $first->items()->where('status', 'reserved')->count());
        $this->assertSame(1, $first->items()->where('status', 'shortage')->count());
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_reserved')->count());
        $this->assertDatabaseCount('account_deliveries', 1);
    }

    #[Test]
    public function remote_timeout_after_a_partial_reservation_remains_anchored_for_safe_retry(): void
    {
        $checkout = $this->settledCheckout([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 2, 'price_minor' => 5000],
        ], 10000, 'TX-TIMEOUT');
        $this->seedAvailable(901, 'G3D');
        $realPool = app(StockPoolService::class);
        $pool = Mockery::mock(StockPoolService::class);
        $pool->shouldReceive('reserveOne')->once()->with('G3D', Mockery::type('string'))
            ->andReturnUsing(fn (string $code, string $reference) => $realPool->reserveOne($code, $reference));
        $pool->shouldReceive('reserveOne')->once()->with('G3D', Mockery::type('string'))
            ->andThrow(new \RuntimeException('timeout'));
        $service = new AccountDeliveryService(
            $pool,
            app(ProductMapper::class),
            Mockery::mock(TelegramAlertBotService::class),
            Mockery::mock(LINEService::class),
        );

        $delivery = $service->createFromPayment(
            $this->bot,
            $this->conversation,
            $checkout->settledEvent->slip_verification_id,
            100.0,
            $checkout->items,
        );

        $this->assertSame(1, $delivery->items()->where('status', 'reserved')->count());
        $this->assertSame(1, $delivery->items()->where('status', 'reserving')->count());
        $this->assertSame(AccountDelivery::STATUS_RESERVING, $delivery->status);
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_reserved')->count());
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_available')->count());
    }

    #[Test]
    public function remote_commit_then_lost_response_is_recovered_by_the_exact_unit_key(): void
    {
        $checkout = $this->settledCheckout([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000, 'TX-AMBIGUOUS-COMMIT');
        $this->seedAvailable(902, 'G3D');
        $realPool = app(StockPoolService::class);
        $pool = new class($realPool) extends StockPoolService
        {
            private int $reserveAttempts = 0;

            private int $recoveryAttempts = 0;

            public function __construct(private readonly StockPoolService $real) {}

            public function reserveOne(string $stockCode, string $orderRef): ?array
            {
                $this->reserveAttempts++;
                $row = $this->real->reserveOne($stockCode, $orderRef);
                if ($this->reserveAttempts === 1) {
                    throw new \RuntimeException('response lost after remote commit');
                }

                return $row;
            }

            public function reservedByOrderRef(string $orderRef): ?array
            {
                $this->recoveryAttempts++;
                if ($this->recoveryAttempts === 1) {
                    throw new \RuntimeException('remote still unavailable');
                }

                return $this->real->reservedByOrderRef($orderRef);
            }
        };
        $service = new AccountDeliveryService(
            $pool,
            app(ProductMapper::class),
            Mockery::mock(TelegramAlertBotService::class),
            Mockery::mock(LINEService::class),
        );

        $first = $service->createFromPayment(
            $this->bot,
            $this->conversation,
            $checkout->settledEvent->slip_verification_id,
            50.0,
            $checkout->items,
        );
        $this->assertSame(AccountDelivery::STATUS_RESERVING, $first->status);

        $delivery = $service->createFromPayment(
            $this->bot,
            $this->conversation,
            $checkout->settledEvent->slip_verification_id,
            50.0,
            $checkout->items,
        );

        $this->assertSame($first->id, $delivery->id);
        $item = $delivery->items()->firstOrFail();
        $this->assertSame('reserved', $item->status);
        $this->assertSame(902, $item->stock_item_id);
        $this->assertSame("bfb:{$delivery->id}:{$item->id}", DB::connection('mhha_acc')
            ->table('items_reserved')->value('order_ref'));
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_reserved')->count());
    }

    #[Test]
    public function postgresql_workers_serialize_settlement_and_catalog_mutations(): void
    {
        if (DB::getDriverName() !== 'pgsql' || env('COMMERCE_SAFETY_PG_RACE') !== '1') {
            $this->markTestSkipped('Requires an explicitly opted-in disposable PostgreSQL test database.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires pcntl_fork for the PostgreSQL race.');
        }

        $checkout = $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);
        $automatic = $this->automaticEvent('199.00', 'TX-PG-RACE');
        $manual = $this->manualEvent('199.00', $this->owner->id, $checkout);
        $directory = sys_get_temp_dir().'/checkout-settlement-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];

        DB::commit();
        DB::disconnect();

        foreach ([$automatic->id, $manual->id] as $index => $eventId) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                file_put_contents("{$directory}/ready-{$index}", 'ready');
                $deadline = microtime(true) + 10;
                while (! file_exists("{$directory}/go") && microtime(true) < $deadline) {
                    usleep(1000);
                }
                try {
                    $outcome = app(CheckoutAuthority::class)->settle(
                        CheckoutSession::findOrFail($checkout->id),
                        VerifiedPaymentEvent::findOrFail($eventId),
                    );
                    file_put_contents("{$directory}/result-{$index}", $outcome->action);
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents("{$directory}/result-{$index}", $exception::class.': '.$exception->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }

        $deadline = microtime(true) + 10;
        while ((! file_exists("{$directory}/ready-0") || ! file_exists("{$directory}/ready-1"))
            && microtime(true) < $deadline) {
            usleep(1000);
        }
        file_put_contents("{$directory}/go", 'go');
        $statuses = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $statuses[] = pcntl_wexitstatus($status);
        }

        DB::purge();
        DB::reconnect();
        $results = [
            (string) file_get_contents("{$directory}/result-0"),
            (string) file_get_contents("{$directory}/result-1"),
        ];
        foreach (glob("{$directory}/*") as $file) {
            unlink($file);
        }
        rmdir($directory);

        $this->assertSame([0, 0], $statuses, json_encode($results));
        $this->assertContains('settled', $results);
        $this->assertSame('paid', CheckoutSession::findOrFail($checkout->id)->state);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('order_items')->count());

        $this->assertConcurrentCatalogMutationPreventsOrder(
            ['price' => '200.00'],
            'TX-PG-PRICE-MUTATION',
        );
        ProductStock::query()->whereKey($this->products['page']->id)->update(['price' => '199.00']);
        $this->assertConcurrentCatalogMutationPreventsOrder(
            ['available_count' => 0, 'in_stock' => false],
            'TX-PG-STOCK-MUTATION',
        );
        ProductStock::query()->whereKey($this->products['page']->id)->update([
            'available_count' => null,
            'in_stock' => true,
        ]);
    }

    private function assertConcurrentCatalogMutationPreventsOrder(array $mutation, string $transRef): void
    {
        if (DB::getDriverName() !== 'pgsql' || env('COMMERCE_SAFETY_PG_RACE') !== '1') {
            $this->markTestSkipped('Requires an explicitly opted-in disposable PostgreSQL test database.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires pcntl_fork for the PostgreSQL race.');
        }
        $ordersBefore = Order::count();

        $checkout = $this->payable([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);
        $event = $this->automaticEvent('199.00', $transRef);
        $productId = $this->products['page']->id;
        $directory = sys_get_temp_dir().'/checkout-catalog-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        if (DB::transactionLevel() > 0) {
            DB::commit();
        }
        DB::disconnect();

        $mutator = pcntl_fork();
        if ($mutator === 0) {
            DB::purge();
            try {
                DB::beginTransaction();
                ProductStock::query()->whereKey($productId)->update($mutation);
                file_put_contents("{$directory}/mutation-ready", 'ready');
                $deadline = microtime(true) + 10;
                while (! file_exists("{$directory}/commit") && microtime(true) < $deadline) {
                    usleep(1000);
                }
                DB::commit();
                file_put_contents("{$directory}/mutation-result", 'committed');
                exit(0);
            } catch (\Throwable $exception) {
                DB::rollBack();
                file_put_contents("{$directory}/mutation-result", $exception::class.': '.$exception->getMessage());
                exit(1);
            }
        }

        $settler = pcntl_fork();
        if ($settler === 0) {
            DB::purge();
            $deadline = microtime(true) + 10;
            while (! file_exists("{$directory}/mutation-ready") && microtime(true) < $deadline) {
                usleep(1000);
            }
            file_put_contents("{$directory}/settlement-started", 'started');
            try {
                $outcome = app(CheckoutAuthority::class)->settle(
                    CheckoutSession::findOrFail($checkout->id),
                    VerifiedPaymentEvent::findOrFail($event->id),
                );
                file_put_contents("{$directory}/settlement-result", $outcome->action);
                exit(0);
            } catch (\Throwable $exception) {
                file_put_contents("{$directory}/settlement-result", $exception::class.': '.$exception->getMessage());
                exit(1);
            }
        }

        $deadline = microtime(true) + 10;
        while (! file_exists("{$directory}/settlement-started") && microtime(true) < $deadline) {
            usleep(1000);
        }
        usleep(200000);
        file_put_contents("{$directory}/commit", 'commit');
        pcntl_waitpid($mutator, $mutatorStatus);
        pcntl_waitpid($settler, $settlerStatus);

        DB::purge();
        DB::reconnect();
        $mutationResult = (string) file_get_contents("{$directory}/mutation-result");
        $settlementResult = (string) file_get_contents("{$directory}/settlement-result");
        foreach (glob("{$directory}/*") as $file) {
            unlink($file);
        }
        rmdir($directory);

        $this->assertSame(0, pcntl_wexitstatus($mutatorStatus), $mutationResult);
        $this->assertSame(0, pcntl_wexitstatus($settlerStatus), $settlementResult);
        $this->assertSame('committed', $mutationResult);
        $this->assertSame('manual_hold', $settlementResult);
        $this->assertSame('paid_hold', CheckoutSession::findOrFail($checkout->id)->state);
        $this->assertSame($ordersBefore, Order::count());
    }

    private function settledCheckout(array $lines, int $totalMinor, string $transRef, int $revision = 1): CheckoutSession
    {
        $checkout = $this->payable($lines, $totalMinor, revision: $revision);
        $outcome = app(CheckoutAuthority::class)->settle(
            $checkout,
            $this->automaticEvent(number_format($totalMinor / 100, 2, '.', ''), $transRef),
        );
        $this->assertSame('settled', $outcome->action);

        return $checkout->fresh()->load('settledEvent');
    }

    private function payable(array $lines, int $totalMinor, ?Conversation $conversation = null, int $revision = 1): CheckoutSession
    {
        $conversation ??= $this->conversation;
        $cart = app(CanonicalCartValidator::class)->validate($this->bot, $conversation, $lines, $totalMinor);
        $this->assertTrue($cart->valid, implode(', ', $cart->errors));
        $requirements = app(CheckoutConsentPolicy::class)->requirements($this->bot, $conversation, $cart->lines);
        $stages = ['confirm'];
        foreach (['topup_ack', 'support_delay', 'terms'] as $stage) {
            if ($requirements[$stage]) {
                $stages[] = $stage;
            }
        }
        $accepted = [];
        foreach (array_values(array_unique($stages)) as $stage) {
            $message = $conversation->messages()->create([
                'sender' => 'user', 'type' => 'text', 'content' => "accept {$stage}",
            ]);
            $accepted[$stage] = $message->id;
        }

        $checkout = new CheckoutSession;
        $checkout->forceFill([
            'bot_id' => $this->bot->id,
            'conversation_id' => $conversation->id,
            'revision' => $revision,
            'state' => 'payable',
            'items' => $cart->lines,
            'total_minor' => $cart->totalMinor,
            'currency' => 'THB',
            'fingerprint' => $cart->fingerprint,
            'requirements' => $requirements,
            'accepted' => $accepted,
            'settled_event_id' => null,
        ])->save();
        foreach ($accepted as $stage => $messageId) {
            DB::table('checkout_consent_acceptances')->insert([
                'checkout_id' => $checkout->id,
                'revision' => $revision,
                'stage' => $stage,
                'message_id' => $messageId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $checkout;
    }

    private function automaticEvent(string $amount, string $transRef): VerifiedPaymentEvent
    {
        $receipt = $this->conversation->messages()->create([
            'sender' => 'bot', 'type' => 'text', 'content' => "เงินเข้าแล้ว {$amount} บาท",
        ]);
        $slip = SlipVerification::create([
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'message_id' => null,
            'trans_ref' => $transRef,
            'amount' => $amount,
            'status' => 'passed',
        ]);

        return app(PaymentProofService::class)->record(
            $this->bot, $this->conversation, $slip, $receipt, null,
        );
    }

    private function manualEvent(string $amount, int $actorId, ?CheckoutSession $checkout = null): VerifiedPaymentEvent
    {
        $receipt = $this->conversation->messages()->create([
            'sender' => 'bot', 'type' => 'text', 'content' => "manual {$amount}",
        ]);
        $slip = SlipVerification::create([
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'message_id' => $receipt->id,
            'trans_ref' => null,
            'amount' => $amount,
            'status' => 'manual_confirmed',
        ]);

        return app(PaymentProofService::class)->record(
            $this->bot, $this->conversation, $slip, $receipt, $actorId,
        );
    }

    private function product(array $attributes): ProductStock
    {
        return ProductStock::create(array_merge([
            'in_stock' => true,
            'manual_off' => false,
            'display_order' => count($this->products ?? []) + 1,
        ], $attributes));
    }
}
