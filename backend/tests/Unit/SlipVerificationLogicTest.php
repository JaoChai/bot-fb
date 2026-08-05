<?php

namespace Tests\Unit;

use App\Services\OpenRouterService;
use App\Services\Payment\LLMOrderItemExtractor;
use App\Services\Payment\OrderReconstructor;
use App\Services\Payment\PaymentMessageDetector;
use App\Services\Payment\SlipVerificationService;
use App\Services\Payment\TelegramAlertBotService;
use PHPUnit\Framework\TestCase;

class SlipVerificationLogicTest extends TestCase
{
    private function service(): SlipVerificationService
    {
        $openRouter = $this->createMock(OpenRouterService::class);

        return new SlipVerificationService(
            new PaymentMessageDetector,
            new TelegramAlertBotService,
            new LLMOrderItemExtractor($openRouter),
            new OrderReconstructor($openRouter),
        );
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

    public function test_summary_keeps_quantity_when_more_than_one(): void
    {
        // ข้อความจริงจาก prod 2026-07-25 (ออเดอร์ #1672): ลูกค้าสั่งอย่างละ 2 ชุด
        // summary ที่ทิ้ง qty ทำให้ข้อความยืนยัน/การ์ด Telegram/order_items บันทึกเหลือ 1
        $history = [
            ['sender' => 'bot', 'content' => "สรุปรายการที่พี่สั่งซื้อครับ:\n\n1. Nolimit Level Up+ BM (1,000 x 2) = 2,000 บาท\n2. บริการเสริม Page (199 x 2) = 398 บาท\n\nรวมยอดโอน: 2,398 บาท ✅\n\nรบกวนโอนเข้าบัญชี:\nธนาคารกสิกรไทย (KBANK)\n223-3-24880-3"],
            ['sender' => 'user', 'content' => '[รูปภาพ]'],
        ];

        $result = $this->service()->findExpectedPayment($history);

        $this->assertNotNull($result);
        $this->assertSame(2398.0, $result['total']);
        $this->assertSame('Nolimit Level Up+ BM x2, บริการเสริม Page x2', $result['summary']);
    }

    public function test_no_payment_message_returns_null(): void
    {
        $history = [
            ['sender' => 'user', 'content' => 'สวัสดีครับ'],
            ['sender' => 'bot', 'content' => 'สวัสดีครับ มีอะไรให้ช่วยไหมครับ'],
        ];

        $this->assertNull($this->service()->findExpectedPayment($history));
    }

    // --- matchAmount: หลายใบสรุปค้างพร้อมกัน (เคสจริง #1253 2026-08-05) ---

    public function test_match_amount_picks_older_summary_over_latest(): void
    {
        // ใบ Page 199 ออกทับใบ BM 1,100 — สลิป 1,100 ต้องเจอใบ BM ไม่ใช่ mismatch กับใบ 199
        $history = [
            ['sender' => 'bot', 'content' => "สรุปรายการที่พี่สั่งซื้อครับ:\n\n1. Nolimit Level Up+ BM (เติมเงิน) (1,100 x 1) = 1,100 บาท\n\nรวมยอดโอน: 1,100 บาท ✅\n\nรบกวนโอนเข้าบัญชี:\nธนาคารกสิกรไทย (KBANK)\n223-3-24880-3"],
            ['sender' => 'user', 'content' => 'เพจด้วยค่ะ'],
            ['sender' => 'bot', 'content' => "สรุปรายการที่พี่สั่งซื้อครับ:\n\n1. Page (199 x 1) = 199 บาท\n\nรวมยอดโอน: 199 บาท ✅\n\nรบกวนโอนเข้าบัญชี:\nธนาคารกสิกรไทย (KBANK)\n223-3-24880-3"],
            ['sender' => 'user', 'content' => '[รูปภาพ]'],
        ];

        $result = $this->service()->findExpectedPayment($history, matchAmount: 1100.0);

        $this->assertNotNull($result);
        $this->assertSame(1100.0, $result['total']);
        $this->assertSame('Nolimit Level Up+ BM (เติมเงิน)', $result['summary']);

        // สลิป 199 ยังต้องเจอใบ Page ตามปกติ
        $result = $this->service()->findExpectedPayment($history, matchAmount: 199.0);
        $this->assertSame(199.0, $result['total']);
    }

    public function test_match_amount_with_no_matching_summary_returns_null(): void
    {
        $history = [
            ['sender' => 'bot', 'content' => "สรุปรายการ\n1. Nolimit BM = 1,100 บาท\nรวมยอดโอน: 1,100 บาท\nโอนเข้าบัญชี 223-3-24880-3"],
        ];

        $this->assertNull($this->service()->findExpectedPayment($history, matchAmount: 500.0));
    }

    public function test_summary_before_verify_success_is_consumed(): void
    {
        // ใบสรุปที่อยู่ก่อน "เงินเข้าแล้ว" = จ่ายไปแล้ว — เงินโอนใหม่ยอดเท่ากัน
        // ต้องไม่ auto-match ออเดอร์เก่า (ไม่งั้นโอนซ้ำโดยบังเอิญ = ส่งของซ้ำ)
        $history = [
            ['sender' => 'bot', 'content' => "สรุปรายการ\n1. Nolimit BM = 1,100 บาท\nรวมยอดโอน: 1,100 บาท\nโอนเข้าบัญชี 223-3-24880-3"],
            ['sender' => 'user', 'content' => '[รูปภาพ]'],
            ['sender' => 'bot', 'content' => "เงินเข้าแล้ว 1,100 บาท ✅\nออเดอร์: Nolimit BM\nส่งใน 5-10 นาที ขอบคุณครับ\n[ยืนยันชำระเงิน]"],
            ['sender' => 'user', 'content' => '[รูปภาพ]'],
        ];

        $this->assertNull($this->service()->findExpectedPayment($history, matchAmount: 1100.0));
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
