<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\VipPriceGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VipPriceGuardServiceTest extends TestCase
{
    use RefreshDatabase;

    private VipPriceGuardService $guard;

    private Conversation $vipConversation;

    protected function setUp(): void
    {
        parent::setUp();

        ProductStock::create([
            'name' => 'Nolimit Level Up+ Personal',
            'slug' => 'personal',
            'stock_code' => 'NLMP',
            'aliases' => ['Personal', 'BM'],
            'in_stock' => true,
            'display_order' => 1,
            'price' => 1100,
            'vip_price' => 1000,
        ]);

        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $this->vipConversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'memory_notes' => [[
                'type' => 'memory',
                'source' => 'vip_auto',
                'content' => 'ซื้อยืนยันแล้ว 3 ครั้ง',
            ]],
        ]);
        $this->guard = app(VipPriceGuardService::class);
    }

    public function test_replaces_stale_normal_price_before_customer_sees_it(): void
    {
        $result = $this->guard->enforce(
            "สรุปรายการสั่งซื้อ\n1. Nolimit Level Up+ Personal 1,100 บาท\nรวม: 1,100 บาท\nพิมพ์ ยืนยัน",
            null,
            $this->vipConversation
        );

        $this->assertTrue($result['corrected']);
        $this->assertStringContainsString('มอบสิทธิ VIP', $result['content']);
        $this->assertStringContainsString('1,000 บาท/ตัว', $result['content']);
        $this->assertNull($result['order_payload']);
    }

    public function test_allows_canonical_vip_price(): void
    {
        $content = "สรุปรายการสั่งซื้อ\n1. Nolimit Level Up+ Personal 1,000 บาท\nรวม: 1,000 บาท\nพิมพ์ ยืนยัน";
        $payload = [
            'items' => [['name' => 'Nolimit Level Up+ Personal', 'qty' => 1, 'total' => '1000']],
            'total' => 1000,
        ];

        $result = $this->guard->enforce($content, $payload, $this->vipConversation);

        $this->assertFalse($result['corrected']);
        $this->assertSame($content, $result['content']);
        $this->assertSame($payload, $result['order_payload']);
    }

    public function test_rejects_stale_hidden_order_payload_even_when_visible_text_has_no_price(): void
    {
        $result = $this->guard->enforce(
            'ตรวจสอบรายการให้แล้วครับ',
            [
                'items' => [['name' => 'Nolimit Level Up+ Personal', 'qty' => 1, 'total' => '1100']],
                'total' => 1100,
            ],
            $this->vipConversation
        );

        $this->assertTrue($result['corrected']);
        $this->assertNull($result['order_payload']);
    }

    public function test_allows_normal_price_for_non_vip(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->vipConversation->bot_id,
            'memory_notes' => [],
        ]);
        $content = 'Nolimit Level Up+ Personal ราคา 1,100 บาท';

        $result = $this->guard->enforce($content, null, $conversation);

        $this->assertFalse($result['corrected']);
        $this->assertSame($content, $result['content']);
    }

    public function test_rejects_vip_price_for_non_vip(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->vipConversation->bot_id,
            'memory_notes' => [],
        ]);

        $result = $this->guard->enforce(
            "สรุปรายการสั่งซื้อ\n1. Nolimit Level Up+ Personal 1,000 บาท\nรวม: 1,000 บาท",
            null,
            $conversation
        );

        $this->assertTrue($result['corrected']);
        $this->assertStringContainsString('ราคาปกติ', $result['content']);
        $this->assertStringContainsString('1,100 บาท/ตัว', $result['content']);
    }

    public function test_rejects_grand_total_that_disagrees_with_canonical_items(): void
    {
        $result = $this->guard->enforce(
            "สรุปรายการสั่งซื้อ\n1. Nolimit Level Up+ Personal 1,000 บาท\nรวมยอดโอน: 1,100 บาท\n223-3-24880-3",
            null,
            $this->vipConversation
        );

        $this->assertTrue($result['corrected']);
    }

    public function test_rejects_other_incorrect_informational_price_formats(): void
    {
        foreach (['900 บาท', '900฿', '900.-'] as $wrongPrice) {
            $result = $this->guard->enforce(
                "Nolimit Level Up+ Personal ราคา {$wrongPrice}",
                null,
                $this->vipConversation
            );

            $this->assertTrue($result['corrected'], "Expected {$wrongPrice} to be rejected");
        }
    }

    public function test_allows_non_vip_limit_amount_on_product_line(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->vipConversation->bot_id,
            'memory_notes' => [],
        ]);
        $content = 'BM 5 ตัวครับ ขอแจ้งก่อนนะครับ Limit เริ่มต้นที่ 1,600 บาท';

        $result = $this->guard->enforce($content, null, $conversation);

        $this->assertFalse($result['corrected']);
        $this->assertSame($content, $result['content']);
    }

    public function test_allows_price_of_another_mentioned_product(): void
    {
        ProductStock::create([
            'name' => 'Page',
            'slug' => 'page',
            'stock_code' => 'PAGE',
            'aliases' => [],
            'in_stock' => true,
            'display_order' => 2,
            'price' => 199,
            'vip_price' => null,
        ]);
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->vipConversation->bot_id,
            'memory_notes' => [],
        ]);
        $content = 'Nolimit BM 1 ตัว กับ Page 199 บาทครับ';

        $result = $this->guard->enforce($content, null, $conversation);

        $this->assertFalse($result['corrected']);
        $this->assertSame($content, $result['content']);
    }

    public function test_still_rejects_incorrect_non_vip_price(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->vipConversation->bot_id,
            'memory_notes' => [],
        ]);
        $result = $this->guard->enforce('Nolimit BM ราคา 1,600 บาทครับ', null, $conversation);

        $this->assertTrue($result['corrected']);
    }

    public function test_vip_still_rejects_normal_price_with_limit_on_same_line(): void
    {
        $result = $this->guard->enforce(
            'BM ราคา 1,100 บาท Limit เริ่มต้น 1,600 บาท',
            null,
            $this->vipConversation
        );

        $this->assertTrue($result['corrected']);
    }

    public function test_allows_vip_price_with_limit_on_same_line(): void
    {
        $content = 'BM 2 ตัว ราคา 1,000 บาท Limit 1,600 บาท';
        $result = $this->guard->enforce($content, null, $this->vipConversation);

        $this->assertFalse($result['corrected']);
        $this->assertSame($content, $result['content']);
    }

    public function test_allows_visible_normal_and_vip_comparison(): void
    {
        $content = 'Nolimit Level Up+ Personal ราคาปกติ 1,100 บาท มอบสิทธิ VIP เหลือ 1,000 บาท';

        $result = $this->guard->enforce($content, null, $this->vipConversation);

        $this->assertFalse($result['corrected']);
        $this->assertSame($content, $result['content']);
    }
}
