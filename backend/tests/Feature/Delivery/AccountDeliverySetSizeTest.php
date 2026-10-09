<?php

namespace Tests\Feature\Delivery;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Delivery\AccountDeliveryService;
use App\Services\Delivery\StockPoolService;
use App\Services\LINEService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

/**
 * แอดมินเลือกขนาดชุด (setSize) จากปุ่มบนการ์ด → deliver แบ่งรอบตามชุดที่เลือก
 * + ลูกค้าต้องได้รู้ว่าถูกเพดานตัดจำนวน (ระบบส่งให้ X จากที่สั่ง Y) ไม่ใช่หายเงียบ
 */
class AccountDeliverySetSizeTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    private AccountDelivery $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockPool();
        config(['delivery.split_from' => 10, 'delivery.max_qty' => 30]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $user = User::factory()->owner()->create();
        $this->bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $this->conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id, 'channel_type' => 'line',
            'external_customer_id' => 'Uabc123',
        ]);
        $slip = SlipVerification::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id,
            'amount' => 1299, 'status' => 'passed',
        ]);
        $this->delivery = AccountDelivery::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id,
            'slip_verification_id' => $slip->id,
            'status' => AccountDelivery::STATUS_RESERVED, 'amount' => 1299,
        ]);
        $this->delivery->items()->create([
            'product_name' => 'เพจ', 'kind' => 'support_link', 'qty' => 1, 'status' => 'reserved',
        ]);
    }

    /** เพิ่มบัญชีจอง N บัญชี (id 10..9+N, product G3D) — ลบ item เพจของ setUp ก่อนเรียกถ้าไม่ต้องการเพจ */
    private function addReservedAccounts(int $count): void
    {
        $pool = app(StockPoolService::class);
        foreach (range(10, 9 + $count) as $id) {
            $this->seedAvailable($id, 'G3D', "uid{$id}|pass{$id}|mail|2fa");
            $pool->reserveOne('G3D', '1');
            $this->delivery->items()->create([
                'product_name' => 'G3D', 'stock_code' => 'G3D', 'kind' => 'stock',
                'qty' => 1, 'stock_item_id' => $id, 'status' => 'reserved',
            ]);
        }
    }

    /** mark item แรก requested_qty — จำลอง createFromPayment ตัดเพดาน (requested > จองได้) */
    private function capFirstStockItem(int $requested): void
    {
        $item = $this->delivery->items()->where('kind', 'stock')->orderBy('id')->first();
        $item->update(['requested_qty' => $requested]);
    }

    /**
     * mock LINE: เก็บแต่ละ push เป็น array ของ bubble (string) — คืน closure อ่านค่า
     *
     * @return \Closure(): array<int, array<int, string>>
     */
    private function capturePushes(): \Closure
    {
        $pushes = [];
        $this->mock(LINEService::class, function (MockInterface $mock) use (&$pushes) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')
                ->andReturnUsing(function ($bot, $token, $userId, $messages) use (&$pushes) {
                    $pushes[] = array_column($messages, 'text');

                    return ['method' => 'push', 'success' => true];
                });
        });

        return function () use (&$pushes): array {
            return $pushes;
        };
    }

    private static function accountsPerBubble(array $bubbles): array
    {
        return array_map(fn (string $b): int => substr_count($b, '|mail|2fa'), $bubbles);
    }

    public function test_case_1_forty_one_accounts_set_size_twenty_makes_three_rounds(): void
    {
        $this->addReservedAccounts(41);
        $this->capFirstStockItem(41); // prod #507: สั่ง 41 ถูกเพดาน 30 — ที่นี่ seed 41 ได้เพราะ seed เอง
        $pushes = $this->capturePushes();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม', 20);

        [$p1, $p2, $p3] = $pushes();
        $this->assertCount(3, $pushes());
        $this->assertSame([20], self::accountsPerBubble($p1));
        $this->assertSame([20], self::accountsPerBubble($p2));
        $this->assertSame([1], self::accountsPerBubble([$p3[0]]));
        $this->assertStringContainsString('lin.ee/sTD5TQL', $p3[count($p3) - 1]);
        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_reserved')->count());
        $this->assertSame(41, DB::connection('mhha_acc')->table('items_sold')->count());
    }

    public function test_case_2_thirty_accounts_without_set_size_still_halves(): void
    {
        $this->addReservedAccounts(30);
        $pushes = $this->capturePushes();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        [$p1, $p2] = $pushes();
        $this->assertCount(2, $pushes());
        $this->assertSame([15], self::accountsPerBubble([$p1[0]]));
        $this->assertSame([15], self::accountsPerBubble([$p2[0]]));
        $this->assertStringContainsString('lin.ee/sTD5TQL', $p2[1]);
    }

    public function test_case_3_thirty_accounts_set_size_fifteen(): void
    {
        $this->addReservedAccounts(30);
        $pushes = $this->capturePushes();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม', 15);

        [$p1, $p2] = $pushes();
        $this->assertCount(2, $pushes());
        $this->assertSame([15], self::accountsPerBubble([$p1[0]]));
        $this->assertSame([15], self::accountsPerBubble([$p2[0]]));
        $this->assertStringContainsString('lin.ee/sTD5TQL', $p2[1]);
    }

    public function test_case_4_thirty_accounts_set_size_ten_makes_three_rounds(): void
    {
        $this->addReservedAccounts(30);
        $pushes = $this->capturePushes();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม', 10);

        [$p1, $p2, $p3] = $pushes();
        $this->assertCount(3, $pushes());
        $this->assertSame([10], self::accountsPerBubble([$p1[0]]));
        $this->assertSame([10], self::accountsPerBubble([$p2[0]]));
        $this->assertSame([10], self::accountsPerBubble([$p3[0]]));
        $this->assertStringContainsString('lin.ee/sTD5TQL', $p3[1]);
    }

    public function test_case_5_capped_order_notice_bubble_reaches_customer_before_support(): void
    {
        // สั่ง 41 ระบบจองได้แค่ 30 (เพดาน) — ลูกค้าต้องเห็น "ส่งให้แล้ว 30 จาก 41 ... อีก 11"
        $this->addReservedAccounts(30);
        $this->capFirstStockItem(41);
        $pushes = $this->capturePushes();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        [$p1, $p2] = $pushes();
        $this->assertSame([15], self::accountsPerBubble([$p1[0]]));
        $this->assertSame([15], self::accountsPerBubble([$p2[0]]));
        // บับเบิลแจ้งลูกค้า: หลังบัญชี ก่อน support — ข้อความตามที่การ์ดกำหนด
        $notice = $p2[1];
        $this->assertStringContainsString('ระบบส่งให้แล้ว 30 จาก 41 บัญชี (G3D)', $notice);
        $this->assertStringContainsString('อีก 11 บัญชี', $notice);
        $this->assertStringContainsString('ทีมงานกำลังส่งตามให้ในแชทนี้นะครับ', $notice);
        $this->assertStringContainsString('lin.ee/sTD5TQL', $p2[2]);
    }

    public function test_no_notice_bubble_when_nothing_capped(): void
    {
        $this->addReservedAccounts(30);
        $pushes = $this->capturePushes();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        [$p1, $p2] = $pushes();
        foreach ($p1 as $bubble) {
            $this->assertStringNotContainsString('ระบบส่งให้แล้ว', $bubble);
        }
        foreach ($p2 as $bubble) {
            $this->assertStringNotContainsString('ระบบส่งให้แล้ว', $bubble);
        }
    }

    public function test_notice_bubble_has_no_credential_text(): void
    {
        $this->addReservedAccounts(30);
        $this->capFirstStockItem(41);
        $pushes = $this->capturePushes();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        $pushesAll = $pushes();
        $this->assertNotEmpty($pushesAll);
        foreach ($pushesAll as $bubbles) {
            foreach ($bubbles as $bubble) {
                if (str_contains($bubble, 'ระบบส่งให้แล้ว')) {
                    $this->assertStringNotContainsString('|mail|2fa', $bubble);
                    $this->assertStringNotContainsString('uid1', $bubble);
                }
                $this->assertLessThanOrEqual(5000, mb_strlen($bubble));
            }
            $this->assertLessThanOrEqual(5, count($bubbles));
        }
    }

    public function test_notice_bubble_stored_in_conversation_history_for_the_ai_bot(): void
    {
        $this->addReservedAccounts(30);
        $this->capFirstStockItem(41);

        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->andReturn(['method' => 'push', 'success' => true]);
        });

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        $msg = $this->conversation->messages()->latest('id')->first();
        $this->assertStringContainsString('ระบบส่งให้แล้ว 30 จาก 41', $msg->content);
        // ห้ามมี credential ดิบในประวัติแชท (เข้า LLM context)
        $this->assertStringNotContainsString('|mail|2fa', $msg->content);
    }

    public function test_set_size_out_of_range_is_ignored_and_halves(): void
    {
        $this->addReservedAccounts(30);
        $pushes = $this->capturePushes();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม', 999);

        [$p1, $p2] = $pushes();
        $this->assertCount(2, $pushes());
        $this->assertSame([15], self::accountsPerBubble([$p1[0]]));
        $this->assertSame([15], self::accountsPerBubble([$p2[0]]));
    }

    /** mock LINE push สำเร็จ (ไม่สนข้อความ) — สำหรับเทสต์ที่ต้อง deliver แล้วดูผลลัพธ์หลังส่ง */
    private function mockLineOk(): void
    {
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->andReturn(['method' => 'push', 'success' => true]);
        });
    }

    /**
     * ทุกสินค้าที่โดนเพดานตัด ต้องมีบรรทัดของตัวเองในบับเบิลเดียวกัน (spec: one line per capped product)
     */
    public function test_notice_bubble_has_one_line_per_capped_product(): void
    {
        $this->addReservedAccounts(30);
        $this->capFirstStockItem(41); // G3D สั่ง 41 จองได้ 30
        $this->seedAvailable(900, 'BM', 'uid900|pass900|mail|2fa');
        app(StockPoolService::class)->reserveOne('BM', '1');
        $this->delivery->items()->create([
            'product_name' => 'BM', 'stock_code' => 'BM', 'kind' => 'stock',
            'qty' => 1, 'stock_item_id' => 900, 'status' => 'reserved', 'requested_qty' => 5,
        ]);
        $pushes = $this->capturePushes();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        $notice = $pushes()[1][1];
        $this->assertStringContainsString('ระบบส่งให้แล้ว 30 จาก 41 บัญชี (G3D)', $notice);
        $this->assertStringContainsString('ระบบส่งให้แล้ว 1 จาก 5 บัญชี (BM)', $notice);
    }

    /** ข้อความแจ้งลูกค้าต้องตรง spec ทุกตัวอักษร (emoji, ไม่มีขีดคั่นก่อน "อีก") */
    public function test_notice_text_matches_spec_exactly(): void
    {
        $this->addReservedAccounts(30);
        $this->capFirstStockItem(41);
        $pushes = $this->capturePushes();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        $notice = $pushes()[1][1];
        $this->assertSame(
            '📦 ระบบส่งให้แล้ว 30 จาก 41 บัญชี (G3D) อีก 11 บัญชี ทีมงานกำลังส่งตามให้ในแชทนี้นะครับ',
            $notice
        );
    }

    /** บับเบิลแจ้งเพดานต้องกินโควตา 5 บับเบิลของ push ด้วย — packRound ต้องกันที่ไว้ให้ (spec: leave room) */
    public function test_pack_round_reserves_a_bubble_slot_for_the_cap_notice(): void
    {
        $service = app(AccountDeliveryService::class);
        $packRound = new \ReflectionMethod($service, 'packRound');
        $packRound->setAccessible(true);

        // repro ของ reviewer: 16 บัญชี × ~1050 ตัวอักษร — ใส่ได้แค่ก้อนละ 4 บัญชี (≤5000)
        // ก่อนแก้: 4 ก้อนบัญชี + notice + support = 6 บับเบิล → assertFitsSinglePush พังทั้งงาน
        $accounts = array_fill(0, 16, str_repeat('ก', 1050));
        $bubbles = $packRound->invoke($service, $accounts, 'SUPPORT', 'NOTICE');

        $this->assertLessThanOrEqual(5, count($bubbles));
        $this->assertSame('NOTICE', $bubbles[count($bubbles) - 2]);
        $this->assertSame('SUPPORT', $bubbles[count($bubbles) - 1]);
    }

    /** ส่งรอบเดียว (packTexts path) ก็ต้องได้บับเบิลแจ้งเพดานบน push สุดท้ายเหมือนกัน */
    public function test_single_round_delivery_still_shows_the_cap_notice(): void
    {
        $this->addReservedAccounts(9); // < split_from → รอบเดียว
        $this->capFirstStockItem(41);  // สั่ง 41 จองได้แค่ 9
        $pushes = $this->capturePushes();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        $this->assertCount(1, $pushes());
        $bubbles = $pushes()[0];
        // 9 บัญชี กับ 2 บับเบิลจองแล้ว (notice+support) → บัญชี 3 ก้อนก้อนละ 3
        $this->assertSame([3, 3, 3], self::accountsPerBubble([$bubbles[0], $bubbles[1], $bubbles[2]]));
        $this->assertSame('📦 ระบบส่งให้แล้ว 9 จาก 41 บัญชี (G3D) อีก 32 บัญชี ทีมงานกำลังส่งตามให้ในแชทนี้นะครับ', $bubbles[3]);
        $this->assertStringContainsString('lin.ee/sTD5TQL', $bubbles[4]);
    }

    /** ประวัติแชทต้องมีบรรทัดแจ้งเพดานครบทุกสินค้าที่โดนตัด (บอท AI ต้องรู้เท่าลูกค้า) */
    public function test_conversation_history_has_one_line_per_capped_product(): void
    {
        $this->addReservedAccounts(30);
        $this->capFirstStockItem(41);
        $this->seedAvailable(901, 'BM', 'uid901|pass901|mail|2fa');
        app(StockPoolService::class)->reserveOne('BM', '1');
        $this->delivery->items()->create([
            'product_name' => 'BM', 'stock_code' => 'BM', 'kind' => 'stock',
            'qty' => 1, 'stock_item_id' => 901, 'status' => 'reserved', 'requested_qty' => 5,
        ]);
        $this->mockLineOk();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        $msg = $this->conversation->messages()->latest('id')->first();
        $this->assertStringContainsString('ระบบส่งให้แล้ว 30 จาก 41 บัญชี (G3D)', $msg->content);
        $this->assertStringContainsString('ระบบส่งให้แล้ว 1 จาก 5 บัญชี (BM)', $msg->content);
    }
}
