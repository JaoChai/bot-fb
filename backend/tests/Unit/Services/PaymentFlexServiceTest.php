<?php

namespace Tests\Unit\Services;

use App\Services\PaymentFlexService;
use Tests\TestCase;

class PaymentFlexServiceTest extends TestCase
{
    private PaymentFlexService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaymentFlexService;
    }

    /** @test */
    public function test_detects_payment_message(): void
    {
        $text = <<<'TEXT'
        สรุปรายการสั่งซื้อ
        1. Nolimit Level Up+ BM (800 x 2) = 1,600 บาท

        รวมยอดโอน: 1,600 บาท

        โอนเข้าบัญชี
        ธนาคารกสิกรไทย (KBANK)
        223-3-24880-3
        หจก. มั่งมีทรัพย์ขายของออนไลน์
        TEXT;

        $this->assertTrue($this->service->isPaymentMessage($text));
    }

    /** @test */
    public function test_does_not_detect_normal_message(): void
    {
        $this->assertFalse($this->service->isPaymentMessage('สวัสดีครับ ยินดีต้อนรับ'));
        $this->assertFalse($this->service->isPaymentMessage('สินค้าราคา 800 บาทครับ'));
        // Has bank account but no total keyword
        $this->assertFalse($this->service->isPaymentMessage('เลขบัญชี 223-3-24880-3'));
        // Has total keyword but no bank account
        $this->assertFalse($this->service->isPaymentMessage('รวมยอดโอน: 1,600 บาท'));
    }

    /** @test */
    public function test_parses_single_item_order(): void
    {
        $text = <<<'TEXT'
        1. Nolimit Level Up+ BM 800 บาท

        รวมยอดโอน: 800 บาท
        223-3-24880-3
        TEXT;

        $data = $this->service->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertCount(1, $data['items']);
        $this->assertEquals('Nolimit Level Up+ BM', $data['items'][0]['name']);
        $this->assertEquals('800', $data['items'][0]['total']);
        $this->assertEquals('800', $data['total']);
    }

    /** @test */
    public function test_parses_multi_item_order(): void
    {
        $text = <<<'TEXT'
        1. Nolimit Level Up+ BM (800 x 2) = 1,600 บาท
        2. Nolimit Level Up+ Personal 900 บาท

        รวมยอดโอน: 2,500 บาท
        223-3-24880-3
        TEXT;

        $data = $this->service->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertCount(2, $data['items']);

        // First item with qty
        $this->assertEquals('Nolimit Level Up+ BM', $data['items'][0]['name']);
        $this->assertEquals('1,600', $data['items'][0]['total']);
        $this->assertEquals('800', $data['items'][0]['price']);
        $this->assertEquals(2, $data['items'][0]['qty']);

        // Second item without qty
        $this->assertEquals('Nolimit Level Up+ Personal', $data['items'][1]['name']);
        $this->assertEquals('900', $data['items'][1]['total']);
        $this->assertArrayNotHasKey('price', $data['items'][1]);

        $this->assertEquals('2,500', $data['total']);
    }

    /** @test */
    public function test_parses_total_amount_variants(): void
    {
        // "รวมยอดโอน" variant
        $data1 = $this->service->parsePaymentData("รวมยอดโอน: 1,600 บาท\n223-3-24880-3");
        $this->assertNotNull($data1);
        $this->assertEquals('1,600', $data1['total']);

        // "สรุปยอดโอน" variant
        $data2 = $this->service->parsePaymentData("สรุปยอดโอน 800 บาท\n223-3-24880-3");
        $this->assertNotNull($data2);
        $this->assertEquals('800', $data2['total']);

        // "ยอดโอน" variant
        $data3 = $this->service->parsePaymentData("ยอดโอน: 2,500 บาท\n223-3-24880-3");
        $this->assertNotNull($data3);
        $this->assertEquals('2,500', $data3['total']);

        // "สรุปยอด" variant (without โอน)
        $data4 = $this->service->parsePaymentData("สรุปยอด 500 บาท\n223-3-24880-3");
        $this->assertNotNull($data4);
        $this->assertEquals('500', $data4['total']);
    }

    /** @test */
    public function test_builds_valid_flex_structure(): void
    {
        $data = [
            'items' => [
                ['name' => 'Nolimit Level Up+ BM', 'total' => '800', 'price' => '800', 'qty' => 1],
            ],
            'total' => '800',
        ];

        $flex = $this->service->buildFlexMessage($data);

        $this->assertEquals('flex', $flex['type']);
        $this->assertStringContains('สรุปรายการสั่งซื้อ', $flex['altText']);
        $this->assertStringContains('800 บาท', $flex['altText']);
        $this->assertEquals('bubble', $flex['contents']['type']);
        $this->assertArrayHasKey('header', $flex['contents']);
        $this->assertArrayHasKey('body', $flex['contents']);
        $this->assertArrayHasKey('footer', $flex['contents']);
    }

    /** @test */
    public function test_flex_contains_bank_info(): void
    {
        $data = [
            'items' => [],
            'total' => '1,600',
        ];

        $flex = $this->service->buildFlexMessage($data);
        $json = json_encode($flex, JSON_UNESCAPED_UNICODE);

        $this->assertStringContains('223-3-24880-3', $json);
        $this->assertStringContains('ธนาคารกสิกรไทย', $json);
        $this->assertStringContains('หจก. มั่งมีทรัพย์ขายของออนไลน์', $json);
    }

    /** @test */
    public function test_flex_has_clipboard_button(): void
    {
        $data = [
            'items' => [],
            'total' => '800',
        ];

        $flex = $this->service->buildFlexMessage($data);
        $json = json_encode($flex, JSON_UNESCAPED_UNICODE);

        $this->assertStringContains('clipboard', $json);
        $this->assertStringContains('2233248803', $json);
        $this->assertStringContains('คัดลอกเลขบัญชี', $json);
    }

    /** @test */
    public function test_fallback_to_text_on_parse_failure(): void
    {
        // Has bank account + keyword but no parseable total pattern
        $text = "รวมยอดโอน ไม่ระบุ\n223-3-24880-3";

        $result = $this->service->tryConvertToFlex($text);

        $this->assertIsString($result);
        $this->assertEquals($text, $result);
    }

    /** @test */
    public function test_fallback_to_text_on_non_payment(): void
    {
        $text = 'สวัสดีครับ ยินดีต้อนรับสู่ร้าน Captain Ad';

        $result = $this->service->tryConvertToFlex($text);

        $this->assertIsString($result);
        $this->assertEquals($text, $result);
    }

    /** @test */
    public function test_try_convert_returns_flex_for_payment(): void
    {
        $text = <<<'TEXT'
        สรุปรายการสั่งซื้อ
        1. Nolimit Level Up+ BM (800 x 2) = 1,600 บาท

        รวมยอดโอน: 1,600 บาท

        โอนเข้าบัญชี
        ธนาคารกสิกรไทย (KBANK)
        223-3-24880-3
        หจก. มั่งมีทรัพย์ขายของออนไลน์

        ⚠️ ไม่รับโอนเงินจากทรูมันนี่วอลเล็ท

        โอนแล้วรบกวนส่งสลิปมาเลยนะครับ 🙏
        TEXT;

        $result = $this->service->tryConvertToFlex($text);

        $this->assertIsArray($result);
        $this->assertEquals('flex', $result['type']);
        $this->assertEquals('bubble', $result['contents']['type']);
    }

    /** @test */
    public function test_total_only_without_items_creates_flex(): void
    {
        $text = "รวมยอดโอน: 800 บาท\nโอนเข้าบัญชี 223-3-24880-3";

        $result = $this->service->tryConvertToFlex($text);

        $this->assertIsArray($result);
        $this->assertEquals('flex', $result['type']);

        // Body should have no item rows, just total + bank info
        $body = $result['contents']['body'];
        // First element should be the total box (no separator before it since no items)
        $this->assertEquals('box', $body['contents'][0]['type']);
        $this->assertEquals('horizontal', $body['contents'][0]['layout']);
    }

    // ────────────────────────────────────────────────────────
    // Step 3: Terms Message Tests
    // ────────────────────────────────────────────────────────

    /** @test */
    public function test_detects_terms_message(): void
    {
        $text = "📋 ก่อนชำระเงิน รบกวนอ่านข้อตกลงครับ\n🔗 https://mhhacoursecontent.my.canva.site/ads-vance\nพิมพ์ 'ยอมรับ' หลังอ่านจบครับ";

        $this->assertTrue($this->service->isTermsMessage($text));
    }

    /** @test */
    public function test_does_not_detect_terms_without_keyword(): void
    {
        // Has "ยอมรับ" but no "ข้อตกลง" → not a terms message
        $text = "รบกวนอ่านก่อนนะครับ\nพิมพ์ 'ยอมรับ' หลังอ่านจบ";

        $this->assertFalse($this->service->isTermsMessage($text));
    }

    /** @test */
    public function test_does_not_detect_terms_for_payment(): void
    {
        // Has "ยอมรับ" + "ข้อตกลง" BUT also has bank account → should be Step 4
        $text = "ข้อตกลง ยอมรับ\n223-3-24880-3\nรวมยอดโอน: 800 บาท";

        $this->assertFalse($this->service->isTermsMessage($text));
    }

    /** @test */
    public function test_does_not_detect_terms_for_verify(): void
    {
        // Has "ยอมรับ" BUT also has "เงินเข้าแล้ว" → should be Step 5
        $text = "ข้อตกลง ยอมรับ\nเงินเข้าแล้ว 800 บาท";

        $this->assertFalse($this->service->isTermsMessage($text));
    }

    /** @test */
    public function test_does_not_detect_terms_for_verify_tag(): void
    {
        // Has "ยอมรับ" + "ข้อตกลง" BUT also has [ยืนยันชำระเงิน] tag → should be Step 5
        $text = "ข้อตกลง ยอมรับ [ยืนยันชำระเงิน]";

        $this->assertFalse($this->service->isTermsMessage($text));
    }

    /** @test */
    public function test_builds_terms_flex_structure(): void
    {
        $flex = $this->service->buildTermsFlexMessage();

        $this->assertEquals('flex', $flex['type']);
        $this->assertStringContains('ข้อตกลง', $flex['altText']);
        $this->assertEquals('bubble', $flex['contents']['type']);
        $this->assertArrayHasKey('header', $flex['contents']);
        $this->assertArrayHasKey('body', $flex['contents']);
        $this->assertArrayHasKey('footer', $flex['contents']);

        // Header should be blue
        $this->assertEquals('#0367D3', $flex['contents']['header']['backgroundColor']);

        // Footer should have URI button with fixed canva.site URL
        $json = json_encode($flex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContains('uri', $json);
        $this->assertStringContains('canva.site/ads-vance', $json);
        $this->assertStringContains('อ่านข้อตกลง', $json);
    }

    /** @test */
    public function test_try_convert_returns_flex_for_terms(): void
    {
        $text = "📋 ก่อนชำระเงิน รบกวนอ่านข้อตกลงครับ\n🔗 https://mhhacoursecontent.my.canva.site/ads-vance\nพิมพ์ 'ยอมรับ' หลังอ่านจบครับ";

        $result = $this->service->tryConvertToFlex($text);

        $this->assertIsArray($result);
        $this->assertEquals('flex', $result['type']);
        $this->assertEquals('bubble', $result['contents']['type']);
        $this->assertEquals('#0367D3', $result['contents']['header']['backgroundColor']);
    }

    // ────────────────────────────────────────────────────────
    // Step 5: Verify Success Message Tests
    // ────────────────────────────────────────────────────────

    /** @test */
    public function test_detects_verify_success_message(): void
    {
        $text = "เงินเข้าแล้ว 1,600 บาท ✅\nออเดอร์:\n• Nolimit Level Up+ BM ×2\nส่งใน 5-10 นาที 📦\nขอบคุณครับ 🙏\n[ยืนยันชำระเงิน]";

        $this->assertTrue($this->service->isVerifySuccessMessage($text));

        // Decimal amount (e.g. 50.00)
        $textDecimal = "เงินเข้าแล้ว 50.00 บาท ✅\nออเดอร์:\n- G3D x1\nส่งใน 5-10 นาที\nขอบคุณครับ\n[ยืนยันชำระเงิน]";
        $this->assertTrue($this->service->isVerifySuccessMessage($textDecimal));
    }

    /** @test */
    public function test_does_not_detect_verify_without_tag(): void
    {
        // Has "เงินเข้าแล้ว" but missing [ยืนยันชำระเงิน] tag
        $text = "เงินเข้าแล้ว 1,600 บาท ✅\nขอบคุณครับ 🙏";

        $this->assertFalse($this->service->isVerifySuccessMessage($text));
    }

    /** @test */
    public function test_does_not_detect_verify_without_amount(): void
    {
        // Has tag but no amount pattern
        $text = "ยืนยันแล้ว [ยืนยันชำระเงิน]";

        $this->assertFalse($this->service->isVerifySuccessMessage($text));
    }

    /** @test */
    public function test_parses_verify_data(): void
    {
        $text = "เงินเข้าแล้ว 1,600 บาท ✅\nออเดอร์:\n• Nolimit Level Up+ BM ×2\n• Nolimit Personal\nส่งใน 5-10 นาที 📦\nขอบคุณครับ 🙏\n[ยืนยันชำระเงิน]";

        $data = $this->service->parseVerifyData($text);

        $this->assertNotNull($data);
        $this->assertEquals('1,600', $data['amount']);
        $this->assertCount(2, $data['items']);
        $this->assertEquals('Nolimit Level Up+ BM ×2', $data['items'][0]);
        $this->assertEquals('Nolimit Personal', $data['items'][1]);
        $this->assertEquals('5-10 นาที 📦', $data['delivery']);
    }

    /** @test */
    public function test_parses_verify_data_minimal(): void
    {
        $text = "เงินเข้าแล้ว 800 บาท ✅\nขอบคุณครับ 🙏\n[ยืนยันชำระเงิน]";

        $data = $this->service->parseVerifyData($text);

        $this->assertNotNull($data);
        $this->assertEquals('800', $data['amount']);
        $this->assertEmpty($data['items']);
        $this->assertNull($data['delivery']);
    }

    /** @test */
    public function test_parses_verify_data_decimal_amount(): void
    {
        $text = "เงินเข้าแล้ว 50.00 บาท ✅\nออเดอร์:\n- G3D x1\nส่งใน 5-10 นาที\nขอบคุณครับ\n[ยืนยันชำระเงิน]";

        $data = $this->service->parseVerifyData($text);

        $this->assertNotNull($data);
        $this->assertEquals('50.00', $data['amount']);
        $this->assertCount(1, $data['items']);
        $this->assertEquals('G3D x1', $data['items'][0]);
    }

    /** @test */
    public function test_builds_verify_flex_structure(): void
    {
        $data = [
            'amount' => '1,600',
            'items' => ['Nolimit Level Up+ BM ×2'],
            'delivery' => '5-10 นาที',
        ];

        $flex = $this->service->buildVerifyFlexMessage($data);

        $this->assertEquals('flex', $flex['type']);
        $this->assertStringContains('1,600 บาท', $flex['altText']);
        $this->assertStringContains('[ยืนยันชำระเงิน]', $flex['altText']);
        $this->assertEquals('bubble', $flex['contents']['type']);

        // Header should be green with subtitle
        $this->assertEquals('#1DB446', $flex['contents']['header']['backgroundColor']);
        $headerJson = json_encode($flex['contents']['header'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('ยืนยันชำระเงินสำเร็จ', $headerJson);
        $this->assertStringContains('ระบบตรวจสอบเรียบร้อยแล้ว', $headerJson);

        // Body should contain centered amount, items in box, delivery, and support contact
        $json = json_encode($flex, JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('เงินเข้าเรียบร้อย', $json);
        $this->assertStringContains('1,600 บาท', $json);
        $this->assertStringContains('📋 รายการสินค้า', $json);
        $this->assertStringContains('Nolimit Level Up+ BM ×2', $json);
        $this->assertStringContains('จัดส่งภายใน 5-10 นาที', $json);
        $this->assertStringContains('@743ddeqy', $json);
        $this->assertStringContains('ขอบคุณ', $json);
        $this->assertStringContains('Captain Ad', $json);
    }

    /** @test */
    public function test_try_convert_returns_flex_for_verify_success(): void
    {
        $text = "เงินเข้าแล้ว 1,600 บาท ✅\nออเดอร์:\n• Nolimit Level Up+ BM ×2\nส่งใน 5-10 นาที 📦\nขอบคุณครับ 🙏\n[ยืนยันชำระเงิน]";

        $result = $this->service->tryConvertToFlex($text);

        $this->assertIsArray($result);
        $this->assertEquals('flex', $result['type']);
        $this->assertEquals('bubble', $result['contents']['type']);
        $this->assertEquals('#1DB446', $result['contents']['header']['backgroundColor']);
        // altText must contain trigger tag for FlowPluginService
        $this->assertStringContains('[ยืนยันชำระเงิน]', $result['altText']);
    }

    /**
     * Helper: assert string contains substring (PHPUnit 10+ compatible).
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertStringContainsString($needle, $haystack);
    }
}
