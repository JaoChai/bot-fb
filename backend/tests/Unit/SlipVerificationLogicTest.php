<?php

namespace Tests\Unit;

use App\Services\Payment\PaymentMessageDetector;
use App\Services\Payment\SlipVerificationService;
use App\Services\Payment\TelegramAlertBotService;
use PHPUnit\Framework\TestCase;

class SlipVerificationLogicTest extends TestCase
{
    private function service(): SlipVerificationService
    {
        return new SlipVerificationService(new PaymentMessageDetector, new TelegramAlertBotService);
    }

    // --- accountMatches: เทียบเลขบัญชีที่ EasySlip mask มา กับเลขที่ตั้งค่าไว้ ---

    public function test_account_matches_masked_account(): void
    {
        // configured: 223-3-24880-3 → digits 2233248803
        // masked:     xxx-x-x4880-x → ตำแหน่งจากท้าย: x,0,8,8,4,x,x,x,x,x
        $this->assertTrue(SlipVerificationService::accountMatches('223-3-24880-3', 'xxx-x-x4880-x'));
    }

    public function test_account_mismatch_detected(): void
    {
        $this->assertFalse(SlipVerificationService::accountMatches('223-3-24880-3', 'xxx-x-x9999-x'));
    }

    public function test_account_with_no_visible_digits_fails(): void
    {
        $this->assertFalse(SlipVerificationService::accountMatches('223-3-24880-3', 'xxx-x-xxxxx-x'));
    }

    public function test_account_masked_longer_than_configured_fails(): void
    {
        $this->assertFalse(SlipVerificationService::accountMatches('4880', 'xxx-x-x4880-x'));
    }

    // --- findExpectedPayment: หายอดออเดอร์ล่าสุดจาก history ---

    public function test_finds_latest_payment_total_from_history(): void
    {
        $history = [
            ['sender' => 'user', 'content' => 'สนใจ BM ครับ'],
            ['sender' => 'bot', 'content' => "สรุปรายการ\n1. Nolimit BM = 1,500 บาท\nรวมยอดโอน: 1,500 บาท\nโอนเข้าบัญชี 223-3-24880-3"],
            ['sender' => 'user', 'content' => 'โอเค'],
        ];

        $result = $this->service()->findExpectedPayment($history);

        $this->assertNotNull($result);
        $this->assertSame(1500.0, $result['total']);
        $this->assertSame('Nolimit BM', $result['summary']);
    }

    public function test_finds_summary_when_items_have_no_bullets(): void
    {
        // Format drift 2026-07-10: โมเดลใหม่ไม่ใส่ bullet → summary เคยกลายเป็น "-"
        $history = [
            ['sender' => 'bot', 'content' => "สรุปรายการที่พี่สั่งซื้อครับ:\n\nNolimit Level Up+ Personal (ผูกบัตร) 1 ตัว x 1,100 = 1,100 บาท\nบริการเสริม Page = 0 บาท\n\nรวมยอดโอน: 1,100 บาท ✅\n\nรบกวนโอนเข้าบัญชี:\nธนาคารกสิกรไทย (KBANK)\n223-3-24880-3"],
            ['sender' => 'user', 'content' => '[รูปภาพ]'],
        ];

        $result = $this->service()->findExpectedPayment($history);

        $this->assertNotNull($result);
        $this->assertSame(1100.0, $result['total']);
        // Page = 0 บาท เป็นของแถม → ตัดออกจาก summary เหลือแค่สินค้าจริง
        $this->assertSame('Nolimit Level Up+ Personal (ผูกบัตร)', $result['summary']);
    }

    public function test_filters_zero_price_freebie_from_summary_real_prod_format(): void
    {
        // ข้อความจริงจาก prod 2026-07-13 (msg 80713) ที่ทำให้ "Page ×1" ปลอมหลุดไป
        // Telegram alert + order_items ทั้งที่ลูกค้าสั่งแค่ BM ตัวเดียว
        $history = [
            ['sender' => 'bot', 'content' => "สรุปรายการที่พี่สั่งซื้อครับ:\n\n1. Nolimit Level Up+ BM (ผูกบัตร) (1,100 x 1) = 1,100 บาท\n\n2. บริการเสริม Page = 0 บาท\n\nรวมยอดโอน: 1,100 บาท ✅\n\n------------------------------\n\nรบกวนโอนเข้าบัญชี:\nธนาคารกสิกรไทย (KBANK)\n223-3-24880-3\nชื่อบัญชี: หจก. มั่งมีทรัพย์ขายของออนไลน์"],
            ['sender' => 'user', 'content' => '[รูปภาพ]'],
        ];

        $result = $this->service()->findExpectedPayment($history);

        $this->assertNotNull($result);
        $this->assertSame(1100.0, $result['total']);
        $this->assertSame('Nolimit Level Up+ BM (ผูกบัตร)', $result['summary']);
    }

    public function test_keeps_page_in_summary_when_it_has_real_price(): void
    {
        // Page ที่มีราคาจริง (199) = ซื้อจริง → ห้ามกรอง, เฉพาะราคา 0 ถึงตัด
        $history = [
            ['sender' => 'bot', 'content' => "สรุปรายการที่พี่สั่งซื้อครับ:\n\nNolimit Level Up+ Personal (ผูกบัตร) 1 ตัว x 1,100 = 1,100 บาท\nบริการเสริม Page = 199 บาท\n\nรวมยอดโอน: 1,299 บาท ✅\n\nรบกวนโอนเข้าบัญชี:\nธนาคารกสิกรไทย (KBANK)\n223-3-24880-3"],
            ['sender' => 'user', 'content' => '[รูปภาพ]'],
        ];

        $result = $this->service()->findExpectedPayment($history);

        $this->assertNotNull($result);
        $this->assertSame(1299.0, $result['total']);
        $this->assertSame('Nolimit Level Up+ Personal (ผูกบัตร), บริการเสริม Page', $result['summary']);
    }

    public function test_no_payment_message_returns_null(): void
    {
        $history = [
            ['sender' => 'user', 'content' => 'สวัสดีครับ'],
            ['sender' => 'bot', 'content' => 'สวัสดีครับ มีอะไรให้ช่วยไหมครับ'],
        ];

        $this->assertNull($this->service()->findExpectedPayment($history));
    }

    public function test_finds_payment_total_using_configured_receiver_account(): void
    {
        $history = [
            ['sender' => 'user', 'content' => 'สนใจ BM ครับ'],
            ['sender' => 'bot', 'content' => "สรุปรายการ\n1. Nolimit BM = 2,000 บาท\nรวมยอดโอน: 2,000 บาท\nโอนเข้าบัญชี 111-2-33333-4"],
            ['sender' => 'user', 'content' => 'โอเค'],
        ];

        $result = $this->service()->findExpectedPayment($history, '111-2-33333-4');

        $this->assertNotNull($result);
        $this->assertSame(2000.0, $result['total']);

        $this->assertNull($this->service()->findExpectedPayment($history));
    }
}
