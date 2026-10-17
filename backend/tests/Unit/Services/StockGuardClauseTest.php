<?php

namespace Tests\Unit\Services;

use App\Models\ProductStock;
use App\Services\StockGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Clause-level StockGuard decisions (prod fixtures, bot 26, 12-17 Oct 2026).
 *
 * The old whole-reply 40-60-char window let a selling keyword of ANOTHER
 * product (or of the customer's own payment process) sit near "BM" and make
 * isActivelySelling win, replacing whole replies that never sold BM.
 * Selling/refusal signals must now be judged per CLAUSE; a mere mention must
 * keep the reply and at most append the stock notice.
 */
class StockGuardClauseTest extends TestCase
{
    use RefreshDatabase;

    protected StockGuardService $guard;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(ProductStock::STOCK_CACHE_KEY);

        ProductStock::factory()->outOfStock()->create([
            'name' => 'Nolimit Level Up+ BM',
            'slug' => 'bm',
            'aliases' => ['BM', 'NLM BM', 'Nolimit BM', 'กระเป๋า BM', 'บัญชีธุรกิจ', 'บีเอ็ม', 'พอร์ตโฟลิโอ'],
        ]);
        ProductStock::factory()->create([
            'name' => 'Nolimit Level Up+ Personal',
            'slug' => 'personal',
            'in_stock' => true,
        ]);
        ProductStock::factory()->create([
            'name' => 'Page',
            'slug' => 'page',
            'in_stock' => true,
            'aliases' => ['เพจ'],
        ]);

        $this->guard = app(StockGuardService::class);
    }

    // ---- real prod replies that must NEVER be replaced (fixtures 1-8) ----

    public function test_fixture_1_cancel_mention_is_unchanged(): void
    {
        $response = 'รับทราบครับพี่ ยกเลิกรายการ BM กับเพจไว้ก่อนนะครับ ยังไม่มีการสรุปออเดอร์หรือชำระเงินครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertSame($response, $result['content']);
    }

    public function test_fixture_2_compatibility_mention_is_never_replaced(): void
    {
        $response = 'มีครับพี่ เพจใหม่ราคา 199 บาท ไม่มีผู้ติดตามครับ ใช้ได้ทั้ง Personal และ BM โดยทีม Support จัดการเรื่องรับเพจให้หลังชำระเงินครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertStringContainsString('ใช้ได้ทั้ง Personal และ BM', $result['content']);
        $this->assertStringNotContainsString('หมด stock', $result['content']);
    }

    public function test_fixture_4_not_needed_mention_is_unchanged(): void
    {
        $response = 'ใช่ครับพี่ Nolimit Personal สร้างเพจเองไม่ได้ครับ แต่สั่งเพจสำเร็จรูปได้ในราคา 199 บาท ใช้กับ Personal ยิงแอดได้เลย ไม่จำเป็นต้องมี BM ครับ หลังชำระเงินทีม Support จัดการเรื่องรับเพจให้ครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertSame($response, $result['content']);
    }

    public function test_fixture_5_not_needed_mention_is_unchanged(): void
    {
        $response = 'บัญชี Personal สร้างเพจเองไม่ได้ครับพี่ ถ้าต้องการเพจใหม่มีเพจสำเร็จรูป 199 บาทครับ ทีม Support จัดการเรื่องรับเพจให้หลังชำระเงิน ไม่ต้องมี BM ครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertSame($response, $result['content']);
    }

    public function test_fixture_6_summary_of_other_products_is_unchanged(): void
    {
        $response = 'ได้ครับพี่ ขอโทษที่สรุปให้ไม่ชัดนะครับ ตอนนี้รับรายการเป็น Nolimit Personal 1 ตัวกับ Page 1 เพจ รวม 1,299 บาทครับ BM หมดชั่วคราวนะครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertSame($response, $result['content']);
    }

    public function test_fixture_7_customer_own_bm_mention_is_unchanged(): void
    {
        $response = 'รับทราบครับพี่ ทางร้านมอบสิทธิ VIP ให้พี่ครับ ทีมงาน Support จัดการเรื่องรับเพจเข้า BM ให้หลังชำระเงินนะครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertSame($response, $result['content']);
    }

    public function test_fixture_8_price_of_other_product_is_unchanged(): void
    {
        $response = 'ได้ครับพี่ ราคาเท่ากันครับ แต่ซื้อเยอะส่งไวกว่าปกตินะครับ Nolimit Personal ตัวละ 1,100 บาท 3 ตัวรวม 3,300 บาท ส่วน BM ตอนนี้หมดชั่วคราวครับพี่ รับเป็น Personal ทั้ง 3 ตัวไหมครับ?';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertSame($response, $result['content']);
    }

    // ---- real selling of BM must stay a FULL block ----

    public function test_order_summary_with_own_quantity_still_blocks(): void
    {
        $response = 'สรุปรายการ: Nolimit Level Up+ BM 2 ตัว รวม 2,200 บาทครับ พิมพ์ยืนยันได้เลยครับ';

        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
        $this->assertContains('Nolimit Level Up+ BM', $result['blocked_products']);
        $this->assertStringContainsString('หมด stock', $result['content']);
    }

    public function test_own_price_with_sales_pitch_still_blocks(): void
    {
        $response = 'BM ราคา 1,100 บาท พร้อมส่งเลยครับ สนใจรับกี่ตัวครับ';

        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
        $this->assertContains('Nolimit Level Up+ BM', $result['blocked_products']);
        $this->assertStringContainsString('หมด stock', $result['content']);
    }

    public function test_recommendation_with_price_still_blocks(): void
    {
        $response = 'แนะนำ Nolimit BM ครับ ราคา 1,100 บาท โอนแล้วส่งทันที';

        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
        $this->assertContains('Nolimit Level Up+ BM', $result['blocked_products']);
        $this->assertStringContainsString('หมด stock', $result['content']);
    }

    // ---- reviewer round 2: refusal must refer to the product itself ----

    public function test_order_summary_with_unrelated_negation_still_blocks(): void
    {
        // "ไม่มีค่าส่ง" negates the shipping fee, not BM — the summary with
        // BM's own quantity/price must stay blocked (main blocks this too)
        $response = 'สรุปรายการ: BM 2 ตัว รวม 2,200 บาท ไม่มีค่าส่งครับ';

        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
        $this->assertContains('Nolimit Level Up+ BM', $result['blocked_products']);
        $this->assertStringContainsString('หมด stock', $result['content']);
    }

    // ---- reviewer round 3: "ทั้งหมด" means total, not out of stock ----

    public function test_order_summary_with_x_quantity_and_total_still_blocks(): void
    {
        $response = 'สรุปรายการ: BM x2 รวมทั้งหมด 2,200 บาทครับ';

        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
        $this->assertSame(['Nolimit Level Up+ BM'], $result['blocked_products']);
        $this->assertStringContainsString('หมด stock', $result['content']);
        $this->assertStringNotContainsString($response, $result['content']);
    }

    public function test_order_summary_with_total_before_quantity_still_blocks(): void
    {
        $response = 'สรุป BM ทั้งหมด 2 ตัว 2,200 บาทครับ';

        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
        $this->assertSame(['Nolimit Level Up+ BM'], $result['blocked_products']);
        $this->assertStringContainsString('หมด stock', $result['content']);
        $this->assertStringNotContainsString($response, $result['content']);
    }

    public function test_order_summary_with_total_before_product_still_blocks(): void
    {
        $response = 'ยอดรวมทั้งหมด BM 2 ตัว 2,200 บาทครับ';

        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
        $this->assertSame(['Nolimit Level Up+ BM'], $result['blocked_products']);
        $this->assertStringContainsString('หมด stock', $result['content']);
        $this->assertStringNotContainsString($response, $result['content']);
    }

    public function test_total_after_price_only_clause_does_not_suppress_notice(): void
    {
        $response = 'BM ราคา 1,100 บาทครับ ราคานี้สำหรับทั้งหมดครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertSame($response."\n\nตอนนี้ Nolimit Level Up+ BM หมดสต็อกชั่วคราวครับ", $result['content']);
    }

    public function test_total_before_price_only_clause_does_not_suppress_notice(): void
    {
        $response = 'ราคานี้สำหรับทั้งหมดครับ BM ราคา 1,100 บาทครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertSame($response."\n\nตอนนี้ Nolimit Level Up+ BM หมดสต็อกชั่วคราวครับ", $result['content']);
    }

    public function test_total_in_product_price_description_does_not_suppress_notice(): void
    {
        $response = 'สินค้า BM ทั้งหมดราคา 1,100 บาทครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertSame($response."\n\nตอนนี้ Nolimit Level Up+ BM หมดสต็อกชั่วคราวครับ", $result['content']);
    }

    public function test_total_followed_by_already_does_not_suppress_notice(): void
    {
        $response = 'BM ทั้งหมดแล้ว 1,100 บาทครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertSame($response."\n\nตอนนี้ Nolimit Level Up+ BM หมดสต็อกชั่วคราวครับ", $result['content']);
    }

    // ---- reviewer round 2: cheer words must belong to THIS product ----

    public function test_cheer_word_of_other_product_page_does_not_block_bm_mention(): void
    {
        // "พร้อมส่ง" pitches Page (in stock); BM is only a compatibility mention
        $response = 'Page ราคา 199 บาท ใช้กับ BM ได้ พร้อมส่งครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertStringContainsString('ใช้กับ BM ได้', $result['content']);
        $this->assertStringNotContainsString('หมด stock', $result['content']);
    }

    public function test_cheer_word_of_other_product_personal_does_not_block_bm_mention(): void
    {
        $response = 'Personal พร้อมส่งเลย ใช้ร่วมกับ BM ได้ครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertStringContainsString('ใช้ร่วมกับ BM ได้', $result['content']);
        $this->assertStringNotContainsString('หมด stock', $result['content']);
    }

    // ---- reviewer round 2: quantity must belong to the product itself ----

    public function test_feature_count_is_not_own_quantity(): void
    {
        // "50 เพจ" counts pages the BM can create, not units of BM being sold
        $response = 'BM สร้างเพจได้ 50 เพจครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertStringContainsString('สร้างเพจได้ 50 เพจ', $result['content']);
        $this->assertStringNotContainsString('หมด stock', $result['content']);
    }

    // ---- new clause-level mechanics ----

    public function test_payment_word_alone_near_name_appends_notice_not_block(): void
    {
        $response = 'ใช้ได้ทั้ง Personal และ BM ครับ หลังชำระเงินทีมงานจัดส่งให้ครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertStringContainsString('ใช้ได้ทั้ง Personal และ BM', $result['content']);
        $this->assertStringContainsString('หมดสต็อกชั่วคราว', $result['content']);
    }

    public function test_own_quantity_in_same_clause_still_blocks(): void
    {
        $response = 'ยืนยันครับ เอา Nolimit Level Up+ BM 2 ตัว พร้อมส่งครับ';

        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
        $this->assertContains('Nolimit Level Up+ BM', $result['blocked_products']);
    }

    public function test_customer_own_bm_id_digits_is_unchanged(): void
    {
        $response = 'รับทราบครับ ทีมงานจัดการรับเพจเข้า BM =1569089761185236 ให้หลังชำระเงินครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertSame($response, $result['content']);
    }

    public function test_upsell_only_offer_is_stripped_not_blocked(): void
    {
        $response = "เพิ่ม Nolimit Level Up+ Personal ลงตะกร้าแล้วครับ\n"
            ."รวม 1,100 บาท\n"
            .'รับ BM เพิ่มไหมครับ';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertStringContainsString('Nolimit Level Up+ Personal', $result['content']);
        $this->assertStringNotContainsString('รับ BM เพิ่ม', $result['content']);
    }
}
