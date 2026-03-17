<?php

namespace Tests\Unit\Services;

use App\Models\Conversation;
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
    public function test_detects_payment_with_ยอดรวม_fallback(): void
    {
        $text = "ยอดรวม: 1,100 บาท\n223-3-24880-3";

        $this->assertTrue($this->service->isPaymentMessage($text));
    }

    /** @test */
    public function test_detects_payment_with_รวมเป็นเงิน_fallback(): void
    {
        $text = "รวมเป็นเงิน: 2,200 บาท\n223-3-24880-3";

        $this->assertTrue($this->service->isPaymentMessage($text));
    }

    /** @test */
    public function test_parses_payment_with_ยอดรวม(): void
    {
        $text = "ยอดรวม: 1,100 บาท\n223-3-24880-3";

        $data = $this->service->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertEquals('1,100', $data['total']);
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
    // Step 2: Confirm Message Tests
    // ────────────────────────────────────────────────────────

    /** @test */
    public function test_detects_confirm_message(): void
    {
        $text = <<<'TEXT'
        สรุปรายการสั่งซื้อ
        1. Nolimit Level Up+ BM 800 บาท

        รวม: 800 บาท

        หากถูกต้อง กรุณาพิมพ์ "ยืนยัน" เพื่อดำเนินการต่อครับ
        TEXT;

        $this->assertTrue($this->service->isConfirmMessage($text));
    }

    /** @test */
    public function test_does_not_detect_payment_as_confirm(): void
    {
        // Step 4: has bank account → should NOT be detected as confirm
        $text = "รวม: 800 บาท\nยืนยัน\n223-3-24880-3";

        $this->assertFalse($this->service->isConfirmMessage($text));
    }

    /** @test */
    public function test_does_not_detect_verify_as_confirm(): void
    {
        // Step 5: has เงินเข้าแล้ว
        $text = "เงินเข้าแล้ว 800 บาท\nรวม: 800 บาท\nยืนยัน\n[ยืนยันชำระเงิน]";

        $this->assertFalse($this->service->isConfirmMessage($text));

        // Step 5: has tag only
        $text2 = "รวม: 800 บาท\nยืนยัน\n[ยืนยันชำระเงิน]";

        $this->assertFalse($this->service->isConfirmMessage($text2));
    }

    /** @test */
    public function test_does_not_detect_terms_as_confirm(): void
    {
        // Step 3: has ข้อตกลง
        $text = "รวม: 800 บาท\nยืนยัน\nข้อตกลงการใช้บริการ";

        $this->assertFalse($this->service->isConfirmMessage($text));

        // Step 3 fallback: has เงื่อนไข
        $text2 = "รวม: 800 บาท\nยืนยัน\nเงื่อนไขการใช้บริการ";

        $this->assertFalse($this->service->isConfirmMessage($text2));
    }

    /** @test */
    public function test_parses_confirm_data_with_items(): void
    {
        $text = <<<'TEXT'
        1. Nolimit Level Up+ BM (800 x 2) = 1,600 บาท
        2. Nolimit Level Up+ Personal 900 บาท

        รวม: 2,500 บาท
        TEXT;

        $data = $this->service->parseConfirmData($text);

        $this->assertNotNull($data);
        $this->assertEquals('2,500', $data['total']);
        $this->assertCount(2, $data['items']);
        $this->assertEquals('Nolimit Level Up+ BM', $data['items'][0]['name']);
        $this->assertEquals('1,600', $data['items'][0]['total']);
        $this->assertEquals('800', $data['items'][0]['price']);
        $this->assertEquals(2, $data['items'][0]['qty']);
        $this->assertEquals('Nolimit Level Up+ Personal', $data['items'][1]['name']);
        $this->assertEquals('900', $data['items'][1]['total']);
    }

    /** @test */
    public function test_parses_confirm_data_total_only(): void
    {
        $text = "รวม: 800 บาท\nกรุณาพิมพ์ ยืนยัน";

        $data = $this->service->parseConfirmData($text);

        $this->assertNotNull($data);
        $this->assertEquals('800', $data['total']);
        $this->assertEmpty($data['items']);
    }

    /** @test */
    public function test_detects_confirm_with_total_variants(): void
    {
        // รวมทั้งหมด
        $text1 = "รวมทั้งหมด: 1,600 บาท\nกรุณาพิมพ์ ยืนยัน";
        $this->assertTrue($this->service->isConfirmMessage($text1));
        $data1 = $this->service->parseConfirmData($text1);
        $this->assertNotNull($data1);
        $this->assertEquals('1,600', $data1['total']);

        // รวมยอด
        $text2 = "รวมยอด: 800 บาท\nยืนยัน";
        $this->assertTrue($this->service->isConfirmMessage($text2));
        $data2 = $this->service->parseConfirmData($text2);
        $this->assertNotNull($data2);
        $this->assertEquals('800', $data2['total']);

        // รวมเป็นเงิน
        $text3 = "รวมเป็นเงิน 2,500 บาท\nยืนยัน";
        $this->assertTrue($this->service->isConfirmMessage($text3));
        $data3 = $this->service->parseConfirmData($text3);
        $this->assertNotNull($data3);
        $this->assertEquals('2,500', $data3['total']);

        // ยอดรวม (รวม อยู่ข้างใน — match ได้เลย)
        $text4 = "ยอดรวม: 900 บาท\nยืนยัน";
        $this->assertTrue($this->service->isConfirmMessage($text4));
    }

    /** @test */
    public function test_parse_confirm_returns_null_without_total(): void
    {
        $text = "สรุปรายการ\nกรุณาพิมพ์ ยืนยัน";

        $data = $this->service->parseConfirmData($text);

        $this->assertNull($data);
    }

    /** @test */
    public function test_builds_confirm_flex_structure(): void
    {
        $data = [
            'items' => [
                ['name' => 'Nolimit Level Up+ BM', 'total' => '800'],
            ],
            'total' => '800',
        ];

        $flex = $this->service->buildConfirmFlexMessage($data);

        $this->assertEquals('flex', $flex['type']);
        $this->assertStringContains('ยืนยันรายการสั่งซื้อ', $flex['altText']);
        $this->assertStringContains('800 บาท', $flex['altText']);
        $this->assertEquals('bubble', $flex['contents']['type']);
        $this->assertArrayHasKey('header', $flex['contents']);
        $this->assertArrayHasKey('body', $flex['contents']);
        $this->assertArrayHasKey('footer', $flex['contents']);

        // Header should be orange
        $this->assertEquals('#FF6B00', $flex['contents']['header']['backgroundColor']);

        // Body should have subheader text
        $json = json_encode($flex, JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('กรุณาตรวจสอบความถูกต้อง', $json);
        $this->assertStringContains('Nolimit Level Up+ BM', $json);
        $this->assertStringContains('800 บาท', $json);
    }

    /** @test */
    public function test_confirm_flex_has_message_button(): void
    {
        $data = [
            'items' => [],
            'total' => '800',
        ];

        $flex = $this->service->buildConfirmFlexMessage($data);
        $json = json_encode($flex, JSON_UNESCAPED_UNICODE);

        // Button should be message action type (not URI, not clipboard)
        $this->assertStringContains('"type":"message"', $json);
        $this->assertStringContains('"text":"ยืนยัน"', $json);
        $this->assertStringContains('ยืนยันรายการ', $json);
    }

    /** @test */
    public function test_confirm_flex_vip_has_gold_header(): void
    {
        $data = [
            'items' => [['name' => 'Nolimit Level Up+ BM', 'total' => '800']],
            'total' => '800',
        ];

        $flex = $this->service->buildConfirmFlexMessage($data, true);

        $this->assertEquals('#D4A017', $flex['contents']['header']['backgroundColor']);
        $headerJson = json_encode($flex['contents']['header'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('👑 VIP', $headerJson);
        $this->assertStringContains('ยืนยันรายการสั่งซื้อ', $headerJson);

        // Button should also be gold
        $footerJson = json_encode($flex['contents']['footer'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('#D4A017', $footerJson);
    }

    /** @test */
    public function test_try_convert_returns_flex_for_confirm(): void
    {
        $text = <<<'TEXT'
        สรุปรายการสั่งซื้อ
        1. Nolimit Level Up+ BM 800 บาท

        รวม: 800 บาท

        หากถูกต้อง กรุณาพิมพ์ "ยืนยัน" เพื่อดำเนินการต่อครับ
        TEXT;

        $result = $this->service->tryConvertToFlex($text);

        $this->assertIsArray($result);
        $this->assertEquals('flex', $result['type']);
        $this->assertEquals('bubble', $result['contents']['type']);
        $this->assertEquals('#FF6B00', $result['contents']['header']['backgroundColor']);
    }

    /** @test */
    public function test_try_convert_returns_flex_for_confirm_vip(): void
    {
        $conversation = new Conversation;
        $conversation->memory_notes = [
            ['type' => 'memory', 'content' => 'ลูกค้า VIP ประจำ'],
        ];

        $text = <<<'TEXT'
        สรุปรายการสั่งซื้อ
        1. Nolimit Level Up+ BM 800 บาท

        รวม: 800 บาท

        หากถูกต้อง กรุณาพิมพ์ "ยืนยัน" เพื่อดำเนินการต่อครับ
        TEXT;

        $result = $this->service->tryConvertToFlex($text, $conversation);

        $this->assertIsArray($result);
        $this->assertEquals('flex', $result['type']);
        $this->assertEquals('#D4A017', $result['contents']['header']['backgroundColor']);
        $headerJson = json_encode($result['contents']['header'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('👑 VIP', $headerJson);
    }

    // ────────────────────────────────────────────────────────
    // Step 2.5: Support Delay Warning Tests
    // ────────────────────────────────────────────────────────

    /** @test */
    public function test_detects_support_delay_message(): void
    {
        $text = "ขอแจ้งให้ทราบก่อนนะครับพี่ ช่วงนี้ทีม Support อาจใช้เวลาซัพพอร์ตนานกว่าปกติหน่อยครับ หากบัญชีมีปัญหาต้องรอคิวนิดนึง ถ้าพี่รับเงื่อนไขตรงนี้ได้ ผมจะดำเนินการจำหน่ายให้ครับผม\n[แจ้งเตือน Support]";

        $this->assertTrue($this->service->isSupportDelayMessage($text));
    }

    /** @test */
    public function test_detects_support_delay_without_tag(): void
    {
        // Real LLM output: no [แจ้งเตือน Support] tag — fallback detection
        $text = 'ช่วงนี้ทีม Support อาจใช้เวลาซัพพอร์ตนานกว่าปกติหน่อยครับ หากบัญชีมีปัญหาต้องรอคิวนิดนึง ถ้าพี่รับเงื่อนไขตรงนี้ได้ ผมจะดำเนินการจำหน่ายให้ครับผม พิมพ์ "ตกลง" ได้เลยครับ';

        $this->assertTrue($this->service->isSupportDelayMessage($text));
    }

    /** @test */
    public function test_detects_support_delay_fallback_with_rอคิว(): void
    {
        $text = 'ทีม Support อาจต้องรอคิวหน่อยครับ พิมพ์ "ตกลง" ได้เลยครับ';

        $this->assertTrue($this->service->isSupportDelayMessage($text));
    }

    /** @test */
    public function test_does_not_detect_support_delay_in_normal_text(): void
    {
        $this->assertFalse($this->service->isSupportDelayMessage('สวัสดีครับ ยินดีต้อนรับ'));
        $this->assertFalse($this->service->isSupportDelayMessage('ทีม Support พร้อมดูแลครับ'));
        $this->assertFalse($this->service->isSupportDelayMessage('แจ้งเตือน'));
        // Has support delay but no "ตกลง" — not a support delay message
        $this->assertFalse($this->service->isSupportDelayMessage('ซัพพอร์ตนานกว่าปกติ'));
        // Has "ตกลง" but no support delay context
        $this->assertFalse($this->service->isSupportDelayMessage('พิมพ์ ตกลง ได้เลยครับ'));
    }

    /** @test */
    public function test_builds_support_delay_flex_normal(): void
    {
        $flex = $this->service->buildSupportDelayFlexMessage(false);

        $this->assertEquals('flex', $flex['type']);
        $this->assertStringContains('แจ้งระยะเวลา Support', $flex['altText']);
        $this->assertEquals('bubble', $flex['contents']['type']);

        // Header should be orange
        $this->assertEquals('#FF6B00', $flex['contents']['header']['backgroundColor']);
        $headerJson = json_encode($flex['contents']['header'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('⏰', $headerJson);
        $this->assertStringNotContains('VIP', $headerJson);

        // Body should have warning text and info box
        $json = json_encode($flex['contents']['body'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('ทีม Support อาจใช้เวลาซัพพอร์ตนานกว่าปกติ', $json);
        $this->assertStringContains('รับเงื่อนไข', $json);

        // Footer should have only ตกลง button (no ยังไม่ตกลง)
        $footerJson = json_encode($flex['contents']['footer'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('ตกลง', $footerJson);
        $this->assertStringNotContains('ยังไม่ตกลง', $footerJson);
        $this->assertStringContains('"type":"message"', $footerJson);
    }

    /** @test */
    public function test_builds_support_delay_flex_vip(): void
    {
        $flex = $this->service->buildSupportDelayFlexMessage(true);

        $this->assertEquals('flex', $flex['type']);

        // Header should be gold
        $this->assertEquals('#D4A017', $flex['contents']['header']['backgroundColor']);
        $headerJson = json_encode($flex['contents']['header'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('👑 VIP', $headerJson);

        // Body should have VIP greeting and priority note
        $json = json_encode($flex['contents']['body'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('ขอบคุณที่อุดหนุนเสมอ', $json);
        $this->assertStringContains('VIP จะได้รับการดูแลเป็นลำดับต้นๆ', $json);

        // Footer should have only ตกลง button (no ยังไม่ตกลง)
        $footerJson = json_encode($flex['contents']['footer'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('ตกลง', $footerJson);
        $this->assertStringNotContains('ยังไม่ตกลง', $footerJson);
    }

    /** @test */
    public function test_try_convert_returns_flex_for_support_delay(): void
    {
        $text = "ขอแจ้งให้ทราบก่อนนะครับพี่ ช่วงนี้ทีม Support อาจใช้เวลาซัพพอร์ตนานกว่าปกติ\n[แจ้งเตือน Support]";

        $result = $this->service->tryConvertToFlex($text);

        $this->assertIsArray($result);
        $this->assertEquals('flex', $result['type']);
        $this->assertEquals('#FF6B00', $result['contents']['header']['backgroundColor']);
    }

    /** @test */
    public function test_try_convert_returns_vip_flex_for_support_delay(): void
    {
        $conversation = new Conversation;
        $conversation->memory_notes = [
            ['type' => 'memory', 'content' => 'ลูกค้า VIP ประจำ'],
        ];

        $text = "ขอแจ้งให้ทราบก่อนนะครับพี่ ช่วงนี้ทีม Support อาจใช้เวลาซัพพอร์ตนานกว่าปกติ\n[แจ้งเตือน Support]";

        $result = $this->service->tryConvertToFlex($text, $conversation);

        $this->assertIsArray($result);
        $this->assertEquals('flex', $result['type']);
        $this->assertEquals('#D4A017', $result['contents']['header']['backgroundColor']);
        $headerJson = json_encode($result['contents']['header'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('👑 VIP', $headerJson);
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
    public function test_detects_terms_with_เงื่อนไข_and_url(): void
    {
        // "เงื่อนไข" alone is too broad — but with URL it triggers via URL fallback
        $text = "รบกวนอ่านเงื่อนไขก่อนนะครับ\nhttps://mhhacoursecontent.my.canva.site/ads-vance\nพิมพ์ 'ยอมรับ' หลังอ่านจบ";

        $this->assertTrue($this->service->isTermsMessage($text));
    }

    /** @test */
    public function test_does_not_detect_terms_with_เงื่อนไข_alone(): void
    {
        // "เงื่อนไข" without URL is too broad — should NOT trigger
        $text = 'เงื่อนไขการจัดส่ง ยอมรับได้ค่ะ';

        $this->assertFalse($this->service->isTermsMessage($text));
    }

    /** @test */
    public function test_detects_terms_with_url_fallback(): void
    {
        // LLM omits both "ข้อตกลง" and "เงื่อนไข" but includes TERMS_URL
        $text = "อ่านก่อนนะครับ\nhttps://mhhacoursecontent.my.canva.site/ads-vance\nพิมพ์ 'ยอมรับ'";

        $this->assertTrue($this->service->isTermsMessage($text));
    }

    /** @test */
    public function test_does_not_detect_terms_without_keyword(): void
    {
        // Has "ยอมรับ" but no "ข้อตกลง"/"เงื่อนไข"/URL → not a terms message
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
        $text = 'ข้อตกลง ยอมรับ [ยืนยันชำระเงิน]';

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
    public function test_detects_verify_without_tag(): void
    {
        // LLM omits [ยืนยันชำระเงิน] tag — should still detect from content
        $text = "เงินเข้าแล้ว 1,600 บาท ✅\nขอบคุณครับ 🙏";

        $this->assertTrue($this->service->isVerifySuccessMessage($text));
    }

    /** @test */
    public function test_does_not_detect_verify_without_amount(): void
    {
        // Has tag but no amount pattern
        $text = 'ยืนยันแล้ว [ยืนยันชำระเงิน]';

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
        $this->assertStringContains('กัปตันแอด', $json);
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

    // ────────────────────────────────────────────────────────
    // VIP Detection & Styling Tests
    // ────────────────────────────────────────────────────────

    /** @test */
    public function test_detects_vip_from_memory_notes_string(): void
    {
        $conversation = new Conversation;
        $conversation->memory_notes = ['ลูกค้า VIP เคยซื้อ Nolimit 3 ครั้ง'];

        $this->assertTrue($this->service->isVipConversation($conversation));
    }

    /** @test */
    public function test_detects_vip_from_memory_notes_object(): void
    {
        // Production format: array of objects with 'content' key
        $conversation = new Conversation;
        $conversation->memory_notes = [
            [
                'id' => 'test-uuid',
                'type' => 'memory',
                'content' => 'ลูกค้า VIP เคยซื้อ Nolimit Level Up+ BM มาแล้ว',
                'created_at' => '2026-01-16T05:26:19.002451Z',
            ],
        ];

        $this->assertTrue($this->service->isVipConversation($conversation));
    }

    /** @test */
    public function test_detects_vip_case_insensitive(): void
    {
        $conversation = new Conversation;
        $conversation->memory_notes = ['ลูกค้า vip ประจำ'];

        $this->assertTrue($this->service->isVipConversation($conversation));
    }

    /** @test */
    public function test_not_vip_without_keyword(): void
    {
        $conversation = new Conversation;
        $conversation->memory_notes = ['ลูกค้าใหม่ สนใจ Nolimit'];

        $this->assertFalse($this->service->isVipConversation($conversation));
    }

    /** @test */
    public function test_not_vip_object_without_keyword(): void
    {
        $conversation = new Conversation;
        $conversation->memory_notes = [
            ['type' => 'memory', 'content' => 'ลูกค้าใหม่ สนใจ Nolimit'],
        ];

        $this->assertFalse($this->service->isVipConversation($conversation));
    }

    /** @test */
    public function test_not_vip_with_null_conversation(): void
    {
        $this->assertFalse($this->service->isVipConversation(null));
    }

    /** @test */
    public function test_not_vip_with_empty_memory_notes(): void
    {
        $conversation = new Conversation;
        $conversation->memory_notes = [];

        $this->assertFalse($this->service->isVipConversation($conversation));
    }

    /** @test */
    public function test_not_vip_with_non_string_memory_notes(): void
    {
        $conversation = new Conversation;
        $conversation->memory_notes = [['type' => 'vip'], null, 123];

        $this->assertFalse($this->service->isVipConversation($conversation));
    }

    /** @test */
    public function test_payment_flex_vip_has_gold_header(): void
    {
        $data = [
            'items' => [['name' => 'Nolimit Level Up+ BM', 'total' => '800']],
            'total' => '800',
        ];

        $flex = $this->service->buildFlexMessage($data, true);

        $this->assertEquals('#D4A017', $flex['contents']['header']['backgroundColor']);
        $headerJson = json_encode($flex['contents']['header'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('👑 VIP', $headerJson);
        $this->assertStringContains('สรุปรายการสั่งซื้อ', $headerJson);
    }

    /** @test */
    public function test_payment_flex_normal_has_green_header(): void
    {
        $data = [
            'items' => [],
            'total' => '800',
        ];

        $flex = $this->service->buildFlexMessage($data, false);

        $this->assertEquals('#1DB446', $flex['contents']['header']['backgroundColor']);
        $headerJson = json_encode($flex['contents']['header'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('สรุปรายการสั่งซื้อ', $headerJson);
        $this->assertStringNotContains('VIP', $headerJson);
    }

    /** @test */
    public function test_verify_flex_vip_has_gold_header(): void
    {
        $data = [
            'amount' => '1,600',
            'items' => ['Nolimit Level Up+ BM ×2'],
            'delivery' => '5-10 นาที',
        ];

        $flex = $this->service->buildVerifyFlexMessage($data, true);

        $this->assertEquals('#D4A017', $flex['contents']['header']['backgroundColor']);
        $headerJson = json_encode($flex['contents']['header'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('👑 VIP', $headerJson);
        $this->assertStringContains('ยืนยันชำระเงินสำเร็จ', $headerJson);

        // Footer should mention VIP
        $footerJson = json_encode($flex['contents']['footer'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContains('VIP', $footerJson);
    }

    /** @test */
    public function test_verify_flex_normal_has_green_header(): void
    {
        $data = [
            'amount' => '800',
            'items' => [],
            'delivery' => null,
        ];

        $flex = $this->service->buildVerifyFlexMessage($data, false);

        $this->assertEquals('#1DB446', $flex['contents']['header']['backgroundColor']);
        $footerJson = json_encode($flex['contents']['footer'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContains('VIP', $footerJson);
    }

    /** @test */
    public function test_try_convert_with_vip_conversation_returns_gold_flex(): void
    {
        $conversation = new Conversation;
        $conversation->memory_notes = [
            ['type' => 'memory', 'content' => 'ลูกค้า VIP ประจำ'],
        ];

        $text = <<<'TEXT'
        สรุปรายการสั่งซื้อ
        1. Nolimit Level Up+ BM 800 บาท

        รวมยอดโอน: 800 บาท

        โอนเข้าบัญชี
        223-3-24880-3
        TEXT;

        $result = $this->service->tryConvertToFlex($text, $conversation);

        $this->assertIsArray($result);
        $this->assertEquals('#D4A017', $result['contents']['header']['backgroundColor']);
    }

    /** @test */
    public function test_try_convert_without_conversation_returns_green_flex(): void
    {
        $text = <<<'TEXT'
        สรุปรายการสั่งซื้อ
        1. Nolimit Level Up+ BM 800 บาท

        รวมยอดโอน: 800 บาท

        โอนเข้าบัญชี
        223-3-24880-3
        TEXT;

        $result = $this->service->tryConvertToFlex($text);

        $this->assertIsArray($result);
        $this->assertEquals('#1DB446', $result['contents']['header']['backgroundColor']);
    }

    /**
     * Helper: assert string contains substring (PHPUnit 10+ compatible).
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertStringContainsString($needle, $haystack);
    }

    private function assertStringNotContains(string $needle, string $haystack): void
    {
        $this->assertStringNotContainsString($needle, $haystack);
    }
}
