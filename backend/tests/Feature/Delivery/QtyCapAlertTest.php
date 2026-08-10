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
 * qty ที่เกินเพดาน (delivery.max_qty) ต้องปรากฏบนการ์ด Telegram —
 * log อย่างเดียวไม่พอ เพราะ LOG_LEVEL บน prod กลืน warning ทิ้ง
 */
class QtyCapAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_text_shows_capped_quantity_warning(): void
    {
        config(['delivery.enabled' => true, 'delivery.max_qty' => 20]);

        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'auto_delivery_enabled' => true]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'channel_type' => 'line']);

        $slip = SlipVerification::create([
            'bot_id' => $bot->id, 'conversation_id' => $conversation->id,
            'amount' => 1500, 'status' => 'passed',
        ]);

        $delivery = AccountDelivery::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'slip_verification_id' => $slip->id,
            'status' => AccountDelivery::STATUS_RESERVED,
            'amount' => 1500,
        ]);
        $delivery->items()->create([
            'product_name' => 'G3D',
            'stock_code' => 'G3D',
            'kind' => AccountDeliveryItem::KIND_STOCK,
            'qty' => 1,
            'status' => AccountDeliveryItem::ST_RESERVED,
            'requested_qty' => 30,
        ]);

        $text = app(AccountDeliveryService::class)->cardTextForTesting($delivery);

        $this->assertStringContainsString('30', $text);
        $this->assertStringContainsString('เกินเพดาน', $text);
    }
}
