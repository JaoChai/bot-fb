<?php

namespace Tests\Feature;

use App\Exceptions\DeliveryAlreadyHandledException;
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

        // credential ดิบ + ลิงก์ support อยู่ในข้อความ
        $all = implode("\n", array_column($pushed, 'text'));
        $this->assertStringContainsString('uid10|pass10|mail|2fa', $all);
        $this->assertStringContainsString('lin.ee/sTD5TQL', $all);

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
