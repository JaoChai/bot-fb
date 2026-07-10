<?php

namespace Tests\Feature;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Delivery\StockPoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class ReconcileDeliveriesTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockPool();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id, 'type' => 'telegram', 'name' => 'แจ้งออเดอร์',
            'enabled' => true, 'trigger_condition' => 'always',
            'config' => ['access_token' => 'TOK', 'chat_id' => '999'],
        ]);
        $this->bot = $bot->fresh();
        $this->conv = Conversation::factory()->create(['bot_id' => $bot->id]);
    }

    private Bot $bot;

    private Conversation $conv;

    /**
     * `created_at`/`updated_at` are NOT mass-assignable on AccountDelivery, so any backdate
     * requested via $attrs must be applied via forceFill AFTER create(), with timestamps
     * disabled so save() doesn't stomp updated_at back to "now" (see task-9-report.md).
     */
    private function makeDelivery(string $status, array $attrs = []): AccountDelivery
    {
        $slip = SlipVerification::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conv->id,
            'amount' => 1100, 'status' => 'passed',
        ]);

        $backdate = array_intersect_key($attrs, array_flip(['created_at', 'updated_at']));
        $attrs = array_diff_key($attrs, $backdate);

        $delivery = AccountDelivery::create(array_merge([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conv->id,
            'slip_verification_id' => $slip->id, 'status' => $status, 'amount' => 1100,
        ], $attrs));

        if ($backdate !== []) {
            $delivery->timestamps = false;
            $delivery->forceFill($backdate)->save();
        }

        return $delivery;
    }

    /** ใส่แถว items_reserved (default: ค้างเกิน 10 นาที ให้ผ่าน age filter ของ reconcile) */
    private function insertReserved(int $id, string $orderRef, ?string $name = 'NLMP', $reservedAt = null): void
    {
        $reservedAt ??= now()->subMinutes(20);
        DB::connection('mhha_acc')->table('items_reserved')->insert([
            'id' => $id, 'name' => $name, 'detail' => 'x|y', 'type' => 'x',
            'order_ref' => $orderRef, 'reservedAt' => $reservedAt,
            'createdAt' => $reservedAt, 'updatedAt' => $reservedAt,
        ]);
    }

    public function test_alerts_on_stuck_reserving_delivery(): void
    {
        $this->makeDelivery('reserving', ['updated_at' => now()->subMinutes(30)]);

        $this->artisan('delivery:reconcile')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', 'reserving'));
    }

    public function test_alerts_on_stuck_delivering_delivery(): void
    {
        $this->makeDelivery('delivering', ['updated_at' => now()->subMinutes(30)]);

        $this->artisan('delivery:reconcile')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', 'delivering')
            && str_contains($r['text'] ?? '', 'เช็คแชทก่อน'));
    }

    public function test_alerts_on_orphaned_reserved_rows(): void
    {
        // แถว reserved ที่ชี้ order_ref = bfb:9999 ซึ่งไม่มีงาน active
        $this->insertReserved(77, StockPoolService::orderRef(9999));
        // ต้องมี delivery อย่างน้อย 1 งานเพื่อ resolve telegram plugin
        $this->makeDelivery('delivered');

        $this->artisan('delivery:reconcile')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', '#77'));
    }

    public function test_delivered_orphan_says_do_not_resell(): void
    {
        // markSold พังหลังส่ง → แถวค้าง items_reserved โดยงานเป็น delivered
        // reconcile ต้องแยกให้ชัดว่า "ส่งแล้ว ห้ามขายซ้ำ" ไม่ใช่ "คืน stock ได้"
        $delivery = $this->makeDelivery('delivered');
        $this->insertReserved(55, StockPoolService::orderRef($delivery->id));

        $this->artisan('delivery:reconcile')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', '#55')
            && str_contains($r['text'] ?? '', 'ห้ามขายซ้ำ'));
    }

    public function test_external_bot_reserved_rows_are_never_touched(): void
    {
        // #9: บอทเบิกภายนอกใช้ items_reserved ร่วมกัน (order_ref ไม่มี prefix bfb:)
        // reconcile ต้องไม่ไปยุ่ง/รายงานแถวของมันเลย — รายงานเฉพาะของ bot-fb
        $delivery = $this->makeDelivery('delivered');
        $this->insertReserved(55, StockPoolService::orderRef($delivery->id));
        $this->insertReserved(56, 'tg-external-999', 'G3D'); // แถวบอทภายนอก

        $this->artisan('delivery:reconcile')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', '#55')
            && str_contains($r['text'] ?? '', 'ห้ามขายซ้ำ')
            && ! str_contains($r['text'] ?? '', '#56')); // แถวภายนอกไม่ถูกแตะเลย
    }

    public function test_quiet_when_all_clean(): void
    {
        $this->makeDelivery('reserved'); // ปกติ — ไม่ orphan ไม่ stuck

        $this->artisan('delivery:reconcile')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_alerts_via_fallback_plugin_when_no_delivery_exists_at_all(): void
    {
        // ของหลุดอยู่ใน items_reserved ตั้งแต่ก่อนเคยมีงานส่งของเลยสักงาน
        $this->insertReserved(88, StockPoolService::orderRef(9999));
        config(['delivery.bot_ids' => [$this->bot->id]]);

        // ไม่มี AccountDelivery แม้แต่แถวเดียว
        $this->assertSame(0, AccountDelivery::count());

        $this->artisan('delivery:reconcile')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', '#88'));
    }

    public function test_active_reserved_delivery_is_not_flagged_as_orphan(): void
    {
        $delivery = $this->makeDelivery('reserved');
        // งาน active (reserved) + ค้างเกิน 10 นาที — ต้องไม่ถูก flag เพราะ order_ref อยู่ใน activeRefs
        $this->insertReserved(99, StockPoolService::orderRef($delivery->id));

        $this->artisan('delivery:reconcile')->assertSuccessful();

        Http::assertNothingSent();
    }
}
