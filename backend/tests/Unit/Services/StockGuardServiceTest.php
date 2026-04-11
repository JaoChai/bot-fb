<?php

namespace Tests\Unit\Services;

use App\Models\ProductStock;
use App\Services\StockGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StockGuardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected StockGuardService $guard;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(ProductStock::STOCK_CACHE_KEY);
        $this->guard = app(StockGuardService::class);
    }

    public function test_passes_through_when_no_out_of_stock_products(): void
    {
        ProductStock::factory()->create(['name' => 'Product A', 'slug' => 'a', 'in_stock' => true]);

        $result = $this->guard->validate('Product A ราคา 1,100 บาท');

        $this->assertFalse($result['blocked']);
        $this->assertEquals('Product A ราคา 1,100 บาท', $result['content']);
    }

    public function test_passes_through_when_response_correctly_refuses_sale(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Nolimit Level Up+ BM',
            'slug' => 'bm',
            'aliases' => ['BM'],
        ]);

        $response = 'ขออภัยครับ Nolimit Level Up+ BM หมดชั่วคราวครับ ไม่สามารถสั่งซื้อได้';
        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertEquals($response, $result['content']);
    }

    public function test_blocks_response_with_price_for_out_of_stock_product(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Nolimit Level Up+ BM',
            'slug' => 'bm',
            'aliases' => ['BM'],
        ]);

        $response = 'เพิ่ม Nolimit Level Up+ BM ลงตะกร้าแล้วครับ รวม 1,100 บาท';
        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
        $this->assertContains('Nolimit Level Up+ BM', $result['blocked_products']);
        $this->assertStringContainsString('หมด stock', $result['content']);
    }

    public function test_strips_upsell_for_out_of_stock_product(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Page',
            'slug' => 'page',
            'aliases' => ['เพจ'],
        ]);

        $response = 'รับเพจเพิ่มด้วยไหมครับ ตัวละ 199 บาท';
        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertStringNotContainsString('เพจ', $result['content']);
    }

    public function test_replacement_response_includes_available_products(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Nolimit Level Up+ BM',
            'slug' => 'bm',
            'aliases' => ['BM'],
        ]);
        ProductStock::factory()->create([
            'name' => 'Nolimit Level Up+ Personal',
            'slug' => 'personal',
            'in_stock' => true,
        ]);

        $response = 'Nolimit Level Up+ BM ราคา 1,100 บาทครับ';
        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
        $this->assertStringContainsString('Nolimit Level Up+ Personal', $result['content']);
    }

    public function test_passes_when_config_disabled(): void
    {
        config(['rag.stock_guard.enabled' => false]);

        ProductStock::factory()->outOfStock()->create([
            'name' => 'Nolimit Level Up+ BM',
            'slug' => 'bm',
            'aliases' => ['BM'],
        ]);

        $response = 'Nolimit Level Up+ BM ราคา 1,100 บาท';
        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
    }

    public function test_detects_product_by_alias(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Nolimit Level Up+ BM',
            'slug' => 'bm',
            'aliases' => ['BM', 'บีเอ็ม', 'พอร์ตโฟลิโอ'],
        ]);

        $response = 'พอร์ตโฟลิโอ ราคา 1,100 บาทครับ';
        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
        $this->assertContains('Nolimit Level Up+ BM', $result['blocked_products']);
    }

    public function test_does_not_block_unrelated_response(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Nolimit Level Up+ BM',
            'slug' => 'bm',
            'aliases' => ['BM'],
        ]);

        $response = 'สวัสดีครับ ยินดีให้บริการ มีอะไรให้ช่วยครับ?';
        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
    }

    public function test_handles_multiple_out_of_stock_violations(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Nolimit Level Up+ BM',
            'slug' => 'bm',
            'aliases' => ['BM'],
        ]);
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Page',
            'slug' => 'page',
            'aliases' => ['เพจ'],
        ]);

        $response = 'สรุปยอด: Nolimit Level Up+ BM x1 = 1,100 บาท, Page x1 = 199 บาท รวม 1,299 บาท';
        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
        $this->assertCount(2, $result['blocked_products']);
    }

    public function test_short_alias_is_skipped_to_avoid_false_positive(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Test Product',
            'slug' => 'test',
            'aliases' => ['T'], // Single char alias
        ]);

        $response = 'The price is 500 บาท';
        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
    }

    public function test_blocks_selling_product_even_when_another_is_refused(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Nolimit Level Up+ BM',
            'slug' => 'bm',
            'aliases' => ['BM'],
        ]);
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Page',
            'slug' => 'page',
            'aliases' => ['เพจ'],
        ]);

        // Response refuses BM but sells Page — guard blocks the whole response (conservative)
        $response = 'ขออภัยครับ Nolimit Level Up+ BM หมดชั่วคราว แต่ Page ราคา 199 บาท เพิ่มลงตะกร้าได้เลยครับ';
        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
        $this->assertContains('Page', $result['blocked_products']);
    }

    public function test_allows_informational_price_with_refusal(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Nolimit Level Up+ BM',
            'slug' => 'bm',
            'aliases' => ['BM', 'บีเอ็ม'],
        ]);

        // Customer asks about price — bot gives info + says out of stock
        $response = 'Nolimit Level Up+ BM ราคา 1,100 บาทครับ แต่ตอนนี้หมดชั่วคราว ถ้ารอได้จะแจ้งเมื่อกลับมาครับ';
        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertEquals($response, $result['content']);
    }

    public function test_blocks_active_selling_even_with_refusal(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Nolimit Level Up+ BM',
            'slug' => 'bm',
            'aliases' => ['BM'],
        ]);

        // Bot says out of stock but also adds to cart — should block
        $response = 'Nolimit Level Up+ BM หมดชั่วคราว แต่เพิ่มลงตะกร้าให้แล้วครับ ราคา 1,100 บาท';
        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
    }

    public function test_allows_feature_explanation_for_out_of_stock(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Page',
            'slug' => 'page',
            'aliases' => ['เพจ'],
        ]);

        // Bot answers price question + says out of stock (informational, no active selling)
        $response = 'Page ราคา 199 บาทครับ แต่ตอนนี้ Page หมดสต็อกชั่วคราว ถ้ารอได้จะแจ้งนะครับ';
        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
    }

    public function test_does_not_block_payment_instruction_with_product_as_line_item(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Page',
            'slug' => 'page',
            'aliases' => ['เพจ', 'fanpage'],
        ]);

        $response = "สรุปรายการที่พี่สั่งซื้อครับ:\n\n"
            ."1. Nolimit Level Up+ Personal (1,000 x 2) = 2,000 บาท\n"
            ."2. บริการเสริม Page = 199 บาท\n\n"
            ."รวมยอดโอน: 2,199 บาท\n\n"
            ."ธนาคารกสิกรไทย (KBANK)\n"
            ."223-3-24880-3\n"
            .'ชื่อบัญชี: หจก. มั่งมีทรัพย์ขายของออนไลน์';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertEquals($response, $result['content']);
    }

    public function test_does_not_block_payment_with_zero_price_line_item(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Page',
            'slug' => 'page',
            'aliases' => ['เพจ', 'fanpage'],
        ]);

        $response = "สรุปรายการ:\n\n"
            ."1. Nolimit Level Up+ Personal 2,000 บาท\n"
            ."2. Page = 0 บาท\n\n"
            ."รวมยอดโอน: 2,000 บาท\n\n"
            .'223-3-24880-3';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
    }

    public function test_blocks_selling_out_of_stock_even_with_bank_account_if_not_line_item(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Page',
            'slug' => 'page',
            'aliases' => ['เพจ', 'fanpage'],
        ]);

        $response = "แนะนำ Page ราคา 599 บาท\n\n"
            ."โอนมาที่:\n"
            .'223-3-24880-3';

        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
        $this->assertContains('Page', $result['blocked_products']);
    }

    public function test_does_not_block_payment_with_alias_as_line_item(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Page',
            'slug' => 'page',
            'aliases' => ['เพจ', 'fanpage'],
        ]);

        $response = "สรุปรายการ:\n\n"
            ."1. Nolimit Level Up+ Personal 2,000 บาท\n"
            ."- เพจ 199 บาท\n\n"
            ."รวมยอดโอน: 2,199 บาท\n\n"
            .'223-3-24880-3';

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
    }

    public function test_blocks_price_only_response_without_thai_currency(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Nolimit Level Up+ BM',
            'slug' => 'bm',
            'aliases' => ['BM'],
        ]);

        // Price with digits only (no บาท keyword) — tests the \d{3,} pattern
        $response = 'Nolimit Level Up+ BM ราคา 1100';
        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
    }

    public function test_strips_upsell_from_cart_response_keeping_main_product(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Page',
            'slug' => 'page',
            'aliases' => ['เพจ'],
        ]);
        ProductStock::factory()->create([
            'name' => 'Nolimit Level Up+ Personal',
            'slug' => 'personal',
            'in_stock' => true,
        ]);

        $response = "เพิ่ม Nolimit Level Up+ Personal ลงตะกร้าแล้วครับ\n\n"
            ."ตะกร้า:\n"
            ."- Nolimit Level Up+ Personal x1 - 1,100 บาท\n"
            ."รวม: 1,100 บาท\n\n"
            ."📌 Limit เริ่มต้น 1,600 บาท\n\n"
            ."รับ Page เพิ่มไหมครับ? ตัวละ 199 บาท\n\n"
            ."ถ้าไม่รับพิมพ์ 'พอแล้ว' มาได้เลยครับ";

        $result = $this->guard->validate($response);

        $this->assertFalse($result['blocked']);
        $this->assertStringContainsString('Nolimit Level Up+ Personal', $result['content']);
        $this->assertStringContainsString('1,100 บาท', $result['content']);
        $this->assertStringNotContainsString('รับ Page เพิ่ม', $result['content']);
        $this->assertStringNotContainsString('พอแล้ว', $result['content']);
    }

    public function test_still_blocks_direct_selling_of_out_of_stock_page(): void
    {
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Page',
            'slug' => 'page',
            'aliases' => ['เพจ'],
        ]);

        // Direct selling (not upsell) — should still block
        $response = 'Page ราคา 199 บาท เพิ่มลงตะกร้าให้แล้วครับ';
        $result = $this->guard->validate($response);

        $this->assertTrue($result['blocked']);
        $this->assertContains('Page', $result['blocked_products']);
    }
}
