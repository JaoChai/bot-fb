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
 * คำเตือน "ลูกค้าสั่ง X แต่จองได้ Y (เกินเพดาน)" ต้องอยู่บรรทัดแรกของการ์ด
 * — เดิมอยู่ล่าง customer/amount (บรรทัด 4) แอดมินกดปุ่มไล่จากบนลงล่างไม่ทันเห็น (prod #507)
 */
class AccountDeliveryCardLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_cap_warning_is_the_first_line_of_the_card(): void
    {
        config(['delivery.enabled' => true, 'delivery.max_qty' => 30]);

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
        $delivery->items()->create([
            'product_name' => 'G3D', 'stock_code' => 'G3D', 'kind' => AccountDeliveryItem::KIND_STOCK,
            'qty' => 1, 'status' => AccountDeliveryItem::ST_RESERVED, 'requested_qty' => 41,
        ]);

        $text = app(AccountDeliveryService::class)->cardTextForTesting($delivery);
        $firstLine = strtok($text, "\n");

        $this->assertStringContainsString('เกินเพดาน', $firstLine);
        $this->assertStringContainsString('41', $firstLine);
    }
}
