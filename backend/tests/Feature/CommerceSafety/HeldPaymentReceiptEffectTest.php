<?php

namespace Tests\Feature\CommerceSafety;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Jobs\ReserveAccountStock;
use App\Jobs\RunPaymentEffect;
use App\Models\Bot;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\Order;
use App\Models\PaymentEffect;
use App\Models\ProductStock;
use App\Models\SlipVerification;
use App\Models\User;
use App\Models\VerifiedPaymentEvent;
use App\Services\CommerceSafety\CanonicalCartValidator;
use App\Services\CommerceSafety\CheckoutAuthority;
use App\Services\CommerceSafety\CheckoutConsentPolicy;
use App\Services\CommerceSafety\PaymentEffectDispatcher;
use App\Services\CommerceSafety\PaymentProofService;
use App\Services\LINEService;
use App\Services\LineWebhook\LineWebhookOutputService;
use App\Services\LineWebhook\ResponseEnvelope;
use App\Services\LineWebhook\WebhookContext;
use App\Services\Payment\ManualPaymentConfirmService;
use App\Services\Payment\OrderReconstructor;
use App\Services\Payment\SlipRetryService;
use App\Services\Payment\SlipVerificationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class HeldPaymentReceiptEffectTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithStockPool;

    // Baseline contains intentionally irreversible down() migrations. Fresh isolated test DB only.
    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = false;
    }

    private User $owner;

    private Bot $bot;

    private Conversation $conversation;

    /** @var array<string, ProductStock> */
    private array $products;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
        $this->setUpStockPool();

        $this->owner = User::factory()->owner()->create();
        $this->bot = Bot::factory()->active()->create([
            'user_id' => $this->owner->id,
            'auto_delivery_enabled' => true, 'channel_access_token' => 'fixture',
        ]);
        $this->conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => [], 'channel_type' => 'line', 'external_customer_id' => 'fixture-user',
        ]);
        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);
        $plugin = FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'Settlement fixture',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'fixture', 'chat_id' => 'finance', 'message_template' => '{amount} {product} {source_bank}'],
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

    #[DataProvider('heldCases')]
    public function test_received_money_persists_one_reconcilable_receipt_without_direct_output(string $scenario): void
    {
        $checkout = $scenario === 'no_checkout' ? null : $this->payable([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000);
        if ($scenario === 'hold_mode') {
            config(["commerce_safety.bots.{$this->bot->id}.mode" => 'hold']);
        }
        $this->fakeReceivedTransfer('HELD-RECEIPT', $scenario === 'wrong_amount' ? '40.00' : '50.00');
        $line = $this->mock(LINEService::class);
        $line->shouldNotReceive('replyWithFallback');
        $image = $this->imageMessage();
        $result = app(SlipVerificationService::class)->verify(
            $this->bot, $this->conversation, $image, 'https://invalid.test/slip', [],
        );
        $this->assertTrue($result->passed);
        $event = VerifiedPaymentEvent::sole();
        $this->assertSame('manual_hold', $event->disposition);
        $this->assertSame('passed', $event->slipVerification->status);
        $this->assertSame($scenario === 'wrong_amount' ? 4000 : 5000, $event->amount_minor);
        if ($checkout) {
            $this->assertSame('paid_hold', $checkout->fresh()->state);
        } else {
            $this->assertNull($event->checkout_id);
        }
        $effect = $this->assertHeldEffect($event);
        $this->dispatchOutput($event);
        $this->assertSame(0, $effect->fresh()->attempt_count);
        $this->reconcileAndDispatch($event, $effect, $line);
    }

    public static function heldCases(): array
    {
        return [['no_checkout'], ['wrong_amount'], ['hold_mode']];
    }

    public function test_duplicate_provider_and_retry_keep_original_terminal_hold_receipt_and_retry_key(): void
    {
        $this->fakeReceivedTransfer('HELD-DUPLICATE', '50.00');
        $line = $this->mock(LINEService::class);
        $line->shouldNotReceive('replyWithFallback');
        $image = $this->imageMessage();
        $service = app(SlipVerificationService::class);
        $first = $service->verify($this->bot, $this->conversation, $image, 'https://invalid.test/slip', []);
        $event = VerifiedPaymentEvent::sole();
        $effect = $this->assertHeldEffect($event);
        $this->payable([['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000]], 5000);
        $duplicate = $service->verify($this->bot, $this->conversation, $this->imageMessage(), 'https://invalid.test/slip', []);
        foreach ([1, 2] as $attempt) {
            app(SlipRetryService::class)->retry($this->bot, $this->conversation, $image, 'https://invalid.test/slip', $attempt);
        }
        $this->assertSame($first->slipVerificationId, $duplicate->slipVerificationId);
        $this->assertSame($event->id, VerifiedPaymentEvent::sole()->id);
        $this->assertSame('manual_hold', $event->fresh()->disposition);
        $this->assertSame($effect->id, $this->assertHeldEffect($event)->id);
        $this->assertSame($effect->retry_key, $effect->fresh()->retry_key);
        $this->assertSame(1, SlipVerification::count());
        $this->assertSame(1, $this->conversation->messages()->where('sender', 'bot')->count());
        $this->reconcileAndDispatch($event, $effect, $line);
    }

    public function test_manual_confirmation_and_retry_share_one_held_receipt_effect(): void
    {
        $line = $this->mock(LINEService::class);
        $line->shouldNotReceive('replyWithFallback');
        $image = $this->imageMessage();
        $result = app(ManualPaymentConfirmService::class)->confirm($this->bot, $this->conversation, '50.00', $this->owner->id);
        $event = VerifiedPaymentEvent::sole();
        $this->assertFalse($result['order_created']);
        $this->assertSame('manual_hold', $event->disposition);
        $this->assertSame('manual', $event->source);
        $this->assertSame($this->owner->id, $event->actor_id);
        $effect = $this->assertHeldEffect($event);
        app(PaymentProofService::class)->record($this->bot, $this->conversation, $event->slipVerification, $event->receiptMessage, $this->owner->id);
        app(SlipRetryService::class)->retry($this->bot, $this->conversation, $image, 'https://invalid.test/slip', 1);
        $this->assertSame($effect->id, $this->assertHeldEffect($event)->id);
        $this->assertSame(1, VerifiedPaymentEvent::count());
        $this->assertSame(1, SlipVerification::count());
        Http::assertNothingSent();
        $this->reconcileAndDispatch($event, $effect, $line);
    }

    public function test_recovered_manual_proof_without_actor_can_acknowledge_money_but_cannot_fulfill(): void
    {
        $line = $this->mock(LINEService::class);
        $line->shouldNotReceive('replyWithFallback');
        $image = $this->imageMessage();
        SlipVerification::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id,
            'message_id' => null, 'amount' => '50.00', 'status' => 'manual_confirmed',
        ]);
        app(SlipRetryService::class)->retry($this->bot, $this->conversation, $image, 'https://invalid.test/slip', 1);
        $event = VerifiedPaymentEvent::sole();
        $this->assertNull($event->actor_id);
        $this->assertSame('manual_actor_unavailable', $event->hold_reason);
        $this->reconcileAndDispatch($event, $this->assertHeldEffect($event), $line);
    }

    public function test_proof_and_effect_commit_atomically_before_any_later_output(): void
    {
        DB::beginTransaction();
        $event = $this->automaticEvent('50.00', 'ATOMIC');
        $this->assertHeldEffect($event);
        Queue::assertNothingPushed();
        DB::rollBack();
        $this->assertSame(0, VerifiedPaymentEvent::count());
        $this->assertSame(0, PaymentEffect::count());
        $this->assertSame(0, SlipVerification::count());
        $this->assertSame(0, Message::count());
        Queue::assertNothingPushed();

        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('queue unavailable'));
        $event = $this->automaticEvent('50.00', 'COMMITTED');
        Queue::swap(new QueueFake(app()));
        $effect = $this->assertHeldEffect($event);
        $this->reconcileAndDispatch($event, $effect, $this->mock(LINEService::class));
    }

    public function test_settlement_adds_other_effects_without_replacing_proof_receipt_row(): void
    {
        $checkout = $this->payable([['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000]], 5000);
        $event = $this->automaticEvent('50.00', 'SETTLED-REUSE');
        $effect = $this->assertHeldEffect($event);
        $this->assertSame('settled', app(CheckoutAuthority::class)->settle($checkout, $event)->action);
        app(PaymentEffectDispatcher::class)->enqueue($event);
        $this->assertSame($effect->id, PaymentEffect::where('kind', 'line_receipt')->sole()->id);
        $this->assertSame($effect->retry_key, $effect->fresh()->retry_key);
        $this->assertSame(3, PaymentEffect::count());
        $this->assertSame(1, Order::count());
        Queue::assertPushed(RunPaymentEffect::class, 3);
    }

    public function test_effect_insert_failure_rolls_back_the_payment_event(): void
    {
        $attempted = false;
        DB::listen(function ($query) use (&$attempted): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'payment_effects')) {
                $attempted = true;
                throw new \RuntimeException('effect insert interrupted');
            }
        });
        try {
            $this->automaticEvent('50.00', 'INSERT-FAILURE');
            $this->fail('Proof cannot commit without its receipt effect.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('effect insert interrupted', $exception->getMessage());
        }
        $this->assertTrue($attempted);
        $this->assertSame(0, VerifiedPaymentEvent::count());
        $this->assertSame(0, PaymentEffect::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_failed_settlement_preserves_the_committed_proof_receipt_for_reconciliation(): void
    {
        $checkout = $this->payable([['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000]], 5000);
        $event = $this->automaticEvent('50.00', 'SETTLEMENT-FAILURE');
        $effect = $this->assertHeldEffect($event);
        Event::listen('eloquent.created: '.Order::class, function (): void {
            throw new \RuntimeException('settlement interrupted');
        });
        try {
            app(CheckoutAuthority::class)->settle($checkout, $event);
            $this->fail('Settlement should roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('settlement interrupted', $exception->getMessage());
        }
        $this->assertSame($effect->id, $this->assertHeldEffect($event)->id);
        $this->reconcileAndDispatch($event, $effect, $this->mock(LINEService::class));
    }

    public function test_retry_repairs_a_legacy_terminal_proof_missing_its_receipt_effect(): void
    {
        $image = $this->imageMessage();
        $event = $this->automaticEvent('50.00', 'LEGACY-HELD');
        $event->slipVerification->update(['message_id' => $image->id]);
        PaymentEffect::query()->delete();
        app(SlipRetryService::class)->retry($this->bot, $this->conversation, $image, 'https://invalid.test/slip', 1);
        $this->assertSame($event->id, VerifiedPaymentEvent::sole()->id);
        $this->reconcileAndDispatch($event, $this->assertHeldEffect($event), $this->mock(LINEService::class));
    }

    #[DataProvider('invalidProofMutations')]
    public function test_receipt_dispatch_rechecks_persisted_proof_authority(string $mutation): void
    {
        $event = $this->automaticEvent('50.00', 'INVALIDATED');
        $effect = $this->assertHeldEffect($event);
        match ($mutation) {
            'amount' => $event->slipVerification->update(['amount' => '99.00']),
            'status' => $event->slipVerification->update(['status' => 'failed']),
            'reference' => $event->slipVerification->update(['trans_ref' => 'OTHER']),
            'receipt_sender' => $event->receiptMessage->update(['sender' => 'user']),
        };
        $this->mock(LINEService::class)->shouldNotReceive('pushPaymentReceipt');
        app(PaymentEffectDispatcher::class)->run($effect->id);
        $this->assertSame('failed', $effect->fresh()->state);
        $this->assertSame('authority_invalid', $effect->fresh()->last_error_code);
        $this->assertSame(0, Order::count());
        Http::assertNothingSent();
    }

    public static function invalidProofMutations(): array
    {
        return [['amount'], ['status'], ['reference'], ['receipt_sender']];
    }

    private function imageMessage(): Message
    {
        return $this->conversation->messages()->create(['sender' => 'user', 'type' => 'image', 'content' => '[image]']);
    }

    private function assertHeldEffect(VerifiedPaymentEvent $event): PaymentEffect
    {
        $this->assertNotNull($event->fresh()->receiptMessage);
        $this->assertNotNull($event->fresh()->slipVerification);
        $this->assertSame(1, PaymentEffect::where('event_id', $event->id)->where('kind', 'line_receipt')->count());
        $this->assertSame(0, PaymentEffect::whereIn('kind', ['telegram_payment', 'reserve_stock'])->count());
        $this->assertSame(0, Order::count());
        Queue::assertNotPushed(ReserveAccountStock::class);
        $effect = PaymentEffect::where('event_id', $event->id)->sole();
        $this->assertSame('pending', $effect->state);
        $this->assertNotEmpty($effect->retry_key);

        return $effect;
    }

    private function dispatchOutput(VerifiedPaymentEvent $event): void
    {
        Event::fake([MessageSent::class, ConversationUpdated::class]);
        $ctx = new WebhookContext($this->bot, ['message' => ['type' => 'image'], 'source' => ['userId' => 'fixture-user']]);
        $ctx->conversation = $this->conversation;
        $ctx->metadata['bot_message'] = $event->receiptMessage;
        $ctx->response = ResponseEnvelope::text($event->receiptMessage->content);
        app(LineWebhookOutputService::class)->dispatch($ctx);
    }

    private function reconcileAndDispatch(VerifiedPaymentEvent $event, PaymentEffect $effect, $line): void
    {
        // Generated receipt prose cannot authorize fulfillment or change the verified amount.
        $event->receiptMessage->update(['content' => 'FORGED 999 ส่งใน 5-10 นาที']);
        $line->shouldReceive('pushPaymentReceipt')->once()->withArgs(function ($bot, $destination, $flex, $retryKey) use ($event, $effect): bool {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($this->bot->id, $bot->id);
            $this->assertSame('fixture-user', $destination);
            $this->assertSame($effect->retry_key, $retryKey);
            $this->assertStringContainsString('เงินเข้าแล้ว '.($event->amount_minor / 100), $flex['altText']);
            $this->assertStringContainsString('ทีมงานตรวจสอบรายการ', $flex['altText']);
            $this->assertStringNotContainsString('5-10', json_encode($flex, JSON_UNESCAPED_UNICODE));
            $this->assertStringNotContainsString('FORGED', json_encode($flex));

            return true;
        })->andReturn('line-request');
        Queue::fake();
        $this->artisan('payment-effects:reconcile')->assertSuccessful();
        Queue::assertPushed(RunPaymentEffect::class, fn ($job) => $job->effectId === $effect->id);
        (new RunPaymentEffect($effect->id))->handle(app(PaymentEffectDispatcher::class));
        (new RunPaymentEffect($effect->id))->handle(app(PaymentEffectDispatcher::class));
        $this->assertSame('succeeded', $effect->fresh()->state);
        $this->assertSame(1, $effect->fresh()->attempt_count);
        $this->assertStringContainsString('ทีมงานตรวจสอบรายการ', $event->receiptMessage->fresh()->content);
        $this->assertSame(1, PaymentEffect::count());
        $this->assertSame(0, Order::count());
    }

    private function fakeReceivedTransfer(string $reference, string $amount): void
    {
        $this->owner->getOrCreateSettings()->update(['easyslip_api_token' => 'local-test-token']);
        $this->bot->settings()->updateOrCreate(['bot_id' => $this->bot->id], ['slip_verification_enabled' => true, 'slip_receiver_account' => '223-3-24880-3']);
        $this->bot->unsetRelation('settings')->unsetRelation('user');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.easyslip.com/*' => Http::response(['data' => [
            'amountInSlip' => $amount,
            'rawSlip' => ['transRef' => $reference, 'receiver' => ['account' => ['bank' => ['account' => '2233248803']]]],
        ]])]);
        $this->mock(OrderReconstructor::class)->shouldNotReceive('reconstruct');
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

    private function product(array $attributes): ProductStock
    {
        return ProductStock::create(array_merge([
            'in_stock' => true,
            'manual_off' => false,
            'display_order' => count($this->products ?? []) + 1,
        ], $attributes));
    }
}
