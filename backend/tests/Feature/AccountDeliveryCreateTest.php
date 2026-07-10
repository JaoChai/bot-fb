<?php

namespace Tests\Feature;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\ProductStock;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Delivery\AccountDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class AccountDeliveryCreateTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    private SlipVerification $slip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockPool();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $user = User::factory()->owner()->create();
        $this->bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);
        $this->bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id, 'type' => 'telegram', 'name' => 'แจ้งออเดอร์',
            'enabled' => true, 'trigger_condition' => 'always',
            'config' => ['access_token' => 'TOK', 'chat_id' => '999'],
        ]);
        $this->bot = $this->bot->fresh();
        $this->conversation = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        $this->slip = SlipVerification::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id,
            'amount' => 1299, 'status' => 'passed',
        ]);

        config(['delivery.enabled' => true]);
        $this->bot->update(['auto_delivery_enabled' => true]);
        $this->bot = $this->bot->fresh();

        ProductStock::create([
            'name' => 'Nolimit ส่วนตัว', 'slug' => 'nolimit-personal', 'aliases' => [],
            'in_stock' => true, 'display_order' => 1,
            'stock_code' => 'NLMP', 'delivery_method' => 'stock',
        ]);
        ProductStock::create([
            'name' => 'เพจ', 'slug' => 'page', 'aliases' => [], 'in_stock' => true,
            'display_order' => 2, 'stock_code' => null, 'delivery_method' => 'support_link',
        ]);
    }

    private function create(array $items): ?AccountDelivery
    {
        return app(AccountDeliveryService::class)->createFromPayment(
            $this->bot, $this->conversation, $this->slip->id, 1299.0, $items,
        );
    }

    public function test_reserves_stock_and_records_items(): void
    {
        $this->seedAvailable(10, 'NLMP');
        $this->seedAvailable(11, 'NLMP');

        $delivery = $this->create([
            ['name' => 'Nolimit ส่วนตัว', 'total' => '2200', 'qty' => 2],
            ['name' => 'เพจ', 'total' => '199', 'qty' => 1],
        ]);

        $this->assertSame(AccountDelivery::STATUS_RESERVED, $delivery->status);
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_available')->count());
        $this->assertSame(2, DB::connection('mhha_acc')->table('items_reserved')->count());
        $this->assertSame(2, $delivery->items()->where('kind', 'stock')->where('status', 'reserved')->count());
        $this->assertSame(1, $delivery->items()->where('kind', 'support_link')->count());

        // การ์ดถูกส่งเข้า Telegram พร้อมปุ่ม dv/dx
        Http::assertSent(function ($request) use ($delivery) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['reply_markup'] ?? '', "dv|{$delivery->id}|x")
                && str_contains($request['reply_markup'] ?? '', "dx|{$delivery->id}|x");
        });
    }

    public function test_reserve_writes_bfb_prefixed_order_ref(): void
    {
        $this->seedAvailable(10, 'NLMP');

        $delivery = $this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1100']]);

        // order_ref ต้องมี prefix bfb: เพื่อแยกจากบอทเบิกภายนอกที่ใช้ items_reserved ร่วมกัน
        $ref = DB::connection('mhha_acc')->table('items_reserved')->value('order_ref');
        $this->assertSame("bfb:{$delivery->id}", $ref);
    }

    public function test_shortage_and_unmapped_are_recorded_not_guessed(): void
    {
        $this->seedAvailable(10, 'NLMP'); // มีชิ้นเดียว แต่สั่ง 2

        $delivery = $this->create([
            ['name' => 'Nolimit ส่วนตัว', 'total' => '2200', 'qty' => 2],
            ['name' => 'ของประหลาด', 'total' => '100'],
        ]);

        $this->assertSame(AccountDelivery::STATUS_RESERVED, $delivery->status);
        $this->assertSame(1, $delivery->items()->where('status', 'reserved')->count());
        $this->assertSame(1, $delivery->items()->where('status', 'shortage')->count());
        $this->assertSame(1, $delivery->items()->where('status', 'unmapped')->count());
    }

    public function test_qty_is_capped_to_max(): void
    {
        config(['delivery.max_qty' => 20]);
        $this->seedAvailable(10, 'NLMP');
        $this->seedAvailable(11, 'NLMP');

        // summary เพี้ยนสั่ง 999 — ต้องจองไม่เกินเพดาน 20 (2 reserved + 18 shortage)
        $delivery = $this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '999999', 'qty' => 999]]);

        $this->assertSame(20, $delivery->items()->count());
        $this->assertSame(2, DB::connection('mhha_acc')->table('items_reserved')->count());
    }

    public function test_nothing_deliverable_marks_failed(): void
    {
        $delivery = $this->create([['name' => 'ของประหลาด', 'total' => '100']]);

        $this->assertSame(AccountDelivery::STATUS_FAILED, $delivery->status);
    }

    public function test_duplicate_slip_verification_returns_null_without_reserving(): void
    {
        $this->seedAvailable(10, 'NLMP');
        $this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1100']]);

        $second = $this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1100']]);

        $this->assertNull($second);
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_reserved')->count());
    }

    public function test_second_delivery_for_same_conversation_and_amount_is_blocked(): void
    {
        $this->seedAvailable(10, 'NLMP');
        $this->seedAvailable(11, 'NLMP');

        // path แรก (เช่น manual confirm) — slip ใบที่ 1
        $first = $this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1299']]);
        $this->assertNotNull($first);

        // path ที่สอง (เช่น EasySlip) — slip คนละใบ ยอดเดียวกัน conversation เดิม
        $slip2 = SlipVerification::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id,
            'trans_ref' => 'TXN999', 'amount' => 1299, 'status' => 'passed',
        ]);
        $second = app(AccountDeliveryService::class)->createFromPayment(
            $this->bot, $this->conversation, $slip2->id, 1299.0,
            [['name' => 'Nolimit ส่วนตัว', 'total' => '1299']],
        );

        $this->assertNull($second); // กันขายซ้ำข้าม dispatch path
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_reserved')->count());
    }

    public function test_second_delivery_with_different_amount_is_allowed(): void
    {
        $this->seedAvailable(10, 'NLMP');
        $this->seedAvailable(11, 'NLMP');

        $first = $this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1299']]);
        $this->assertNotNull($first);

        // ซื้อรอบใหม่ ยอดต่างกัน — ต้องไม่โดนบล็อก (ไม่ใช่ duplicate)
        $slip2 = SlipVerification::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id,
            'trans_ref' => 'TXN888', 'amount' => 550, 'status' => 'passed',
        ]);
        $second = app(AccountDeliveryService::class)->createFromPayment(
            $this->bot, $this->conversation, $slip2->id, 550.0,
            [['name' => 'Nolimit ส่วนตัว', 'total' => '550']],
        );

        $this->assertNotNull($second);
        $this->assertSame(2, DB::connection('mhha_acc')->table('items_reserved')->count());
    }

    public function test_disabled_or_wrong_bot_returns_null(): void
    {
        config(['delivery.enabled' => false]);
        $this->assertNull($this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1100']]));

        $this->bot->update(['auto_delivery_enabled' => false]);
        $this->bot = $this->bot->fresh();
        $this->assertNull($this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1100']]));
    }

    public function test_returns_null_when_bot_auto_delivery_disabled(): void
    {
        $this->seedAvailable(10, 'NLMP');
        $this->bot->update(['auto_delivery_enabled' => false]);
        $this->bot = $this->bot->fresh();

        $delivery = $this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1,299 บาท']]);

        $this->assertNull($delivery);
        $this->assertDatabaseCount('account_deliveries', 0);
        $this->assertSame(1, $this->countAvailable('NLMP')); // ไม่แตะ stock
    }

    public function test_returns_null_when_master_env_disabled_even_if_bot_enabled(): void
    {
        $this->seedAvailable(10, 'NLMP');
        config(['delivery.enabled' => false]);

        $delivery = $this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1,299 บาท']]);

        $this->assertNull($delivery);
        $this->assertDatabaseCount('account_deliveries', 0);
    }

    private function countAvailable(string $code): int
    {
        return DB::connection('mhha_acc')->table('items_available')->where('name', $code)->count();
    }
}
