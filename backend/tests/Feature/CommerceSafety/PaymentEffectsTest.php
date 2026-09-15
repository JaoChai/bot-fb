<?php

namespace Tests\Feature\CommerceSafety;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
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
use App\Services\CommerceSafety\CanonicalCartValidator;
use App\Services\CommerceSafety\CheckoutAuthority;
use App\Services\CommerceSafety\CheckoutConsentPolicy;
use App\Services\CommerceSafety\PaymentEffectDispatcher;
use App\Services\CommerceSafety\PaymentProofService;
use App\Services\Delivery\AccountDeliveryService;
use App\Services\LineWebhook\LineWebhookOutputService;
use App\Services\LineWebhook\ResponseEnvelope;
use App\Services\LineWebhook\WebhookContext;
use App\Services\OpenRouterService;
use App\Services\Payment\ManualPaymentConfirmService;
use App\Services\Payment\SlipRetryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class PaymentEffectsTest extends TestCase
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

    private function settled(): VerifiedPaymentEvent
    {
        $checkout = $this->payable([['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000]], 5000);
        $event = $this->automaticEvent('50.00', 'EFFECT-TEST');
        $this->assertSame('settled', app(CheckoutAuthority::class)->settle($checkout, $event)->action);

        return $event->fresh();
    }

    private function effect(string $kind): PaymentEffect
    {
        return PaymentEffect::where('kind', $kind)->sole();
    }

    public function test_atomic_enqueue_twice_and_repeat_after_cache_window(): void
    {
        $event = $this->settled();
        app(PaymentEffectDispatcher::class)->enqueue($event);
        $this->travel(2)->hours();
        app(CheckoutAuthority::class)->settleEvent($event);
        $this->assertSame(3, PaymentEffect::count());
        $this->assertSame(1, Order::count());
        $this->assertSame(1, PaymentEffect::where('kind', 'reserve_stock')->count());
        Queue::assertPushed(RunPaymentEffect::class, 3);
    }

    public function test_rollback_and_outer_commit_queue_boundary(): void
    {
        DB::beginTransaction();
        $this->settled();
        $this->assertSame(3, PaymentEffect::count());
        Queue::assertNothingPushed();
        DB::rollBack();
        $this->assertSame(0, Order::count());
        $this->assertSame(0, PaymentEffect::count());
        Queue::assertNothingPushed();
        DB::beginTransaction();
        $this->settled();
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(RunPaymentEffect::class, 3);
    }

    public function test_enqueue_failure_leaves_pending_reconcilable_rows(): void
    {
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('queue down'));
        $this->settled();
        $this->assertSame(3, PaymentEffect::where('state', 'pending')->count());
        Queue::swap(new QueueFake(app()));
        $this->artisan('payment-effects:reconcile')->assertSuccessful();
        Queue::assertPushed(RunPaymentEffect::class, 3);
    }

    public function test_pre_b3_unsettled_held_orderless_checkoutless_and_mismatched_events_create_zero_effects(): void
    {
        $event = $this->automaticEvent('50.00', 'LEGACY');
        app(PaymentEffectDispatcher::class)->enqueue($event);
        $this->assertSame(0, PaymentEffect::count());
        $checkout = $this->payable([['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000]], 5000);
        DB::table('verified_payment_events')->where('id', $event->id)->update(['checkout_id' => $checkout->id]);
        foreach ([null, 'manual_hold', 'settled'] as $disposition) {
            DB::table('verified_payment_events')->where('id', $event->id)->update(['disposition' => $disposition]);
            app(PaymentEffectDispatcher::class)->enqueue($event->fresh());
            $this->assertSame(0, PaymentEffect::count());
        }
        Queue::assertNothingPushed();
    }

    public function test_line_timeout_reuses_key_and_accepted_duplicate_is_success(): void
    {
        $this->settled();
        $effect = $this->effect('line_receipt');
        $keys = [];
        Http::fake(function ($request) use (&$keys) {
            $this->assertSame(0, DB::transactionLevel());
            $keys[] = $request->header('X-Line-Retry-Key')[0];
            if (count($keys) === 1) {
                throw new ConnectionException('response lost');
            }

            return Http::response([], 409, ['x-line-accepted-request-id' => 'accepted']);
        });
        app(PaymentEffectDispatcher::class)->run($effect->id);
        $this->assertSame('failed', $effect->fresh()->state);
        $this->travel(2)->minutes();
        app(PaymentEffectDispatcher::class)->run($effect->id);
        $this->assertSame('succeeded', $effect->fresh()->state);
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame($effect->fresh()->retry_key, $keys[0]);
    }

    public function test_telegram_ambiguous_timeout_is_never_automatically_resent(): void
    {
        $this->settled();
        Http::fake(fn () => throw new ConnectionException('response lost'));
        $effect = $this->effect('telegram_payment');
        app(PaymentEffectDispatcher::class)->run($effect->id);
        $this->assertSame('uncertain', $effect->fresh()->state);
        $this->travel(1)->days();
        Queue::fake();
        $this->artisan('payment-effects:reconcile')->expectsOutputToContain('uncertain=1')->assertSuccessful();
        app(PaymentEffectDispatcher::class)->run($effect->id);
        $this->assertSame(1, $effect->fresh()->attempt_count);
        Queue::assertNotPushed(RunPaymentEffect::class, fn ($job) => $job->effectId === $effect->id);
    }

    public function test_definite_failure_has_bounded_five_attempts_and_backoff(): void
    {
        $this->settled();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 400)]);
        $effect = $this->effect('telegram_payment');
        for ($i = 1; $i <= 5; $i++) {
            app(PaymentEffectDispatcher::class)->run($effect->id);
            $this->assertSame($i, $effect->fresh()->attempt_count);
            app(PaymentEffectDispatcher::class)->run($effect->id);
            $this->assertSame($i, $effect->fresh()->attempt_count);
            $this->travel(1)->hours();
        }
        app(PaymentEffectDispatcher::class)->run($effect->id);
        Http::assertSentCount(5);
        $this->assertSame('failed', $effect->fresh()->state);
    }

    public function test_claim_crash_before_transport_stale_lease_and_fencing(): void
    {
        $this->settled();
        $effect = $this->effect('telegram_payment');
        $dispatcher = app(PaymentEffectDispatcher::class);
        $claim = $dispatcher->claim($effect->id);
        $this->assertNotNull($claim);
        $this->assertNull($dispatcher->claim($effect->id));
        $this->travel(6)->minutes();
        $new = $dispatcher->claim($effect->id);
        $this->assertNotSame($claim->claim_token, $new->claim_token);
        $this->assertFalse($dispatcher->beginTransport($claim));
        $this->assertFalse($dispatcher->finish($claim, 'succeeded'));
        $this->assertTrue($dispatcher->finish($new, 'failed', 'before_transport'));
        $this->travel(1)->hours();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 12]])]);
        $dispatcher->run($effect->id);
        $this->assertSame('succeeded', $effect->fresh()->state);
    }

    public function test_process_loss_after_telegram_transport_marker_becomes_uncertain(): void
    {
        $this->settled();
        $dispatcher = app(PaymentEffectDispatcher::class);
        $claim = $dispatcher->claim($this->effect('telegram_payment')->id);
        $this->assertTrue($dispatcher->beginTransport($claim));
        $this->travel(6)->minutes();
        $this->artisan('payment-effects:reconcile')->assertSuccessful();
        $this->assertSame('uncertain', $claim->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_telegram_freezes_plugin_and_uses_database_template_without_llm(): void
    {
        $event = $this->settled();
        $event->receiptMessage->update(['content' => 'FORGED 999 product']);
        $this->mock(OpenRouterService::class)->shouldNotReceive('chat');
        Http::fake(function ($request) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('finance', $request['chat_id']);
            $this->assertStringContainsString('50', $request['text']);
            $this->assertStringContainsString('G3D', $request['text']);
            $this->assertStringNotContainsString('FORGED', $request['text']);

            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });
        app(PaymentEffectDispatcher::class)->run($this->effect('telegram_payment')->id);
        $this->assertSame('succeeded', $this->effect('telegram_payment')->state);
        Http::assertSentCount(1);
    }

    public function test_plugin_and_authority_mutations_fail_without_transport(): void
    {
        $event = $this->settled();
        config(["commerce_safety.bots.{$this->bot->id}.payment_plugin_ids" => []]);
        app(PaymentEffectDispatcher::class)->run($this->effect('telegram_payment')->id);
        $this->assertSame('failed', $this->effect('telegram_payment')->state);
        $event->order->update(['total_amount' => 1]);
        app(PaymentEffectDispatcher::class)->run($this->effect('reserve_stock')->id);
        $this->assertSame('failed', $this->effect('reserve_stock')->state);
        Http::assertNothingSent();
    }

    public function test_invalid_disabled_and_multiple_plugin_configuration_is_audited(): void
    {
        $plugin = FlowPlugin::first();
        $plugin->update(['enabled' => false]);
        $this->settled();
        $this->assertSame('failed', $this->effect('telegram_payment')->state);
        $this->assertNull($this->effect('telegram_payment')->plugin_id);
        Http::assertNothingSent();
    }

    public function test_auto_manual_same_checkout_stock_replay_and_card_failure_are_independent(): void
    {
        $event = $this->settled();
        $manual = $this->manualEvent('50.00', $this->owner->id, $event->checkout);
        app(CheckoutAuthority::class)->settleEvent($manual);
        $this->seedAvailable(1, 'G3D');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 400)]);
        app(PaymentEffectDispatcher::class)->run($this->effect('telegram_payment')->id);
        app(PaymentEffectDispatcher::class)->run($this->effect('reserve_stock')->id);
        $delivery = AccountDelivery::sole();
        $anchors = $delivery->items->pluck('anchor_key')->all();
        $refs = DB::connection('mhha_acc')->table('items_reserved')->pluck('order_ref')->all();
        app(PaymentEffectDispatcher::class)->run($this->effect('reserve_stock')->id);
        $legacy = unserialize(serialize(new ReserveAccountStock($this->bot->id, $this->conversation->id, $event->slip_verification_id, 999, [])));
        $legacy->handle(app(AccountDeliveryService::class), app(CheckoutAuthority::class));
        $this->assertSame(1, AccountDelivery::count());
        $this->assertSame($anchors, $delivery->fresh()->items->pluck('anchor_key')->all());
        $this->assertSame($refs, DB::connection('mhha_acc')->table('items_reserved')->pluck('order_ref')->all());
        $this->assertSame('succeeded', $this->effect('reserve_stock')->state);
        $this->assertSame(3, PaymentEffect::count());
        $this->assertSame(1, Order::count());
        Queue::assertPushed(SendDeliveryCard::class, 1);
    }

    public function test_stock_shortage_completes_independently_of_telegram_failure(): void
    {
        $this->settled();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 400)]);
        app(PaymentEffectDispatcher::class)->run($this->effect('telegram_payment')->id);
        app(PaymentEffectDispatcher::class)->run($this->effect('reserve_stock')->id);
        $this->assertSame('failed', $this->effect('telegram_payment')->state);
        $this->assertSame('succeeded', $this->effect('reserve_stock')->state);
        $this->assertDatabaseHas('account_delivery_items', ['status' => 'shortage']);
    }

    public function test_line_preserves_persisted_hold_copy_and_does_not_send_after_scope_is_disabled(): void
    {
        $event = $this->settled();
        DB::table('verified_payment_events')->where('id', $event->id)->update(['disposition' => 'manual_hold']);
        $event->checkout->forceFill(['state' => 'paid_hold'])->save();
        $event->receiptMessage->update(['content' => 'FORGED 999 ส่งใน 5-10 นาที']);
        Http::fake(function ($request) {
            $text = json_encode($request['messages'], JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString('50', $text);
            $this->assertStringContainsString('ทีมงาน', $text);
            $this->assertStringNotContainsString('5-10', $text);
            $this->assertStringNotContainsString('FORGED', $text);

            return Http::response([]);
        });
        config(["commerce_safety.bots.{$this->bot->id}.mode" => 'off']);
        app(PaymentEffectDispatcher::class)->run($this->effect('line_receipt')->id);
        $this->assertSame('failed', $this->effect('line_receipt')->state);
        Http::assertNothingSent();
        config(["commerce_safety.bots.{$this->bot->id}.mode" => 'hold']);
        $this->travel(2)->minutes();
        app(PaymentEffectDispatcher::class)->run($this->effect('line_receipt')->id);
        $this->assertSame('succeeded', $this->effect('line_receipt')->state);
        Http::assertSentCount(1);
    }

    public function test_delivery_card_failure_does_not_replay_successful_stock_effect(): void
    {
        $this->settled();
        $this->seedAvailable(1, 'G3D');
        app(PaymentEffectDispatcher::class)->run($this->effect('reserve_stock')->id);
        $delivery = AccountDelivery::sole();
        $before = $delivery->items->toArray();
        $service = Mockery::mock(AccountDeliveryService::class);
        $service->shouldReceive('sendCard')->once()->andReturn(false);
        try {
            (new SendDeliveryCard($delivery->id))->handle($service);
            $this->fail('Card failure must be owned by SendDeliveryCard.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('delivery card send failed', $e->getMessage());
        }
        app(PaymentEffectDispatcher::class)->run($this->effect('reserve_stock')->id);
        $this->assertSame('succeeded', $this->effect('reserve_stock')->state);
        $this->assertSame(1, $this->effect('reserve_stock')->attempt_count);
        $this->assertSame($before, $delivery->fresh()->items->toArray());
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_reserved')->count());
    }

    public function test_a_synchronous_run_inside_transaction_leaves_durable_pending_work(): void
    {
        $this->settled();
        DB::transaction(fn () => app(PaymentEffectDispatcher::class)->run($this->effect('line_receipt')->id));
        $this->assertSame('pending', $this->effect('line_receipt')->state);
        $this->assertSame(0, $this->effect('line_receipt')->attempt_count);
        Http::assertNothingSent();
    }

    public function test_stale_line_and_pending_failed_uncertain_reconciliation_policy(): void
    {
        $this->settled();
        $dispatcher = app(PaymentEffectDispatcher::class);
        $line = $dispatcher->claim($this->effect('line_receipt')->id);
        $dispatcher->beginTransport($line);
        $telegram = $dispatcher->claim($this->effect('telegram_payment')->id);
        $dispatcher->finish($telegram, 'failed', 'before_transport');
        $this->travel(6)->minutes();
        Queue::fake();
        $this->artisan('payment-effects:reconcile')->assertSuccessful();
        Queue::assertPushed(RunPaymentEffect::class, 3);
        $this->assertSame(1, $line->fresh()->attempt_count);
        $this->assertFalse($dispatcher->finish($line, 'succeeded'));
        $this->travel(24)->hours();
        $dispatcher->run($line->id);
        $this->assertSame('uncertain', $line->fresh()->state);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidPluginConfigurations')]
    public function test_plugin_configuration_at_insertion_is_frozen_or_audited(string $case): void
    {
        Event::listen('eloquent.created: '.Order::class, function () use ($case): void {
            $plugin = FlowPlugin::first();
            $otherBot = Bot::factory()->create();
            $otherFlow = Flow::factory()->create(['bot_id' => $otherBot->id]);
            $foreign = FlowPlugin::create(['flow_id' => $otherFlow->id, 'name' => 'foreign', 'trigger_condition' => 'always', 'type' => 'telegram', 'enabled' => true, 'config' => []]);
            $ids = match ($case) {
                'empty' => [], 'zero' => [0], 'multiple' => [$plugin->id, $foreign->id],
                'foreign' => [$foreign->id], 'string' => [(string) $plugin->id],
                default => [$plugin->id],
            };
            if ($case === 'disabled') {
                $plugin->update(['enabled' => false]);
            }
            if ($case === 'wrong_type') {
                $plugin->update(['type' => 'order']);
            }
            foreach (['missing_token' => 'access_token', 'missing_chat' => 'chat_id', 'missing_template' => 'message_template'] as $missing => $key) {
                if ($case === $missing) {
                    $config = $plugin->config;
                    unset($config[$key]);
                    $plugin->update(['config' => $config]);
                }
            }
            config(["commerce_safety.bots.{$this->bot->id}.payment_plugin_ids" => $ids]);
        });
        $this->settled();
        $effect = $this->effect('telegram_payment');
        $this->assertSame('failed', $effect->state);
        $this->assertNull($effect->plugin_id);
        $this->assertSame('plugin_configuration_invalid', $effect->last_error_code);
        app(PaymentEffectDispatcher::class)->run($effect->id);
        Http::assertNothingSent();
    }

    public static function invalidPluginConfigurations(): array
    {
        return array_map(fn ($case) => [$case], ['empty', 'zero', 'multiple', 'foreign', 'string', 'disabled', 'wrong_type', 'missing_token', 'missing_chat', 'missing_template']);
    }

    public function test_frozen_plugin_cannot_be_retargeted_to_another_owned_plugin(): void
    {
        $this->settled();
        $original = $this->effect('telegram_payment')->plugin_id;
        $other = FlowPlugin::first()->replicate();
        $other->save();
        config(["commerce_safety.bots.{$this->bot->id}.payment_plugin_ids" => [$other->id]]);
        app(PaymentEffectDispatcher::class)->run($this->effect('telegram_payment')->id);
        $this->assertSame($original, $this->effect('telegram_payment')->plugin_id);
        $this->assertSame('plugin_configuration_changed', $this->effect('telegram_payment')->last_error_code);
        Http::assertNothingSent();
    }

    public function test_mismatched_checkout_and_orderless_events_cannot_enqueue(): void
    {
        $event = $this->settled();
        // Model a pre-effect deployed settled event, then corrupt each authority link.
        DB::table('payment_effects')->delete();
        $orderId = $event->order_id;
        DB::table('verified_payment_events')->where('id', $event->id)->update(['order_id' => null]);
        app(PaymentEffectDispatcher::class)->enqueue($event);
        $this->assertSame(0, PaymentEffect::count());
        DB::table('verified_payment_events')->where('id', $event->id)->update(['order_id' => $orderId]);
        $other = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        $event->checkout->forceFill(['conversation_id' => $other->id])->save();
        app(PaymentEffectDispatcher::class)->enqueue($event);
        $this->assertSame(0, PaymentEffect::count());
    }

    public function test_effect_audit_restricts_event_deletion(): void
    {
        $event = $this->settled();
        $event->checkout->forceFill(['settled_event_id' => null])->save();
        try {
            DB::transaction(fn () => DB::table('verified_payment_events')->where('id', $event->id)->delete());
            $this->fail('Effect audit reference must restrict event deletion.');
        } catch (QueryException) {
            $this->assertSame(3, PaymentEffect::count());
        }
    }

    public function test_scoped_automatic_output_and_retry_leave_the_only_line_send_to_the_effect(): void
    {
        Http::fake(['api.line.me/*' => Http::response([])]);
        $event = $this->settled();
        Event::fake([MessageSent::class, ConversationUpdated::class]);
        $image = $this->conversation->messages()->create(['sender' => 'user', 'type' => 'image', 'content' => '[image]']);
        $event->slipVerification->update(['message_id' => $image->id]);
        $ctx = new WebhookContext($this->bot, ['message' => ['type' => 'image'], 'source' => ['userId' => 'fixture-user']]);
        $ctx->conversation = $this->conversation;
        $ctx->userMessage = $image;
        $ctx->metadata['bot_message'] = $event->receiptMessage;
        $ctx->response = ResponseEnvelope::text('FORGED 999');
        app(LineWebhookOutputService::class)->dispatch($ctx);
        app(SlipRetryService::class)->retry($this->bot, $this->conversation, $image, 'https://invalid.test/slip', 1);
        Http::assertNothingSent();
        app(PaymentEffectDispatcher::class)->run($this->effect('line_receipt')->id);
        app(LineWebhookOutputService::class)->dispatch($ctx);
        app(SlipRetryService::class)->retry($this->bot, $this->conversation, $image, 'https://invalid.test/slip', 2);
        app(PaymentEffectDispatcher::class)->run($this->effect('line_receipt')->id);
        Http::assertSentCount(1);
        $this->assertSame('succeeded', $this->effect('line_receipt')->state);
        $this->assertSame(3, PaymentEffect::count());
    }

    public function test_scoped_manual_confirmation_leaves_the_only_line_send_to_the_effect(): void
    {
        Http::fake(['api.line.me/*' => Http::response([])]);
        $this->payable([['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000]], 5000);
        $result = app(ManualPaymentConfirmService::class)->confirm($this->bot, $this->conversation, '50.00', $this->owner->id);
        $this->assertTrue($result['order_created']);
        $this->assertSame('manual', VerifiedPaymentEvent::sole()->source);
        Http::assertNothingSent();
        $this->assertSame(3, PaymentEffect::count());
        app(PaymentEffectDispatcher::class)->run($this->effect('line_receipt')->id);
        Http::assertSentCount(1);
    }

    public function test_postgresql_two_worker_enqueue_and_transport_claim(): void
    {
        if (DB::getDriverName() !== 'pgsql' || env('COMMERCE_SAFETY_PG_RACE') !== '1' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Controller must supply an opted-in disposable PostgreSQL database and pcntl.');
        }
        $event = $this->settled();
        DB::table('payment_effects')->delete();
        $this->runEffectWorkers(function () use ($event): void {
            app(PaymentEffectDispatcher::class)->enqueue(VerifiedPaymentEvent::findOrFail($event->id));
        });
        $this->assertSame(3, PaymentEffect::count());
        $effectId = $this->effect('telegram_payment')->id;
        $this->runEffectWorkers(function () use ($effectId): void {
            Http::fake(function () {
                $this->assertSame(0, DB::transactionLevel());
                usleep(200000);

                return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
            });
            app(PaymentEffectDispatcher::class)->run($effectId);
        });
        $this->assertSame('succeeded', $this->effect('telegram_payment')->state);
        $this->assertSame(1, $this->effect('telegram_payment')->attempt_count);
        $this->assertSame(1, Order::count());
    }

    private function runEffectWorkers(\Closure $work): void
    {
        $directory = base_path('storage/framework/testing/payment-effects-race-').bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        DB::disconnect();
        $children = [];
        $statuses = [];
        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Could not fork PostgreSQL worker');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        DB::statement("SET statement_timeout = '5s'");
                        DB::statement("SET lock_timeout = '3s'");
                        file_put_contents("{$directory}/ready-{$index}", 'ready');
                        $deadline = microtime(true) + 8;
                        while (! file_exists("{$directory}/go") && microtime(true) < $deadline) {
                            usleep(1000);
                        }
                        if (! file_exists("{$directory}/go")) {
                            throw new \RuntimeException('Start barrier timed out');
                        }
                        $work($index);
                        file_put_contents("{$directory}/result-{$index}", 'ok');
                        exit(0);
                    } catch (\Throwable $exception) {
                        file_put_contents("{$directory}/result-{$index}", $exception::class.': '.$exception->getMessage());
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            $deadline = microtime(true) + 8;
            while ((! file_exists("{$directory}/ready-0") || ! file_exists("{$directory}/ready-1")) && microtime(true) < $deadline) {
                usleep(1000);
            }
            file_put_contents("{$directory}/go", 'go');
            $deadline = microtime(true) + 12;
            foreach ($children as $pid) {
                do {
                    $done = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($done === 0) {
                        usleep(10000);
                    }
                } while ($done === 0 && microtime(true) < $deadline);
                if ($done === 0) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                    $statuses[] = 'timeout';
                } else {
                    $statuses[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 'signal';
                }
            }
            DB::purge();
            DB::reconnect();
            $results = [];
            foreach ([0, 1] as $index) {
                $results[] = is_file("{$directory}/result-{$index}") ? file_get_contents("{$directory}/result-{$index}") : 'missing result';
            }
            $this->assertSame([0, 0], $statuses, json_encode($results));
            $this->assertSame(['ok', 'ok'], $results);
        } finally {
            foreach ($children as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                }
            }
            foreach (glob("{$directory}/*") as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
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
            $this->bot, $this->conversation, $slip, $receipt, $actorId, $checkout,
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
