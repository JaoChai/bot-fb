<?php

namespace Tests\Feature\CommerceSafety;

use App\Jobs\ReserveAccountStock;
use App\Jobs\SendDeliveryCard;
use App\Models\AccountDelivery;
use App\Models\AccountDeliveryItem;
use App\Models\Bot;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\ProductStock;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\CommerceSafety\CanonicalCartValidator;
use App\Services\CommerceSafety\CheckoutAuthority;
use App\Services\CommerceSafety\CheckoutConsentPolicy;
use App\Services\CommerceSafety\PaymentProofService;
use App\Services\Delivery\AccountDeliveryService;
use App\Services\Delivery\StockPoolService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class LegacyReservationRecoveryTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithStockPool;

    private Bot $bot;

    private Conversation $conversation;

    private array $remoteTransactionLevels = [];

    // Isolated SQLite without an outer test transaction or irreversible down() migrations.
    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Log::spy();
        $this->setUpStockPool();
        config(['delivery.enabled' => true, 'commerce_safety.bots' => []]);
        $this->bot = Bot::factory()->active()->create([
            'user_id' => User::factory()->owner()->create()->id,
            'auto_delivery_enabled' => true,
        ]);
        $this->conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id, 'memory_notes' => [],
        ]);
        ProductStock::create([
            'name' => 'G3D', 'slug' => 'g3d', 'stock_code' => 'G3D', 'aliases' => [],
            'delivery_method' => 'stock', 'price' => '50.00', 'available_count' => 20,
            'in_stock' => true, 'manual_off' => false, 'display_order' => 1,
        ]);
        DB::listen(function ($query): void {
            if ($query->connectionName === StockPoolService::CONNECTION) {
                $this->remoteTransactionLevels[] = DB::transactionLevel();
            }
        });
    }

    public static function modes(): array
    {
        return ['scoped' => ['enforce'], 'off' => ['off'], 'unconfigured' => [null]];
    }

    public static function unresolvedLegacyCases(): array
    {
        $cases = [];
        foreach (self::modes() as $name => [$mode]) {
            $cases[$name.' shortage only'] = [$mode, false];
            $cases[$name.' mixed completed and shortage'] = [$mode, true];
        }

        return $cases;
    }

    #[DataProvider('unresolvedLegacyCases')]
    public function test_unresolved_legacy_reservation_holds_without_any_reserving_items(?string $mode, bool $mixed): void
    {
        [$delivery, $job] = $this->legacyDelivery($mode, $mixed ? 2 : 1);
        $items = $delivery->items()->orderBy('id')->get();
        // The old worker recorded shortage after losing the remote commit response,
        // then crashed before delivery finalization.
        $items->first()->update(['status' => AccountDeliveryItem::ST_SHORTAGE]);
        $legacyRef = StockPoolService::orderRef($delivery->id);
        $this->seedReserved(700, $legacyRef);
        if ($mixed) {
            $items->last()->update(['status' => AccountDeliveryItem::ST_RESERVED, 'stock_item_id' => 701]);
            $this->seedReserved(701, StockPoolService::orderRef($delivery->id, $items->last()->id));
        }
        $delivery->forceFill([
            'reservation_token' => 'crashed-worker',
            'reservation_claimed_at' => now()->subMinutes(6),
        ])->save();
        $this->seedAvailable(1, 'G3D');
        $before = $delivery->items()->orderBy('id')->get(['id', 'status', 'stock_item_id'])->toArray();
        $remoteBefore = DB::connection('mhha_acc')->table('items_reserved')->orderBy('id')->get()->toArray();
        $this->assertFalse($delivery->items()->where('status', AccountDeliveryItem::ST_RESERVING)->exists());

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->retry($job);

            $held = $delivery->fresh();
            $this->assertSame(AccountDelivery::STATUS_RESERVING, $held->status);
            $this->assertNull($held->reservation_token);
            $this->assertNull($held->reservation_claimed_at);
            $this->assertNull($held->card_dispatched_at);
            $this->assertSame($before, $held->items()->orderBy('id')->get(['id', 'status', 'stock_item_id'])->toArray());
            $this->assertEquals($remoteBefore, DB::connection('mhha_acc')->table('items_reserved')->orderBy('id')->get()->toArray());
            $this->assertSame([1], DB::connection('mhha_acc')->table('items_available')->pluck('id')->all());
            Queue::assertNotPushed(SendDeliveryCard::class);
        }

        $this->travel(11)->minutes();
        $this->artisan('delivery:reconcile')
            ->expectsOutputToContain("งาน #{$delivery->id} ค้างสถานะ reserving")
            ->assertSuccessful();
        $this->assertSame($legacyRef, DB::connection('mhha_acc')->table('items_reserved')->where('id', 700)->value('order_ref'));
        $this->assertRemoteOutsideLocalTransactions();
    }

    #[DataProvider('modes')]
    public function test_retry_links_the_exact_legacy_row_without_allocating_again(?string $mode): void
    {
        [$delivery, $job] = $this->legacyDelivery($mode);
        $item = $delivery->items()->sole();
        $this->seedReserved(700, StockPoolService::orderRef($delivery->id));
        $this->seedReserved(800, 'tg-external-'.$delivery->id);
        $this->seedAvailable(1, 'G3D');

        $this->retry($job);
        $this->retry($job);

        $this->assertSame([1], DB::connection('mhha_acc')->table('items_available')->pluck('id')->all());
        $this->assertSame([700, 800], DB::connection('mhha_acc')->table('items_reserved')->orderBy('id')->pluck('id')->all());
        $this->assertSame(700, $item->fresh()->stock_item_id);
        $this->assertSame(AccountDeliveryItem::ST_RESERVED, $item->fresh()->status);
        $this->assertSame(AccountDelivery::STATUS_RESERVED, $delivery->fresh()->status);
        $this->assertSame(1, $delivery->items()->count());
        $this->assertSame(StockPoolService::orderRef($delivery->id), DB::connection('mhha_acc')->table('items_reserved')->where('id', 700)->value('order_ref'));
        $this->assertSame('tg-external-'.$delivery->id, DB::connection('mhha_acc')->table('items_reserved')->where('id', 800)->value('order_ref'));
        Queue::assertPushed(SendDeliveryCard::class, 1);
        $this->assertRemoteOutsideLocalTransactions();
    }

    #[DataProvider('modes')]
    public function test_multiple_legacy_rows_stay_held_and_visible_to_reconciliation(?string $mode): void
    {
        [$delivery, $job] = $this->legacyDelivery($mode);
        $this->seedReserved(700, StockPoolService::orderRef($delivery->id));
        $this->seedReserved(701, StockPoolService::orderRef($delivery->id));
        $this->seedReserved(800, 'tg-external-'.$delivery->id);
        $this->seedAvailable(1, 'G3D');

        $this->retry($job);
        $this->retry($job); // Anchor adoption must not erase the legacy ambiguity on later retries.

        $this->assertHeld($delivery);
        $this->assertSame([700, 701, 800], DB::connection('mhha_acc')->table('items_reserved')->orderBy('id')->pluck('id')->all());
        $orphans = app(StockPoolService::class)->orphanedReservedRows([]);
        $this->assertSame([700, 701], array_column($orphans, 'id'));
        $this->assertSame([StockPoolService::orderRef($delivery->id)], array_values(array_unique(array_column($orphans, 'order_ref'))));
        $this->travel(11)->minutes();
        $this->artisan('delivery:reconcile')->expectsOutputToContain("งาน #{$delivery->id} ค้างสถานะ reserving")->assertSuccessful();
        foreach (['warning', 'error'] as $level) {
            Log::shouldNotHaveReceived($level, fn ($message, $context = []) => str_contains($message.json_encode($context), 'SECRET-LEGACY-DETAIL'));
        }
        $this->assertRemoteOutsideLocalTransactions();
    }

    #[DataProvider('modes')]
    public function test_external_and_other_delivery_refs_do_not_block_per_item_reservations(?string $mode): void
    {
        [$delivery, $job] = $this->legacyDelivery($mode);
        $refs = ['tg-external-'.$delivery->id, 'external:bfb:'.$delivery->id, StockPoolService::orderRef($delivery->id.'0')];
        foreach ($refs as $index => $ref) {
            $this->seedReserved(800 + $index, $ref);
        }
        $this->seedAvailable(1, 'G3D');
        $this->seedAvailable(2, 'G3D');
        $this->retry($job);
        $this->retry($job);

        $item = $delivery->items()->sole();
        $this->assertSame(1, $item->stock_item_id);
        $this->assertSame(StockPoolService::orderRef($delivery->id, $item->id), DB::connection('mhha_acc')->table('items_reserved')->where('id', 1)->value('order_ref'));
        $this->assertSame($refs, DB::connection('mhha_acc')->table('items_reserved')->where('id', '>=', 800)->orderBy('id')->pluck('order_ref')->all());
        $this->assertSame([2], DB::connection('mhha_acc')->table('items_available')->pluck('id')->all());
        $this->assertRemoteOutsideLocalTransactions();
    }

    public function test_one_legacy_row_is_not_guessed_between_two_local_units(): void
    {
        [$delivery, $job] = $this->legacyDelivery('off', 2);
        $this->seedReserved(700, StockPoolService::orderRef($delivery->id));
        $this->seedAvailable(1, 'G3D');
        $this->retry($job);
        $this->retry($job);
        $this->assertHeld($delivery);
        $this->assertSame(0, $delivery->items()->whereNotNull('stock_item_id')->count());
    }

    public function test_a_legacy_row_for_another_product_is_held_without_allocating(): void
    {
        [$delivery, $job] = $this->legacyDelivery('off');
        $this->seedReserved(700, StockPoolService::orderRef($delivery->id), 'OTHER');
        $this->seedAvailable(1, 'G3D');
        $this->retry($job);
        $this->assertHeld($delivery);
    }

    public function test_an_already_linked_legacy_row_is_not_reused_for_the_remaining_unit(): void
    {
        [$delivery, $job] = $this->legacyDelivery('off', 2);
        $delivery->items()->firstOrFail()->update(['stock_item_id' => 700, 'status' => AccountDeliveryItem::ST_RESERVED]);
        $this->seedReserved(700, StockPoolService::orderRef($delivery->id));
        $this->seedAvailable(1, 'G3D');
        $this->retry($job);
        $this->assertSame([700, 1], $delivery->items()->pluck('stock_item_id')->all());
        $this->assertSame(AccountDelivery::STATUS_RESERVED, $delivery->fresh()->status);
    }

    public function test_conflicting_legacy_and_per_item_reservations_are_held(): void
    {
        [$delivery, $job] = $this->legacyDelivery('off');
        $this->seedReserved(700, StockPoolService::orderRef($delivery->id));
        $this->seedReserved(701, StockPoolService::orderRef($delivery->id, $delivery->items()->sole()->id));
        $this->seedAvailable(1, 'G3D');
        $this->retry($job);
        $this->assertHeld($delivery);
    }

    private function assertHeld(AccountDelivery $delivery): void
    {
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_available')->count());
        $this->assertSame(AccountDelivery::STATUS_RESERVING, $delivery->fresh()->status);
        $this->assertSame(0, $delivery->items()->whereNotNull('stock_item_id')->count());
        $this->assertNull($delivery->fresh()->reservation_token);
        $this->assertNull($delivery->fresh()->card_dispatched_at);
        Queue::assertNotPushed(SendDeliveryCard::class);
    }

    private function assertRemoteOutsideLocalTransactions(): void
    {
        $this->assertNotEmpty($this->remoteTransactionLevels);
        $this->assertSame([0], array_values(array_unique($this->remoteTransactionLevels)));
        Http::assertNothingSent();
    }

    private function retry(ReserveAccountStock $job): void
    {
        unserialize(serialize($job))->handle(app(AccountDeliveryService::class), app(CheckoutAuthority::class));
    }

    private function seedReserved(int $id, string $ref, string $code = 'G3D'): void
    {
        DB::connection('mhha_acc')->table('items_reserved')->insert([
            'id' => $id, 'name' => $code, 'detail' => 'SECRET-LEGACY-DETAIL',
            'order_ref' => $ref, 'reservedAt' => now()->subMinutes(20),
        ]);
    }

    private function legacyDelivery(?string $mode, int $qty = 1): array
    {
        if ($mode !== null) {
            config(["commerce_safety.bots.{$this->bot->id}" => ['mode' => $mode]]);
        }
        $items = [['name' => 'G3D', 'qty' => $qty, 'total' => (string) (50 * $qty)]];
        $slip = SlipVerification::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id,
            'amount' => 50 * $qty, 'status' => 'passed', 'trans_ref' => 'LEGACY-RECOVERY',
        ]);
        if ($mode === 'enforce') {
            $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);
            $plugin = FlowPlugin::create([
                'flow_id' => $flow->id, 'type' => 'order', 'name' => 'Recovery fixture',
                'enabled' => true, 'trigger_condition' => 'always', 'config' => [],
            ]);
            config(["commerce_safety.bots.{$this->bot->id}.payment_plugin_ids" => [$plugin->id]]);
            $cart = app(CanonicalCartValidator::class)->validate($this->bot, $this->conversation,
                [['name' => 'G3D', 'method' => 'none', 'qty' => $qty, 'price_minor' => 5000]], 5000 * $qty);
            $this->assertTrue($cart->valid);
            $requirements = app(CheckoutConsentPolicy::class)->requirements($this->bot, $this->conversation, $cart->lines);
            $accepted = [];
            foreach (['confirm', 'topup_ack', 'support_delay', 'terms'] as $stage) {
                if ($stage === 'confirm' || $requirements[$stage]) {
                    $accepted[$stage] = $this->conversation->messages()->create([
                        'sender' => 'user', 'type' => 'text', 'content' => "accept {$stage}",
                    ])->id;
                }
            }
            $checkout = new CheckoutSession;
            $checkout->forceFill([
                'bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id,
                'revision' => 1, 'state' => 'payable', 'items' => $cart->lines,
                'total_minor' => $cart->totalMinor, 'currency' => 'THB', 'fingerprint' => $cart->fingerprint,
                'requirements' => $requirements, 'accepted' => $accepted,
            ])->save();
            foreach ($accepted as $stage => $messageId) {
                DB::table('checkout_consent_acceptances')->insert([
                    'checkout_id' => $checkout->id, 'revision' => 1, 'stage' => $stage,
                    'message_id' => $messageId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $receipt = $this->conversation->messages()->create([
                'sender' => 'bot', 'type' => 'text', 'content' => 'Payment received',
            ]);
            $event = app(PaymentProofService::class)->record($this->bot, $this->conversation, $slip, $receipt, null);
            $this->assertSame('settled', app(CheckoutAuthority::class)->settle($checkout, $event)->action);
        }
        $delivery = AccountDelivery::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id,
            'slip_verification_id' => $slip->id, 'status' => AccountDelivery::STATUS_RESERVING, 'amount' => 50 * $qty,
        ]);
        for ($unit = 0; $unit < $qty; $unit++) {
            $delivery->items()->create([
                'product_name' => 'G3D', 'stock_code' => 'G3D', 'kind' => AccountDeliveryItem::KIND_STOCK,
                'qty' => 1, 'status' => AccountDeliveryItem::ST_RESERVING,
            ]);
        }

        return [$delivery, new ReserveAccountStock($this->bot->id, $this->conversation->id, $slip->id, 50.0 * $qty, $items)];
    }
}
