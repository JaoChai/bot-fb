<?php

namespace Tests\Feature;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\SlipVerification;
use App\Models\User;
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
        // แถว reserved ที่ชี้ order_ref = 9999 ซึ่งไม่มีงาน active
        DB::connection('mhha_acc')->table('items_reserved')->insert([
            'id' => 77, 'name' => 'NLMP', 'detail' => 'x|y', 'type' => 'x',
            'order_ref' => '9999', 'reservedAt' => now(),
            'createdAt' => now(), 'updatedAt' => now(),
        ]);
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
        DB::connection('mhha_acc')->table('items_reserved')->insert([
            'id' => 55, 'name' => 'NLMP', 'detail' => 'x|y', 'type' => 'x',
            'order_ref' => (string) $delivery->id, 'reservedAt' => now(),
            'createdAt' => now(), 'updatedAt' => now(),
        ]);

        $this->artisan('delivery:reconcile')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', '#55')
            && str_contains($r['text'] ?? '', 'ห้ามขายซ้ำ'));
    }

    public function test_non_numeric_external_order_ref_does_not_break_disambiguation(): void
    {
        // แถวของบอทเบิกภายนอก (order_ref ไม่ใช่ตัวเลข) ปนอยู่ ต้องไม่ทำให้ query พัง
        // และ alert "ห้ามขายซ้ำ" ของงาน delivered ต้องยังอยู่ครบ
        $delivery = $this->makeDelivery('delivered');
        DB::connection('mhha_acc')->table('items_reserved')->insert([
            ['id' => 55, 'name' => 'NLMP', 'detail' => 'x|y', 'type' => 'x',
                'order_ref' => (string) $delivery->id, 'reservedAt' => now(),
                'createdAt' => now(), 'updatedAt' => now()],
            ['id' => 56, 'name' => 'G3D', 'detail' => 'a|b', 'type' => 'x',
                'order_ref' => 'tg-external-999', 'reservedAt' => now(),
                'createdAt' => now(), 'updatedAt' => now()],
        ]);

        $this->artisan('delivery:reconcile')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', '#55')
            && str_contains($r['text'] ?? '', 'ห้ามขายซ้ำ')
            && str_contains($r['text'] ?? '', '#56')); // แถวภายนอกยังถูกรายงาน ไม่ถูกกลืน
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
        DB::connection('mhha_acc')->table('items_reserved')->insert([
            'id' => 88, 'name' => 'NLMP', 'detail' => 'x|y', 'type' => 'x',
            'order_ref' => '9999', 'reservedAt' => now(),
            'createdAt' => now(), 'updatedAt' => now(),
        ]);
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
        DB::connection('mhha_acc')->table('items_reserved')->insert([
            'id' => 99, 'name' => 'NLMP', 'detail' => 'x|y', 'type' => 'x',
            'order_ref' => (string) $delivery->id, 'reservedAt' => now(),
            'createdAt' => now(), 'updatedAt' => now(),
        ]);

        $this->artisan('delivery:reconcile')->assertSuccessful();

        Http::assertNothingSent();
    }
}
