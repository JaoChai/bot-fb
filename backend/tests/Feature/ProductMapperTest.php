<?php

namespace Tests\Feature;

use App\Models\ProductStock;
use App\Services\Delivery\ProductMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMapperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProductStock::create([
            'name' => 'Nolimit ส่วนตัว', 'slug' => 'nolimit-personal',
            'aliases' => ['NLM ส่วนตัว', 'Nolimit Personal', 'Nolimit'], 'in_stock' => true,
            'display_order' => 1, 'stock_code' => 'NLMP', 'delivery_method' => 'stock',
        ]);
        ProductStock::create([
            'name' => 'Nolimit Share BM', 'slug' => 'nolimit-bm',
            'aliases' => ['Share BM', 'NLM BM'], 'in_stock' => true,
            'display_order' => 2, 'stock_code' => 'NLMBM', 'delivery_method' => 'stock',
        ]);
        ProductStock::create([
            'name' => 'เพจ', 'slug' => 'page', 'aliases' => ['เพจโฆษณา', 'PAGE'],
            'in_stock' => true, 'display_order' => 3,
            'stock_code' => null, 'delivery_method' => 'support_link',
        ]);
        ProductStock::create([
            'name' => 'เฟสไก่', 'slug' => 'g3d', 'aliases' => ['G3D'],
            'in_stock' => true, 'display_order' => 4,
            'stock_code' => 'G3D', 'delivery_method' => 'stock',
        ]);
        ProductStock::create([
            'name' => 'สินค้าไม่เปิดส่งอัตโนมัติ', 'slug' => 'other', 'aliases' => [],
            'in_stock' => true, 'display_order' => 5,
            'stock_code' => null, 'delivery_method' => 'none',
        ]);
    }

    public function test_maps_by_name(): void
    {
        $p = app(ProductMapper::class)->map('Nolimit ส่วนตัว (ผูกบัตร)');
        $this->assertSame('NLMP', $p->stock_code);
    }

    public function test_maps_by_alias(): void
    {
        $p = app(ProductMapper::class)->map('เพจโฆษณา 2 เพจ');
        $this->assertSame('support_link', $p->delivery_method);
    }

    public function test_prefers_longest_match_over_substring(): void
    {
        // "Nolimit Share BM" ต้องไม่ถูกจับเป็น NLMP แม้ขึ้นต้นด้วย "Nolimit"
        $p = app(ProductMapper::class)->map('Nolimit Share BM');
        $this->assertSame('NLMBM', $p->stock_code);
    }

    public function test_returns_null_for_unknown_or_none(): void
    {
        $this->assertNull(app(ProductMapper::class)->map('ของแปลกๆ ไม่มีในระบบ'));
        $this->assertNull(app(ProductMapper::class)->map('สินค้าไม่เปิดส่งอัตโนมัติ'));
    }

    public function test_short_alias_does_not_overmatch(): void
    {
        ProductStock::create([
            'name' => 'สินค้าพิเศษ', 'slug' => 'special', 'aliases' => ['bm'],
            'in_stock' => true, 'display_order' => 9,
            'stock_code' => 'SPC', 'delivery_method' => 'stock',
        ]);

        // alias "bm" (2 ตัว) ต้องถูกข้าม ไม่ทำให้ "bmw" จับเป็น SPC
        $this->assertNull(app(ProductMapper::class)->map('ปั๊ม bmw ราคาถูก'));
    }

    public function test_ambiguous_equal_length_match_returns_null(): void
    {
        ProductStock::create([
            'name' => 'AAAA', 'slug' => 'aaaa', 'aliases' => [], 'in_stock' => true,
            'display_order' => 10, 'stock_code' => 'A4', 'delivery_method' => 'stock',
        ]);
        ProductStock::create([
            'name' => 'BBBB', 'slug' => 'bbbb', 'aliases' => [], 'in_stock' => true,
            'display_order' => 11, 'stock_code' => 'B4', 'delivery_method' => 'stock',
        ]);

        // มีทั้ง aaaa และ bbbb (ยาวเท่ากัน) → กำกวม ไม่เดา
        $this->assertNull(app(ProductMapper::class)->map('ชุด aaaa และ bbbb'));
    }
}
