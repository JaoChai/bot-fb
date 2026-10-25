<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\VipPriceGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real production false positives (Railway logs, conv 1122/1754/1755/1764) that
 * the guard must let through, and the true positive (conv 634) it must keep
 * catching. Prices: NLMP/NLMBM 1,100 normal / 1,000 VIP; Page 199; G3D 50.
 * 1,600 บาท is the ad-account spending LIMIT, not a product price.
 */
class VipPriceGuardFalsePositiveTest extends TestCase
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
            'aliases' => ['Personal'],
            'in_stock' => true,
            'display_order' => 1,
            'price' => 1100,
            'vip_price' => 1000,
        ]);
        ProductStock::create([
            'name' => 'Nolimit Level Up+ BM',
            'slug' => 'bm',
            'stock_code' => 'NLMBM',
            'aliases' => ['BM'],
            'in_stock' => true,
            'display_order' => 2,
            'price' => 1100,
            'vip_price' => 1000,
        ]);
        ProductStock::create([
            'name' => 'Page',
            'slug' => 'page',
            'stock_code' => 'PAGE',
            'aliases' => ['เพจ'],
            'in_stock' => true,
            'display_order' => 3,
            'price' => 199,
            'vip_price' => null,
        ]);
        ProductStock::create([
            'name' => 'G3D',
            'slug' => 'g3d',
            'stock_code' => 'G3D',
            'aliases' => [],
            'in_stock' => true,
            'display_order' => 4,
            'price' => 50,
            'vip_price' => null,
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

    private function nonVipConversation(): Conversation
    {
        return Conversation::factory()->create([
            'bot_id' => $this->vipConversation->bot_id,
            'memory_notes' => [],
        ]);
    }

    /** A1 — limit keyword ~31 chars before the amount; the reply was correct. */
    public function test_limit_amount_beyond_old_25char_window_is_not_corrected(): void
    {
        $content = 'ได้ครับพี่ ถ้าหมายถึง Personal กับ BM ความเร็วในการขึ้นวงเงินไม่ต่างกันครับ ทั้งคู่เริ่มที่ 1,600 บาทและขึ้นตามระบบ Facebook โดยการผูกบัตรมีโอกาสปรับวงเงินได้เร็วกว่าการเติมเงินครับ ถามเพิ่มได้เลยครับ';

        $result = $this->guard->enforce($content, null, $this->vipConversation);

        $this->assertFalse($result['corrected']);
        $this->assertSame($content, $result['content']);
    }

    /** A2 — limit keyword ~39 chars before the amount; the reply was correct. */
    public function test_second_limit_false_positive_is_not_corrected(): void
    {
        $content = 'อ๋อ ได้ครับพี่ หลัก (Personal) กับ BM ไม่ได้มีตัวไหนขึ้นวงเงินไวกว่ากันเป็นพิเศษครับ ทั้งคู่เริ่มที่ 1,600 บาท การผูกบัตรมักช่วยให้ Limit ปรับขึ้นได้เร็วกว่าเติมเงิน แต่ความเร็วขึ้นอยู่กับระบบ Facebook และรับประกันเวลาแน่นอนไม่ได้ครับ';

        $result = $this->guard->enforce($content, null, $this->vipConversation);

        $this->assertFalse($result['corrected']);
        $this->assertSame($content, $result['content']);
    }

    /** B1 — correct multi-product total: 2×1,100 Personal + 2×199 Page = 2,598. */
    public function test_correct_multi_product_total_with_page_is_not_corrected(): void
    {
        $content = 'ใช่ครับพี่ รับเป็น Nolimit Level Up+ Personal 2 แอคเคานต์ พร้อม Page 2 เพจ รวม 2,598 บาทครับ เงื่อนไขเรื่องคิว Support ที่แจ้งไป พี่รับได้ไหมครับ?';

        $result = $this->guard->enforce($content, null, $this->nonVipConversation());

        $this->assertFalse($result['corrected']);
        $this->assertSame($content, $result['content']);
    }

    /** B2 — Page named only by its Thai alias เพจ: 2×1,100 + 2×199 = 2,598. */
    public function test_correct_total_with_thai_page_alias_is_not_corrected(): void
    {
        $content = 'รับทราบครับพี่ Nolimit Personal 2 ตัว และเพจ 2 เพจ รวม 2,598 บาทตามเดิมครับ';

        $result = $this->guard->enforce($content, null, $this->nonVipConversation());

        $this->assertFalse($result['corrected']);
        $this->assertSame($content, $result['content']);
    }

    /** B3 — total keyword เป็น; 1×1,100 + 1×199 = 1,299. */
    public function test_personal_plus_page_total_is_not_corrected(): void
    {
        $content = 'รวมทั้ง Nolimit Personal แบบผูกบัตร 1 ตัวกับ Page 1 เพจ เป็น 1,299 บาทครับพี่';

        $result = $this->guard->enforce($content, null, $this->nonVipConversation());

        $this->assertFalse($result['corrected']);
        $this->assertSame($content, $result['content']);
    }

    /** B4 — no explicit Page quantity: defaults to 1; 1,100 + 199 = 1,299. */
    public function test_default_quantity_total_is_not_corrected(): void
    {
        $content = 'ได้ครับพี่ รับ Personal 1 บัญชีพร้อมเพจได้ครับ รวม 1,299 บาท ไม่มี QR นะครับ ชำระโดยโอนเข้าบัญชีธนาคารตอนถึงขั้นตอนชำระเงินครับ';

        $result = $this->guard->enforce($content, null, $this->nonVipConversation());

        $this->assertFalse($result['corrected']);
        $this->assertSame($content, $result['content']);
    }

    /** x N quantity syntax from the spec: 2×1,100 + 2×199 = 2,598. */
    public function test_x_quantity_total_is_not_corrected(): void
    {
        $content = 'รับ Personal x2 และ Page x2 รวม 2,598 บาทครับ';

        $result = $this->guard->enforce($content, null, $this->nonVipConversation());

        $this->assertFalse($result['corrected']);
        $this->assertSame($content, $result['content']);
    }

    /** T1 — TRUE positive: VIP customer told the normal price. Must stay caught. */
    public function test_vip_quoted_normal_price_is_still_corrected_as_informational(): void
    {
        $content = 'ถ้าพี่ไม่ได้ยิง Conversion API (CAPI) ใช้ Nolimit Personal แทนได้เลยครับ เหมือนกันทุกอย่าง ยิงแอด ติดพิกเซล ผูกบัตร เติมเงินได้ปกติ ราคาเท่ากัน 1,100 บาท พร้อมส่งเลย';

        $result = $this->guard->enforce($content, null, $this->vipConversation);

        $this->assertTrue($result['corrected']);
        $this->assertSame('informational_price', $result['reason']['type']);
        $this->assertSame(1000.0, $result['reason']['expected']);
    }

    /** Money safety — VIP total computed with normal prices must not pass. */
    public function test_wrong_vip_multi_product_total_is_still_corrected(): void
    {
        $result = $this->guard->enforce(
            'Nolimit Personal 2 ตัว และเพจ 2 เพจ รวม 2,598 บาท',
            null,
            $this->vipConversation
        );

        $this->assertTrue($result['corrected']);
    }

    /** Money safety — correct VIP total is 1,199, not 1,299. */
    public function test_wrong_vip_total_with_default_page_quantity_is_still_corrected(): void
    {
        $result = $this->guard->enforce(
            'รับ Personal 1 บัญชีพร้อมเพจ รวม 1,299 บาท',
            null,
            $this->vipConversation
        );

        $this->assertTrue($result['corrected']);
    }

    /** Money safety — non-VIP customer must not be quoted the VIP price. */
    public function test_non_vip_quoted_vip_price_is_still_corrected(): void
    {
        $result = $this->guard->enforce(
            'Nolimit Personal ราคา 1,000 บาท',
            null,
            $this->nonVipConversation()
        );

        $this->assertTrue($result['corrected']);
    }

    /** Money safety — VIP customer must not be quoted the normal price. */
    public function test_vip_quoted_normal_price_is_still_corrected(): void
    {
        $result = $this->guard->enforce(
            'Personal ราคา 1,100 บาท',
            null,
            $this->vipConversation
        );

        $this->assertTrue($result['corrected']);
    }

    /** Money safety — ราคา between the limit keyword and the amount keeps it a price. */
    public function test_price_after_limit_keyword_is_still_corrected(): void
    {
        $result = $this->guard->enforce(
            'Personal วงเงินเริ่ม 1,600 ราคา 1,100 บาท',
            null,
            $this->vipConversation
        );

        $this->assertTrue($result['corrected']);
    }
}
