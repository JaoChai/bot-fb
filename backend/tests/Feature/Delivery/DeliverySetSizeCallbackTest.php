<?php

namespace Tests\Feature\Delivery;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Delivery\StockPoolService;
use App\Services\LINEService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

/**
 * callback dv|{id}|{setSize} — ค่าที่ 3 ต้องไปถึง deliver() แบบ validated:
 * 'x' = พฤติกรรมเดิม (แบ่งครึ่ง), เลขใน [1, delivery.max_qty] = ขนาดชุด,
 * อย่างอื่น (abc / 999 เกินเพดาน) = ละเชิง log warning + แบ่งครึ่ง ห้ามใช้ค่าจาก client ดิบๆ
 */
class DeliverySetSizeCallbackTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    private AccountDelivery $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockPool();
        config(['services.telegram_alert.secret' => 'SEC', 'delivery.max_qty' => 30]);
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
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id, 'channel_type' => 'line',
            'external_customer_id' => 'Uabc123',
        ]);
        $slip = SlipVerification::create([
            'bot_id' => $bot->id, 'conversation_id' => $conversation->id,
            'amount' => 1100, 'status' => 'passed',
        ]);

        $pool = app(StockPoolService::class);
        $this->seedAvailable(10, 'NLMP', 'uid10|pass10|mail|2fa');
        $pool->reserveOne('NLMP', '1');
        $this->delivery = AccountDelivery::create([
            'bot_id' => $bot->id, 'conversation_id' => $conversation->id,
            'slip_verification_id' => $slip->id,
            'status' => AccountDelivery::STATUS_RESERVED, 'amount' => 1100,
        ]);
        $this->delivery->items()->create([
            'product_name' => 'Nolimit ส่วนตัว', 'stock_code' => 'NLMP', 'kind' => 'stock',
            'qty' => 1, 'stock_item_id' => 10, 'status' => 'reserved',
        ]);
    }

    private function press(string $data, int $fromId = 12345, string $fromName = 'บูม'): TestResponse
    {
        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SEC'])
            ->postJson('/api/webhook/telegram-alert/TOK', ['callback_query' => [
                'id' => 'cb1',
                'data' => $data,
                'from' => ['first_name' => $fromName, 'id' => $fromId],
                'message' => ['message_id' => 55, 'chat' => ['id' => 999]],
            ]]);
    }

    private function mockLinePush(): void
    {
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->andReturn(['method' => 'push', 'success' => true]);
        });
    }

    /** เพิ่มบัญชีจองเข้า delivery จนรวมกับของ setUp เป็น $total บัญชี (setUp มี 1) */
    private function addReservedAccounts(int $total): void
    {
        $pool = app(StockPoolService::class);
        foreach (range(11, 10 + $total) as $id) {
            $this->seedAvailable($id, 'NLMP', "uid{$id}|pass{$id}|mail|2fa");
            $pool->reserveOne('NLMP', '1');
            $this->delivery->items()->create([
                'product_name' => 'Nolimit ส่วนตัว', 'stock_code' => 'NLMP', 'kind' => 'stock',
                'qty' => 1, 'stock_item_id' => $id, 'status' => 'reserved',
            ]);
        }
    }

    public function test_callback_with_plain_x_stays_legacy_without_warning(): void
    {
        $this->mockLinePush();
        Log::spy();

        $this->press("dv|{$this->delivery->id}|x")->assertOk();

        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_callback_with_numeric_size_uses_it_as_set_size(): void
    {
        // setSize=1 บนงาน 1 บัญชี: ต้องส่งสำเร็จเป็นรอบเดียวแบบไม่มีเตือน (ค่า valid เข้าถึง deliver())
        $this->mockLinePush();
        Log::spy();

        $this->press("dv|{$this->delivery->id}|1")->assertOk();

        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_callback_with_non_numeric_size_falls_back_and_logs_warning(): void
    {
        $this->addReservedAccounts(9); // รวม setUp = 10 บัญชี ≥ split_from → แบ่งครึ่ง 5+5
        $this->mockLinePush();
        Log::spy();

        $this->press("dv|{$this->delivery->id}|abc")->assertOk();

        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_callback_with_size_over_max_qty_falls_back_and_logs_warning(): void
    {
        $this->addReservedAccounts(9);
        $this->mockLinePush();
        Log::spy();

        $this->press("dv|{$this->delivery->id}|999")->assertOk();

        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'setSize')
                && ($context['raw'] ?? null) === 999)
            ->once();
    }

    public function test_callback_with_zero_size_falls_back_and_logs_warning(): void
    {
        $this->addReservedAccounts(9);
        $this->mockLinePush();
        Log::spy();

        $this->press("dv|{$this->delivery->id}|0")->assertOk();

        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'setSize')
                && ($context['raw'] ?? null) === 0)
            ->once();
    }

    /** การ์ดหลังส่งต้องบอกขนาดชุดที่ใช้จริง (จำนวนรอบจริงจากการส่ง ไม่ใช่เดา) */
    public function test_delivered_card_edit_states_the_set_size_used(): void
    {
        $this->addReservedAccounts(19); // setUp มี 1 → รวม 20 บัญชี ชุดละ 8 → 3 รอบ
        $this->mockLinePush();

        $this->press("dv|{$this->delivery->id}|8")->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'editMessageText')
                && str_contains($request['text'] ?? '', 'ส่งแล้ว · ชุดละ 8 (3 ชุด)');
        });
    }

    /** แบ่งครึ่ง (กด x) การ์ดต้องบอกว่าแบ่งครึ่ง 2 ชุด */
    public function test_delivered_card_edit_states_half_split_when_pressed_x(): void
    {
        $this->addReservedAccounts(19); // 20 บัญชี → แบ่งครึ่ง 10+10
        $this->mockLinePush();

        $this->press("dv|{$this->delivery->id}|x")->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'editMessageText')
                && str_contains($request['text'] ?? '', 'ส่งแล้ว · แบ่งครึ่ง (2 ชุด)');
        });
    }

    /** งานรอบเดียวจบ (N < split_from) ต้องบอก "1 ชุด" ไม่ใช่ "แบ่งครึ่ง (2 ชุด)" เหมือนโค้ดเดิม */
    public function test_delivered_card_edit_states_single_round_without_halving(): void
    {
        $this->mockLinePush(); // setUp มี 1 บัญชี → รอบเดียว

        $this->press("dv|{$this->delivery->id}|x")->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'editMessageText')
                && str_contains($request['text'] ?? '', 'ส่งแล้ว · 1 ชุด')
                && ! str_contains($request['text'] ?? '', 'แบ่งครึ่ง');
        });
    }
}
