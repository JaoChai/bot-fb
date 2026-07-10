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
use App\Services\LINEService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class DeliveryCallbackTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    private Bot $bot;

    private AccountDelivery $delivery;

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
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id, 'channel_type' => 'line',
            'external_customer_id' => 'Uabc123',
        ]);
        $slip = SlipVerification::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $conversation->id,
            'amount' => 1100, 'status' => 'passed',
        ]);

        $this->seedAvailable(10, 'NLMP', 'uid10|pass10');
        app(StockPoolService::class)->reserveOne('NLMP', '1');
        $this->delivery = AccountDelivery::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $conversation->id,
            'slip_verification_id' => $slip->id,
            'status' => AccountDelivery::STATUS_RESERVED, 'amount' => 1100,
        ]);
        $this->delivery->items()->create([
            'product_name' => 'Nolimit ส่วนตัว', 'stock_code' => 'NLMP', 'kind' => 'stock',
            'qty' => 1, 'stock_item_id' => 10, 'status' => 'reserved',
        ]);
    }

    private function press(string $data, int $fromId = 12345): TestResponse
    {
        config(['services.telegram_alert.secret' => 'SEC']);

        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SEC'])
            ->postJson('/api/webhook/telegram-alert/TOK', ['callback_query' => [
                'id' => 'cb1',
                'data' => $data,
                'from' => ['first_name' => 'บูม', 'id' => $fromId],
                'message' => ['message_id' => 55, 'chat' => ['id' => 999]],
            ]]);
    }

    private function setAuthorizedUsers(array $ids): void
    {
        $plugin = FlowPlugin::where('type', 'telegram')->firstOrFail();
        $plugin->update(['config' => array_merge($plugin->config, ['authorized_user_ids' => $ids])]);
    }

    public function test_dv_delivers(): void
    {
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->once()->andReturn(['method' => 'push', 'success' => true]);
        });

        $this->press("dv|{$this->delivery->id}|x")->assertOk();

        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText')
            && str_contains($r['text'] ?? '', 'ส่งให้ลูกค้าแล้ว'));
    }

    public function test_dv_appends_pending_manual_note_for_shortage(): void
    {
        // มี item ที่ shortage ปนอยู่ — ข้อความสำเร็จต้องเตือน "ยังต้องส่งเอง" ไม่ให้หาย
        $this->delivery->items()->create([
            'product_name' => 'เฟสไก่', 'kind' => 'stock', 'qty' => 1, 'status' => 'shortage',
        ]);
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->once()->andReturn(['method' => 'push', 'success' => true]);
        });

        $this->press("dv|{$this->delivery->id}|x")->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText')
            && str_contains($r['text'] ?? '', 'ยังต้องส่งเอง')
            && str_contains($r['text'] ?? '', 'เฟสไก่'));
    }

    public function test_dx_asks_second_step_without_touching_stock(): void
    {
        $this->press("dx|{$this->delivery->id}|x")->assertOk();

        $this->assertSame(AccountDelivery::STATUS_RESERVED, $this->delivery->fresh()->status);
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_reserved')->count());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText')
            && str_contains($r['reply_markup'] ?? '', "dz|{$this->delivery->id}|x"));
    }

    public function test_dz_cancels_and_returns_stock(): void
    {
        $this->press("dz|{$this->delivery->id}|x")->assertOk();

        $fresh = $this->delivery->fresh();
        $this->assertSame(AccountDelivery::STATUS_CANCELED, $fresh->status);
        $this->assertSame('returned', $fresh->items()->first()->status);
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_reserved')->count());
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_available')->count());
    }

    public function test_dz_with_stock_return_failure_shows_truthful_card(): void
    {
        // ทำให้คืนของพัง: ลบตาราง items_available ก่อนกด — insert ใน returnToAvailable จะ throw
        Schema::connection('mhha_acc')->drop('items_available');

        $this->press("dz|{$this->delivery->id}|x")->assertOk();

        $this->assertSame(AccountDelivery::STATUS_CANCELED, $this->delivery->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText')
            && str_contains($r['text'] ?? '', 'คืนของเข้า stock ไม่สำเร็จ'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'editMessageText')
            && str_contains($r['text'] ?? '', 'กดลองใหม่ได้'));
    }

    public function test_dv_after_delivered_reports_already_handled(): void
    {
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->once()->andReturn(['method' => 'push', 'success' => true]);
        });
        $this->press("dv|{$this->delivery->id}|x");

        $this->press("dv|{$this->delivery->id}|x")->assertOk();

        // ไม่ส่งซ้ำ (mock once ด้านบนพิสูจน์แล้ว) + แจ้งว่าจัดการไปแล้ว
        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText')
            && str_contains($r['text'] ?? '', 'จัดการไปแล้ว'));
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_sold')->count());
    }

    public function test_unauthorized_user_cannot_deliver(): void
    {
        $this->setAuthorizedUsers([777]); // 12345 (ผู้กด) ไม่อยู่ใน allowlist

        $this->press("dv|{$this->delivery->id}|x")->assertOk();

        // ไม่ถูกส่ง — งานยัง reserved
        $this->assertSame(AccountDelivery::STATUS_RESERVED, $this->delivery->fresh()->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'editMessageText')
            && str_contains($r['text'] ?? '', 'ส่งให้ลูกค้าแล้ว'));
    }

    public function test_authorized_user_can_deliver(): void
    {
        $this->setAuthorizedUsers([12345]); // ผู้กดอยู่ใน allowlist
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->once()->andReturn(['method' => 'push', 'success' => true]);
        });

        $this->press("dv|{$this->delivery->id}|x", 12345)->assertOk();

        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
    }

    public function test_delivery_of_other_bot_is_rejected(): void
    {
        $otherUser = User::factory()->owner()->create();
        $otherBot = Bot::factory()->create(['user_id' => $otherUser->id]);
        $this->delivery->update(['bot_id' => $otherBot->id]);

        $this->press("dv|{$this->delivery->id}|x")->assertOk();

        $this->assertSame(AccountDelivery::STATUS_RESERVED, $this->delivery->fresh()->status);
    }
}
