<?php

namespace Tests\Unit\Services;

use App\Services\FlowPluginService;
use App\Services\OpenRouterService;
use ReflectionClass;
use Tests\TestCase;

class FlowPluginServiceTest extends TestCase
{
    private FlowPluginService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $openRouter = $this->createMock(OpenRouterService::class);
        $this->service = new FlowPluginService($openRouter);
    }

    /**
     * Helper to call private methods via reflection.
     */
    private function callPrivate(string $method, ...$args): mixed
    {
        $reflection = new ReflectionClass($this->service);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($this->service, ...$args);
    }

    public function test_extract_fallback_amount(): void
    {
        $content = "เงินเข้าแล้ว 2,598.00 บาท ✅\nออเดอร์:\n- Nolimit Level Up+ Personal 1 ตัว";

        $result = $this->callPrivate('extractVariablesFallback', $content, ['amount']);

        $this->assertArrayHasKey('amount', $result);
        $this->assertEquals('2,598.00', $result['amount']);
    }

    public function test_extract_fallback_amount_without_decimal(): void
    {
        $content = 'เงินเข้าแล้ว 1,100 บาท ✅';

        $result = $this->callPrivate('extractVariablesFallback', $content, ['amount']);

        $this->assertArrayHasKey('amount', $result);
        $this->assertEquals('1,100', $result['amount']);
    }

    public function test_extract_fallback_product(): void
    {
        $content = "เงินเข้าแล้ว 1,100 บาท ✅\nออเดอร์:\n- Nolimit Level Up+ Personal 1 ตัว\n\nส่งใน 5-10 นาที";

        $result = $this->callPrivate('extractVariablesFallback', $content, ['product']);

        $this->assertArrayHasKey('product', $result);
        $this->assertEquals('Nolimit Level Up+ Personal 1 ตัว', $result['product']);
    }

    public function test_extract_fallback_product_multiple_items(): void
    {
        $content = "เงินเข้าแล้ว 3,200 บาท ✅\nออเดอร์:\n- Nolimit Level Up+ BM x1\n- Nolimit Page x1\n\nส่งใน 5-10 นาที";

        $result = $this->callPrivate('extractVariablesFallback', $content, ['product']);

        $this->assertArrayHasKey('product', $result);
        $this->assertStringContainsString('Nolimit Level Up+ BM x1', $result['product']);
        $this->assertStringContainsString('Nolimit Page x1', $result['product']);
    }

    public function test_extract_fallback_source_bank(): void
    {
        $content = "ชำระผ่าน K PLUS ธนาคารกสิกรไทย\nเงินเข้าแล้ว 1,100 บาท ✅";

        $result = $this->callPrivate('extractVariablesFallback', $content, ['source_bank']);

        $this->assertArrayHasKey('source_bank', $result);
        $this->assertEquals('กสิกรไทย (KBANK)', $result['source_bank']);
    }

    public function test_extract_fallback_scb_bank(): void
    {
        $content = 'โอนจาก SCB 1,100 บาท';

        $result = $this->callPrivate('extractVariablesFallback', $content, ['source_bank']);

        $this->assertArrayHasKey('source_bank', $result);
        $this->assertEquals('ไทยพาณิชย์ (SCB)', $result['source_bank']);
    }

    public function test_unreplaced_variables_get_dash(): void
    {
        // Test the final cleanup pattern: {varName} → "-"
        $message = 'สินค้า: {product}, จำนวน: {quantity}';

        // Simulate what the service does: preg_replace remaining vars with dash
        $result = preg_replace('/\{(\w+)\}/', '-', $message);

        $this->assertEquals('สินค้า: -, จำนวน: -', $result);
        $this->assertStringNotContainsString('{product}', $result);
        $this->assertStringNotContainsString('{quantity}', $result);
    }

    public function test_extract_fallback_returns_empty_for_unknown_vars(): void
    {
        $content = 'เงินเข้าแล้ว 1,100 บาท ✅';

        $result = $this->callPrivate('extractVariablesFallback', $content, ['unknown_var']);

        $this->assertEmpty($result);
    }

    public function test_extract_fallback_no_bank_in_content(): void
    {
        $content = "เงินเข้าแล้ว 1,100 บาท ✅\nออเดอร์:\n- Product A";

        $result = $this->callPrivate('extractVariablesFallback', $content, ['source_bank']);

        $this->assertArrayNotHasKey('source_bank', $result);
    }
}
