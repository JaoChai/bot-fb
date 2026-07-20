<?php

namespace Tests\Unit\Payment;

use App\Services\Payment\PaymentMessageDetector;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Hardening tests for order-summary parsing (2026-07-11).
 * Root cause: 17% of real order summaries fail to parse because chat-model
 * output format drifts beyond what the original regex covered.
 *
 * Group A: previously-broken formats that must now parse.
 * Group B: regression / by-design behavior that must keep working.
 * Group C: qty extraction correctness (money-critical).
 */
class PaymentMessageDetectorHardeningTest extends TestCase
{
    private PaymentMessageDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new PaymentMessageDetector;
    }

    // ────────────────────────────────────────────────────────
    // Group A — must now parse (previously broken)
    // ────────────────────────────────────────────────────────

    #[Test]
    public function test_a1_parses_item_with_intro_prefix_and_1_tua_qty(): void
    {
        $text = "สรุปรายการที่พี่สั่งซื้อครับ: Nolimit Level Up+ Personal (ผูกบัตร) 1 ตัว = 1,100 บาท\n\nรวมยอดโอน: 1,100 บาท";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertEquals('1,100', $data['total']);
        $this->assertCount(1, $data['items']);
        $this->assertEquals('Nolimit Level Up+ Personal (ผูกบัตร)', $data['items'][0]['name']);
        $this->assertStringNotContainsString('สรุปรายการ', $data['items'][0]['name']);
        $this->assertStringNotContainsString('1 ตัว', $data['items'][0]['name']);
        $this->assertEquals(1, $data['items'][0]['qty']);
    }

    #[Test]
    public function test_a2_parses_item_with_รายการ_prefix_and_x_qty_colon_separator(): void
    {
        $text = "รายการ: Nolimit Level Up+ BM (เติมเงิน) x2 : 2,200 บาท\nรวมยอดโอน: 2,200 บาท";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertEquals('2,200', $data['total']);
        $this->assertCount(1, $data['items']);
        $this->assertEquals('Nolimit Level Up+ BM (เติมเงิน)', $data['items'][0]['name']);
        $this->assertEquals(2, $data['items'][0]['qty']);
    }

    #[Test]
    public function test_a3_parses_total_with_ยอดสุทธิ_keyword(): void
    {
        $text = "Nolimit Level Up+ Personal 1 ตัว = 1,100 บาท\nยอดสุทธิ 1,100 บาท";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertEquals('1,100', $data['total']);
        $this->assertCount(1, $data['items']);
        $this->assertEquals(1, $data['items'][0]['qty']);
    }

    #[Test]
    public function test_a4_strips_leading_emoji_from_item_name(): void
    {
        $text = "🔹 Nolimit Level Up+ BM (ผูกบัตร) = 1,100 บาท\nรวมยอดโอน: 1,100 บาท";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertEquals('1,100', $data['total']);
        $this->assertCount(1, $data['items']);
        $this->assertEquals('Nolimit Level Up+ BM (ผูกบัตร)', $data['items'][0]['name']);
    }

    #[Test]
    public function test_a5_normalizes_pipe_separator_to_newline(): void
    {
        $text = 'สรุปรายการที่พี่สั่งซื้อครับ:|||1. Nolimit Level Up+ Personal (ผูกบัตร) = 1,100 บาท|||รวมยอดโอน: 1,100 บาท';

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertEquals('1,100', $data['total']);
        $this->assertCount(1, $data['items']);
        $this->assertEquals('Nolimit Level Up+ Personal (ผูกบัตร)', $data['items'][0]['name']);
    }

    #[Test]
    public function test_a6_parses_decimal_total_without_corruption(): void
    {
        $text = "1. Nolimit Level Up+ Personal = 1,100.00 บาท\nรวมยอดโอน: 1,100.00 บาท";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertEquals('1,100.00', $data['total']);
        $this->assertCount(1, $data['items']);
        $this->assertEquals('1,100.00', $data['items'][0]['total']);
    }

    // ────────────────────────────────────────────────────────
    // Group B — regression / by-design, must keep working
    // ────────────────────────────────────────────────────────

    #[Test]
    public function test_b1_canonical_numbered_two_items_still_works(): void
    {
        $text = "1. X (1,100 x 1) = 1,100 บาท\n2. Page = 199 บาท\n\nรวมยอดโอน: 1,299 บาท";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertEquals('1,299', $data['total']);
        $this->assertCount(2, $data['items']);
        $this->assertEquals('X', $data['items'][0]['name']);
        $this->assertEquals('1,100', $data['items'][0]['total']);
        $this->assertEquals('Page', $data['items'][1]['name']);
        $this->assertEquals('199', $data['items'][1]['total']);
    }

    #[Test]
    public function test_b2_keeps_variant_parens_in_name(): void
    {
        $text = "Nolimit Level Up+ Personal (ผูกบัตร) 1 ตัว x 1,100 = 1,100 บาท\nรวมยอดโอน: 1,100 บาท";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertCount(1, $data['items']);
        $this->assertEquals('Nolimit Level Up+ Personal (ผูกบัตร)', $data['items'][0]['name']);
        $this->assertEquals('1,100', $data['items'][0]['price']);
        $this->assertEquals(1, $data['items'][0]['qty']);
    }

    #[Test]
    public function test_b3_total_only_line_is_not_captured_as_item(): void
    {
        $text = 'รวมยอดโอน: 1,100 บาท';

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertEquals('1,100', $data['total']);
        $this->assertEmpty($data['items']);
    }

    #[Test]
    public function test_b4_item_name_starting_with_ยอดนิยม_is_captured(): void
    {
        // False-negative fix: broad "ยอด" lookahead exclusion used to eat this line.
        $text = "ยอดนิยม Pack = 500 บาท\nรวมยอดโอน: 500 บาท";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertCount(1, $data['items']);
        $this->assertEquals('ยอดนิยม Pack', $data['items'][0]['name']);
        $this->assertEquals('500', $data['items'][0]['total']);
    }

    // ────────────────────────────────────────────────────────
    // Group C — qty correctness (money-critical)
    // ────────────────────────────────────────────────────────

    #[Test]
    public function test_c1_qty_2_tua_parsed_correctly_not_stuck_in_name(): void
    {
        $text = "Nolimit Level Up+ BM 2 ตัว = 2,200 บาท\nรวมยอดโอน: 2,200 บาท";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertCount(1, $data['items']);
        $this->assertEquals('Nolimit Level Up+ BM', $data['items'][0]['name']);
        $this->assertStringNotContainsString('2 ตัว', $data['items'][0]['name']);
        $this->assertEquals(2, $data['items'][0]['qty']);
    }

    #[Test]
    public function test_c2_bulleted_x_n_qty_not_swallowed_into_name(): void
    {
        // เคสจริง 2026-07-17 (delivery #35): "1. G3D x20" — regex หลักจับ "G3D x20"
        // เป็นชื่อสินค้าทั้งก้อน qty หาย → ระบบส่งบัญชีให้ลูกค้าแค่ 1 จาก 20
        $text = "สรุปรายการที่พี่สั่งซื้อครับ:\n\n1. G3D x20 = 1,000 บาท\n\nรวมยอดโอน: 1,000 บาท ✅";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertCount(1, $data['items']);
        $this->assertEquals('G3D', $data['items'][0]['name']);
        $this->assertEquals(20, $data['items'][0]['qty']);
        $this->assertEquals('1,000', $data['items'][0]['total']);
    }

    #[Test]
    public function test_c3_bulleted_unit_word_qty_not_swallowed_into_name(): void
    {
        $text = "1. G3D 20 ตัว = 1,000 บาท\nรวมยอดโอน: 1,000 บาท";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertCount(1, $data['items']);
        $this->assertEquals('G3D', $data['items'][0]['name']);
        $this->assertEquals(20, $data['items'][0]['qty']);
    }

    #[Test]
    public function test_c4_paren_price_qty_form_wins_over_trailing_strip(): void
    {
        $text = "1. G3D (50 x 20) = 1,000 บาท\nรวมยอดโอน: 1,000 บาท";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertCount(1, $data['items']);
        $this->assertEquals('G3D', $data['items'][0]['name']);
        $this->assertEquals(20, $data['items'][0]['qty']);
        $this->assertEquals('50', $data['items'][0]['price']);
    }
}
