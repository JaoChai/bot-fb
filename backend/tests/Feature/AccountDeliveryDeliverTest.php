<?php

namespace Tests\Feature;

use App\Exceptions\DeliveryAlreadyHandledException;
use App\Jobs\MarkStockSold;
use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Delivery\AccountDeliveryService;
use App\Services\Delivery\StockPoolService;
use App\Services\LINEService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class AccountDeliveryDeliverTest extends TestCase
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

        // จองของไว้แล้ว 1 บัญชี + เพจ 1 รายการ
        $this->seedAvailable(10, 'NLMP', 'uid10|pass10|mail|2fa');
        app(StockPoolService::class)->reserveOne('NLMP', '1');
        $this->delivery = AccountDelivery::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id,
            'slip_verification_id' => $slip->id,
            'status' => AccountDelivery::STATUS_RESERVED, 'amount' => 1299,
        ]);
        $this->delivery->items()->create([
            'product_name' => 'Nolimit ส่วนตัว', 'stock_code' => 'NLMP', 'kind' => 'stock',
            'qty' => 1, 'stock_item_id' => 10, 'status' => 'reserved',
        ]);
        $this->delivery->items()->create([
            'product_name' => 'เพจ', 'kind' => 'support_link', 'qty' => 2, 'status' => 'reserved',
        ]);
    }

    public function test_deliver_pushes_credentials_and_marks_sold(): void
    {
        $pushed = [];
        $this->mock(LINEService::class, function (MockInterface $mock) use (&$pushed) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->once()
                ->withArgs(function ($bot, $token, $userId, $messages) use (&$pushed) {
                    $pushed = $messages;

                    return $userId === 'Uabc123' && $token === null;
                })->andReturn(['method' => 'push', 'success' => true]);
        });

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        // credential ดิบ + ข้อความเพจอยู่ในข้อความ (บัญชี+เพจ → ใช้ข้อความเพจ ไม่ใช่ข้อความบัญชี)
        $all = implode("\n", array_column($pushed, 'text'));
        $this->assertStringContainsString('uid10|pass10|mail|2fa', $all);
        $this->assertStringContainsString('lin.ee/sTD5TQL', $all);
        $this->assertStringContainsString('เพิ่มเพจให้ได้เลย', $all);
        $this->assertStringNotContainsString('ปัญหา ทางด้านบัญชี', $all);
        // ไม่มีชื่อลูกค้าในโปรไฟล์ → placeholder ต้องถูกตัดทิ้ง ไม่หลุดไปหาลูกค้า
        $this->assertStringNotContainsString('{customer}', $all);
        $this->assertStringContainsString('รบกวนพี่ แจ้งทีมงาน Support', $all);

        // ของย้ายเข้า items_sold
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_reserved')->count());
        $sold = DB::connection('mhha_acc')->table('items_sold')->first();
        $this->assertSame('บูม', $sold->first_name);

        // สถานะ + ประวัติแชท
        $fresh = $this->delivery->fresh();
        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $fresh->status);
        $this->assertSame('บูม', $fresh->confirmed_by);
        $this->assertSame(2, $fresh->items()->where('status', 'delivered')->count());
        $msg = $this->conversation->messages()->latest('id')->first();
        $this->assertTrue((bool) ($msg->metadata['account_delivery'] ?? false));
    }

    /** mock LINE push สำเร็จ 1 ครั้ง แล้วคืนข้อความทั้งหมดที่ส่งเป็น string เดียว (ผ่าน closure ที่ได้กลับไป) */
    private function captureLinePush(): \Closure
    {
        $pushed = [];
        $this->mock(LINEService::class, function (MockInterface $mock) use (&$pushed) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->once()
                ->andReturnUsing(function ($bot, $token, $userId, $messages) use (&$pushed) {
                    $pushed = $messages;

                    return ['method' => 'push', 'success' => true];
                });
        });

        // ต้อง use by-reference: อ่านค่าหลัง mock ถูกเรียก ไม่ใช่ snapshot ตอนสร้าง closure
        return function () use (&$pushed): string {
            return implode("\n", array_column($pushed, 'text'));
        };
    }

    public function test_page_message_uses_customer_display_name(): void
    {
        $profile = CustomerProfile::factory()->create(['display_name' => 'ไอซ์ มาวิน']);
        $this->conversation->update(['customer_profile_id' => $profile->id]);
        $pushedText = $this->captureLinePush();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        $all = $pushedText();
        $this->assertStringContainsString('รบกวนพี่ ไอซ์ มาวิน แจ้งทีมงาน Support', $all);
        $this->assertStringNotContainsString('{customer}', $all);
    }

    public function test_account_only_order_appends_account_support_message(): void
    {
        // ตัดรายการเพจออก → เหลือบัญชีล้วน ต้องปิดท้ายด้วยข้อความ Support เรื่องบัญชี
        $this->delivery->items()->where('kind', 'support_link')->delete();
        $pushedText = $this->captureLinePush();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        $all = $pushedText();
        $this->assertStringContainsString('uid10|pass10|mail|2fa', $all);
        $this->assertStringContainsString('ปัญหา ทางด้านบัญชี', $all);
        $this->assertStringContainsString('lin.ee/sTD5TQL', $all);
        // ไม่มีเพจ → ต้องไม่ส่งข้อความเพจ
        $this->assertStringNotContainsString('เพิ่มเพจให้ได้เลย', $all);
    }

    public function test_bm_and_ads_ids_are_appended_per_row_data(): void
    {
        // BM (มี bmId+adsId) → 2 บรรทัด, ส่วนตัว (มีแค่ adsId) → 1 บรรทัด — ตามข้อมูลจริงของแถว
        $pool = app(StockPoolService::class);
        $this->seedAvailable(20, 'NLMBM', 'uid20|pass20|mail|2fa', 'x', '1324077876319909', '1129452608578581');
        $pool->reserveOne('NLMBM', '1');
        $this->delivery->items()->create([
            'product_name' => 'Nolimit Share BM', 'stock_code' => 'NLMBM', 'kind' => 'stock',
            'qty' => 1, 'stock_item_id' => 20, 'status' => 'reserved',
        ]);
        $this->seedAvailable(21, 'NLMP', 'uid21|pass21|mail|2fa', 'x', null, '2071472379953388');
        $pool->reserveOne('NLMP', '1');
        $this->delivery->items()->create([
            'product_name' => 'Nolimit ส่วนตัว', 'stock_code' => 'NLMP', 'kind' => 'stock',
            'qty' => 1, 'stock_item_id' => 21, 'status' => 'reserved',
        ]);
        $pushedText = $this->captureLinePush();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        $all = $pushedText();
        $this->assertStringContainsString("uid20|pass20|mail|2fa\n\nBM ID: 1324077876319909\nAds ID: 1129452608578581", $all);
        $this->assertStringContainsString("uid21|pass21|mail|2fa\n\nAds ID: 2071472379953388", $all);
        // แถว setUp (id 10) ไม่มีทั้งคู่ → detail เดิมล้วน ไม่มีบรรทัด id งอกมา
        $this->assertStringContainsString('uid10|pass10|mail|2fa', $all);
        $this->assertSame(1, substr_count($all, 'BM ID:'));
        $this->assertSame(2, substr_count($all, 'Ads ID:'));
        // pin layout ใหม่: บรรทัดว่างคั่นชื่อสินค้า/ข้อมูลบัญชี และคั่นก่อนบล็อก id
        $this->assertStringContainsString("Nolimit Share BM (2/3)\n\nuid20|pass20|mail|2fa", $all);
        $this->assertStringContainsString("Nolimit ส่วนตัว (3/3)\n\nuid21|pass21|mail|2fa", $all);
    }

    public function test_recorded_chat_message_never_contains_raw_credential(): void
    {
        // credential ต้องไปถึง LINE เท่านั้น — ห้ามถูกเก็บใน messages.content
        // (content ถูกดึงกลับเข้า LLM context + surface บนหน้าเว็บ)
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->once()->andReturn(['method' => 'push', 'success' => true]);
        });

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        $msg = $this->conversation->messages()->latest('id')->first();
        // ไม่มี credential ดิบใน content
        $this->assertStringNotContainsString('uid10|pass10|mail|2fa', $msg->content);
        // แต่ยัง traceable: ชื่อสินค้า + stock item id
        $this->assertStringContainsString('Nolimit ส่วนตัว', $msg->content);
        $this->assertStringContainsString('#10', $msg->content);
        // รวม placeholder ของ support_link ด้วย (branch KIND_SUPPORT_LINK)
        $this->assertStringContainsString('ส่งลิงก์ Support', $msg->content);
        $this->assertStringContainsString('เพจ', $msg->content);
        $this->assertTrue((bool) ($msg->metadata['account_delivery'] ?? false));
    }

    public function test_deliver_twice_throws_already_handled(): void
    {
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->once()->andReturn(['method' => 'push', 'success' => true]);
        });
        $service = app(AccountDeliveryService::class);
        $service->deliver($this->delivery, 'บูม');

        $this->expectException(DeliveryAlreadyHandledException::class);
        $service->deliver($this->delivery->fresh(), 'บูม');
    }

    public function test_many_items_are_packed_into_single_push(): void
    {
        // เพิ่มอีก 6 บัญชี (รวมกับของ setUp เป็น 7 + เพจ 1) — โค้ดแบบ chunk เดิมจะยิง 2 push
        $pool = app(StockPoolService::class);
        foreach (range(11, 16) as $id) {
            $this->seedAvailable($id, 'NLMP', "uid{$id}|pass{$id}|mail|2fa");
            $pool->reserveOne('NLMP', '1');
            $this->delivery->items()->create([
                'product_name' => 'Nolimit ส่วนตัว', 'stock_code' => 'NLMP', 'kind' => 'stock',
                'qty' => 1, 'stock_item_id' => $id, 'status' => 'reserved',
            ]);
        }

        $calls = 0;
        $pushed = [];
        $this->mock(LINEService::class, function (MockInterface $mock) use (&$calls, &$pushed) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')
                ->andReturnUsing(function ($bot, $token, $userId, $messages) use (&$calls, &$pushed) {
                    $calls++;
                    $pushed = array_merge($pushed, $messages);

                    return ['method' => 'push', 'success' => true];
                });
        });

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        // all-or-nothing: push เดียวเท่านั้น และไม่เกิน 5 ข้อความ
        $this->assertSame(1, $calls);
        $this->assertLessThanOrEqual(5, count($pushed));

        // credential ครบทั้ง 7 + ลิงก์ support
        $all = implode("\n", array_column($pushed, 'text'));
        foreach (range(10, 16) as $id) {
            $this->assertStringContainsString("uid{$id}|pass{$id}|mail|2fa", $all);
        }
        $this->assertStringContainsString('lin.ee/sTD5TQL', $all);

        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
        $this->assertSame(7, DB::connection('mhha_acc')->table('items_sold')->count());
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_reserved')->count());
    }

    public function test_marksold_failure_dispatches_retry_job_but_still_delivers(): void
    {
        // markSold พังหลัง push สำเร็จ (ลูกค้าได้ของแล้ว) → ห้าม throw กลับ, ต้อง dispatch
        // job ตามเก็บ ไม่ปล่อยของค้าง items_reserved เงียบๆ
        Bus::fake([MarkStockSold::class]);
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->once()->andReturn(['method' => 'push', 'success' => true]);
        });
        $this->mock(StockPoolService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getReserved')->andReturn([
                10 => ['id' => 10, 'detail' => 'uid10|pass10|mail|2fa'],
            ]);
            $mock->shouldReceive('markSold')->andThrow(new \RuntimeException('mhha down'));
        });

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
        Bus::assertDispatched(MarkStockSold::class, fn (MarkStockSold $job) => $job->stockItemIds === [10]);
    }

    public function test_marksold_dispatch_failure_still_marks_delivered(): void
    {
        // queue backend ล่มตอน dispatch job ตามเก็บ (ลูกค้าได้ของไปแล้ว) — deliver ต้องจบ
        // DELIVERED เสมอ ไม่ค้าง DELIVERING จน callback โชว์ปุ่ม "กดลองใหม่" ที่หลอกให้ส่งซ้ำ
        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('queue down'));
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->once()->andReturn(['method' => 'push', 'success' => true]);
        });
        $this->mock(StockPoolService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getReserved')->andReturn([
                10 => ['id' => 10, 'detail' => 'uid10|pass10|mail|2fa'],
            ]);
            $mock->shouldReceive('markSold')->andThrow(new \RuntimeException('mhha down'));
        });

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
    }

    public function test_account_and_support_are_separate_bubbles(): void
    {
        $pushed = [];
        $this->mock(LINEService::class, function (MockInterface $mock) use (&$pushed) {
            $mock->shouldReceive('generateRetryKey')->andReturn('k');
            $mock->shouldReceive('replyWithFallback')->once()
                ->withArgs(function ($bot, $token, $userId, $messages) use (&$pushed) {
                    $pushed = $messages;

                    return true;
                });
        });

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        $texts = array_column($pushed, 'text');
        // มีอย่างน้อย 1 bubble ที่เป็น "บัญชี" (มี credential) และ 1 bubble ที่เป็น support ล้วน
        $accountBubbles = array_filter($texts, fn ($t) => str_contains($t, 'uid10|pass10'));
        $supportBubbles = array_filter($texts, fn ($t) => str_contains($t, 'lin.ee/sTD5TQL'));
        $this->assertNotEmpty($accountBubbles);
        $this->assertNotEmpty($supportBubbles);
        // support ต้องไม่ปนอยู่ bubble เดียวกับ credential
        foreach ($accountBubbles as $b) {
            $this->assertStringNotContainsString('lin.ee/sTD5TQL', $b);
        }
    }

    /** เพิ่มบัญชีจองเข้า delivery จนรวม (กับ id 10 ของ setUp) ได้ $total บัญชี: id 10..(9+$total) */
    private function addReservedAccounts(int $total): void
    {
        $pool = app(StockPoolService::class);
        foreach (range(11, 9 + $total) as $id) {
            $this->seedAvailable($id, 'NLMP', "uid{$id}|pass{$id}|mail|2fa");
            $pool->reserveOne('NLMP', '1');
            $this->delivery->items()->create([
                'product_name' => 'Nolimit ส่วนตัว', 'stock_code' => 'NLMP', 'kind' => 'stock',
                'qty' => 1, 'stock_item_id' => $id, 'status' => 'reserved',
            ]);
        }
    }

    /**
     * mock LINE: เก็บข้อความแต่ละ push แยกกัน (คืน closure ที่ให้ array ของ string ต่อ push)
     * $failOnCall = push ครั้งที่เท่าไรให้พัง (null = ไม่พัง)
     */
    private function capturePushes(?int $failOnCall = null): \Closure
    {
        $pushes = [];
        $this->mock(LINEService::class, function (MockInterface $mock) use (&$pushes, $failOnCall) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')
                ->andReturnUsing(function ($bot, $token, $userId, $messages) use (&$pushes, $failOnCall) {
                    if ($failOnCall !== null && count($pushes) + 1 === $failOnCall) {
                        throw new \RuntimeException('LINE down');
                    }
                    $pushes[] = implode("\n", array_column($messages, 'text'));

                    return ['method' => 'push', 'success' => true];
                });
        });

        return function () use (&$pushes): array {
            return $pushes;
        };
    }

    public function test_nine_accounts_still_go_in_a_single_push(): void
    {
        $this->addReservedAccounts(9);
        $pushes = $this->capturePushes();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        $this->assertCount(1, $pushes());
        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
    }

    public function test_ten_accounts_are_split_into_two_pushes_of_five(): void
    {
        $this->addReservedAccounts(10);
        $pushes = $this->capturePushes();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        [$first, $second] = $pushes();
        $this->assertCount(2, $pushes());
        foreach (range(10, 14) as $id) {
            $this->assertStringContainsString("uid{$id}|pass{$id}", $first);
            $this->assertStringNotContainsString("uid{$id}|pass{$id}", $second);
        }
        foreach (range(15, 19) as $id) {
            $this->assertStringContainsString("uid{$id}|pass{$id}", $second);
            $this->assertStringNotContainsString("uid{$id}|pass{$id}", $first);
        }
        // เลขลำดับนับต่อเนื่องทั้งออเดอร์
        $this->assertStringContainsString('(1/10)', $first);
        $this->assertStringContainsString('(10/10)', $second);
        // support อยู่ท้ายรอบสองเท่านั้น
        $this->assertStringNotContainsString('lin.ee/sTD5TQL', $first);
        $this->assertStringContainsString('lin.ee/sTD5TQL', $second);

        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
        $this->assertSame(10, DB::connection('mhha_acc')->table('items_sold')->count());
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_reserved')->count());
    }

    public function test_odd_count_gives_the_first_push_the_extra_account(): void
    {
        $this->addReservedAccounts(15);
        $pushes = $this->capturePushes();

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        [$first, $second] = $pushes();
        $this->assertSame(8, substr_count($first, '|mail|2fa'));
        $this->assertSame(7, substr_count($second, '|mail|2fa'));
    }

    public function test_second_push_failure_keeps_first_half_sold_and_retry_sends_only_the_rest(): void
    {
        $this->addReservedAccounts(10);
        $this->capturePushes(failOnCall: 2);

        try {
            app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        // รอบแรกถึงลูกค้าแล้ว → ขายแล้ว ห้ามกลับเข้า stock; รอบสองยังจองไว้ กดส่งใหม่ได้
        $fresh = $this->delivery->fresh();
        $this->assertSame(AccountDelivery::STATUS_RESERVED, $fresh->status);
        $this->assertSame(5, $fresh->items()->where('kind', 'stock')->where('status', 'delivered')->count());
        $this->assertSame(5, DB::connection('mhha_acc')->table('items_sold')->count());
        $this->assertSame(5, DB::connection('mhha_acc')->table('items_reserved')->count());

        $pushes = $this->capturePushes();
        app(AccountDeliveryService::class)->deliver($fresh, 'บูม');

        $this->assertCount(1, $pushes());
        $retry = $pushes()[0];
        $this->assertStringNotContainsString('uid10|pass10', $retry);
        foreach (range(15, 19) as $id) {
            $this->assertStringContainsString("uid{$id}|pass{$id}", $retry);
        }
        $this->assertStringContainsString('(6/10)', $retry);
        $this->assertStringContainsString('lin.ee/sTD5TQL', $retry);
        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
        $this->assertSame(10, DB::connection('mhha_acc')->table('items_sold')->count());
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_reserved')->count());
    }

    public function test_bookkeeping_failure_after_push_never_reverts_to_reserved(): void
    {
        // push ถึงลูกค้าแล้ว แต่บันทึกสถานะ item พัง (DB ล่ม) — ห้ามย้อนเป็น reserved
        // (reserved = ปุ่มยกเลิกคืน stock ได้ → บัญชีที่ลูกค้าได้ไปแล้วถูกขายซ้ำ) ต้องค้าง delivering ให้ reconcile เตือน
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->once()->andReturnUsing(function () {
                Schema::rename('account_delivery_items', 'account_delivery_items_gone');

                return ['method' => 'push', 'success' => true];
            });
        });

        $threw = false;
        try {
            app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertSame(AccountDelivery::STATUS_DELIVERING, $this->delivery->fresh()->status);
    }

    public function test_cancel_after_partial_delivery_returns_only_undelivered_accounts(): void
    {
        $this->addReservedAccounts(10);
        $this->capturePushes(failOnCall: 2);
        try {
            app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');
        } catch (\RuntimeException) {
        }

        app(AccountDeliveryService::class)->cancel($this->delivery->fresh(), 'บูม');

        // id ปลายทางถูก gen ใหม่ (IDENTITY) — เทียบด้วย detail แทน
        $available = DB::connection('mhha_acc')->table('items_available')->pluck('detail')->sort()->values()->all();
        $this->assertSame(array_map(fn ($id) => "uid{$id}|pass{$id}|mail|2fa", range(15, 19)), $available);
        $this->assertSame(5, DB::connection('mhha_acc')->table('items_sold')->count());
    }

    public function test_card_shows_partial_progress(): void
    {
        $this->addReservedAccounts(10);
        $this->capturePushes(failOnCall: 2);
        try {
            app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');
        } catch (\RuntimeException) {
        }

        $service = app(AccountDeliveryService::class);
        $fresh = $this->delivery->fresh();
        $this->assertStringContainsString('ส่งแล้ว 5/10 บัญชี เหลือ 5', $service->partialNote($fresh));
        $this->assertStringContainsString('ส่งแล้ว 5/10 บัญชี เหลือ 5', $service->cardTextForTesting($fresh));
        // งานที่ยังไม่เริ่มส่ง ไม่มีบรรทัดนี้
        $this->assertSame('', $service->partialNote(AccountDelivery::make()));
    }

    public function test_line_failure_keeps_stock_reserved(): void
    {
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->andThrow(new \RuntimeException('LINE down'));
        });

        try {
            app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        $this->assertSame(AccountDelivery::STATUS_RESERVED, $this->delivery->fresh()->status);
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_reserved')->count());
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_sold')->count());
    }
}
