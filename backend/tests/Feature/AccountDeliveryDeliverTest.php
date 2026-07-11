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
