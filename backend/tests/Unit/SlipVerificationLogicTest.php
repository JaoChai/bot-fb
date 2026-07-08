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
