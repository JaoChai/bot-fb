<?php

namespace Tests\Unit\Services;

use App\Models\ProductStock;
use App\Services\StockInjectionService;
use Tests\TestCase;

class StockInjectionServiceTest extends TestCase
{
    private StockInjectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StockInjectionService;
    }

    /** สินค้า stock ที่มีจำนวน — ไม่ต้องแตะ DB ใช้ make() พอ */
    private function stockProduct(string $name, ?int $count, bool $inStock = true): ProductStock
    {
        return ProductStock::make([
            'name' => $name, 'slug' => str_replace(' ', '-', $name), 'aliases' => [],
            'in_stock' => $inStock, 'available_count' => $count,
            'stock_code' => 'X', 'delivery_method' => 'stock',
        ]);
    }

    public function test_injection_shows_qty_and_rules_for_counted_products(): void
    {
        $result = $this->service->buildStockInjection(collect([
            $this->stockProduct('BM แดง', 5),
        ]));

        $this->assertStringContainsString('[จำนวนพร้อมส่ง]: BM แดง = 5 ชิ้น', $result);
        $this->assertStringContainsString('ห้ามรับออเดอร์/เพิ่มตะกร้า/สรุปยอดเกินจำนวนพร้อมส่ง', $result);
        $this->assertStringContainsString('ห้ามพูดถึงจำนวนคงเหลือ', $result);
        $this->assertStringContainsString('เสนอขายเท่าที่มี', $result);
    }

    public function test_injection_skips_qty_for_null_count_and_out_of_stock(): void
    {
        $result = $this->service->buildStockInjection(collect([
            $this->stockProduct('เพจ', null),          // support_link ไม่มีจำนวน
            $this->stockProduct('BM เขียว', 7, false), // ของหมด — ห้ามโชว์เลข
        ]));

        $this->assertStringNotContainsString('[จำนวนพร้อมส่ง]', $result);
        $this->assertStringNotContainsString('= 7', $result);
    }

    public function test_reminder_includes_qty_line_when_counts_exist(): void
    {
        $result = $this->service->buildStockReminder(collect([
            $this->stockProduct('BM แดง', 5),
        ]));

        $this->assertStringContainsString('QTY REMINDER', $result);
        $this->assertStringContainsString('BM แดง = 5', $result);
        $this->assertStringContainsString('เสนอขายเท่าที่มี', $result);
    }

    public function test_reminder_combines_out_of_stock_and_qty(): void
    {
        $result = $this->service->buildStockReminder(collect([
            $this->stockProduct('BM แดง', 5),
            $this->stockProduct('BM เขียว', null, false),
        ]));

        $this->assertStringContainsString('STOCK REMINDER', $result); // ของหมดเดิม
        $this->assertStringContainsString('QTY REMINDER', $result);   // จำนวนใหม่
    }

    public function test_reminder_empty_when_nothing_to_say(): void
    {
        $result = $this->service->buildStockReminder(collect([
            $this->stockProduct('เพจ', null), // in stock, ไม่มีจำนวน
        ]));

        $this->assertSame('', $result);
    }
}
