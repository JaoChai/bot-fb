<?php

namespace Tests\Feature\Delivery;

use App\Models\AccountDelivery;
use App\Models\AccountDeliveryItem;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Delivery\AccountDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * การ์ดงานที่จอง ≥ delivery.split_from ต้องให้แอดมินเลือกขนาดชุดได้บนการ์ดเลย
 * (เคส prod #507: การ์ดขึ้น 8 วิ แอดมินกด ✅ ระบบหัวครึ่งไปเอง ทั้งที่ลูกค้าขอ "ชุดละ 20")
 */
class AccountDeliveryKeyboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeDelivery(int $stockCount): AccountDelivery
    {
        config(['delivery.split_from' => 10, 'delivery.max_qty' => 30]);

        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'auto_delivery_enabled' => true]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'channel_type' => 'line']);
        $slip = SlipVerification::create([
            'bot_id' => $bot->id, 'conversation_id' => $conversation->id,
            'amount' => 1500, 'status' => 'passed',
        ]);
        $delivery = AccountDelivery::create([
            'bot_id' => $bot->id, 'conversation_id' => $conversation->id,
            'slip_verification_id' => $slip->id,
            'status' => AccountDelivery::STATUS_RESERVED, 'amount' => 1500,
        ]);
        foreach (range(1, $stockCount) as $i) {
            $delivery->items()->create([
                'product_name' => 'G3D', 'stock_code' => 'G3D',
                'kind' => AccountDeliveryItem::KIND_STOCK, 'qty' => 1,
                'stock_item_id' => 100 + $i, 'status' => AccountDeliveryItem::ST_RESERVED,
            ]);
        }

        return $delivery;
    }

    public function test_keyboard_for_thirty_accounts_offers_half_set_sizes_and_cancel(): void
    {
        $delivery = $this->makeDelivery(30);

        $keyboard = app(AccountDeliveryService::class)->cardKeyboard($delivery);

        $this->assertCount(3, $keyboard);
        $this->assertSame([['text' => '✅ แบ่งครึ่ง', 'callback_data' => "dv|{$delivery->id}|x"]], $keyboard[0]);
        $this->assertSame([
            ['text' => 'ชุดละ 10', 'callback_data' => "dv|{$delivery->id}|10"],
            ['text' => 'ชุดละ 15', 'callback_data' => "dv|{$delivery->id}|15"],
            ['text' => 'ชุดละ 20', 'callback_data' => "dv|{$delivery->id}|20"],
        ], $keyboard[1]);
        $this->assertSame([['text' => '↩️ ยกเลิก คืนเข้า stock', 'callback_data' => "dx|{$delivery->id}|x"]], $keyboard[2]);
    }

    public function test_keyboard_below_split_from_stays_exactly_as_today(): void
    {
        $delivery = $this->makeDelivery(9);

        $keyboard = app(AccountDeliveryService::class)->cardKeyboard($delivery);

        $this->assertSame([
            [['text' => '✅ ส่งให้ลูกค้าเลย', 'callback_data' => "dv|{$delivery->id}|x"]],
            [['text' => '↩️ ยกเลิก คืนเข้า stock', 'callback_data' => "dx|{$delivery->id}|x"]],
        ], $keyboard);
    }

    public function test_keyboard_when_no_size_button_fits_stays_legacy(): void
    {
        // N = 10: ปุ่มชุดละ 10/15/20 ไม่มีปุ่มไหน < N เลย → ไม่มีแถวขนาด คงปุ่มเดิม (แถวปุ่มว่าง Telegram ไม่รับ)
        $delivery = $this->makeDelivery(10);

        $keyboard = app(AccountDeliveryService::class)->cardKeyboard($delivery);

        $this->assertSame([
            [['text' => '✅ ส่งให้ลูกค้าเลย', 'callback_data' => "dv|{$delivery->id}|x"]],
            [['text' => '↩️ ยกเลิก คืนเข้า stock', 'callback_data' => "dx|{$delivery->id}|x"]],
        ], $keyboard);
    }

    public function test_keyboard_counts_only_remaining_reserved_accounts(): void
    {
        // การ์ด retry: ส่งไปแล้ว 10 เหลือ 5 — ปุ่มขนาดชุดต้องดูจาก "ที่เหลือ" (5 < split_from → ปุ่มเดิม)
        $delivery = $this->makeDelivery(15);
        $delivery->items()->where('stock_item_id', '<=', 110)->update(['status' => AccountDeliveryItem::ST_DELIVERED]);

        $keyboard = app(AccountDeliveryService::class)->cardKeyboard($delivery);

        $this->assertSame([
            [['text' => '✅ ส่งให้ลูกค้าเลย', 'callback_data' => "dv|{$delivery->id}|x"]],
            [['text' => '↩️ ยกเลิก คืนเข้า stock', 'callback_data' => "dx|{$delivery->id}|x"]],
        ], $keyboard);
    }
}
