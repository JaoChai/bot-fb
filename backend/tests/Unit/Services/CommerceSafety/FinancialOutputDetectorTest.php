<?php

namespace Tests\Unit\Services\CommerceSafety;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Services\CommerceSafety\FinancialOutputDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinancialOutputDetectorTest extends TestCase
{
    #[Test]
    #[DataProvider('contextualPaymentDirectives')]
    public function it_detects_contextual_payment_directives_without_currency_digits(string $text): void
    {
        $this->assertTrue($this->detector()->detects($this->bot(), $text), $text);
    }

    public static function contextualPaymentDirectives(): array
    {
        return [
            'thai polite prefix' => ['ได้เลยครับ โอนยอดเดิมเข้าบัญชีเดิมได้เลยครับ'],
            'english now prefix' => ['Now transfer the agreed amount to our bank account.'],
            'amount transfer and receipt' => ['ยอด 199 บาท โอนเข้าบัญชี 223-3-24880-3 แล้วส่งสลิปได้เลยครับ'],
            'configured account transfer and receipt' => ['ยอด 199 บาท โอนเข้าบัญชี 987-6-54321-0 แล้วส่งสลิปได้เลยครับ'],
            'receipt before transfer' => ['ส่งสลิปให้ฝ่าย support แล้วโอนยอดเดิมเข้าบัญชีเดิมได้เลย'],
            'english receipt and transfer' => ['Please send the receipt, then transfer the agreed amount to our bank account.'],
            'thai later positive directive' => ['ยังไม่ต้องโอนตอนนี้ แต่พรุ่งนี้โอนยอดเดิมเข้าบัญชีเดิมได้เลย'],
            'thai later positive known account' => ['ห้ามโอนวันนี้ แต่พรุ่งนี้โอนเข้าบัญชี 223-3-24880-3 ได้เลย'],
            'english later positive directive' => ['Please do not transfer now, but tomorrow transfer the agreed amount to our bank account.'],
            'positive before negative' => ['Now transfer the agreed amount to our bank account. Please do not transfer again.'],
            'receipt does not exempt separate account' => ["ส่งสลิปได้เลยครับ\nบัญชี 223-3-24880-3 ครับ"],
            'receipt account and separate transfer' => ['กรุณาส่งสลิปจากบัญชี 987-6-54321-0 แล้วโอนได้เลยครับ'],
            'english separate transfer and receipt account' => ['Now transfer please. Send the receipt for account 987-6-54321-0.'],
            'thai original bypass' => ['โอนยอดเดิมเข้าบัญชีเดิมได้เลยครับ'],
            'thai bare amount bypass' => ['โอน 199 เข้าบัญชีเดิมได้เลยครับ'],
            'english original bypass' => ['Please transfer the agreed amount to our bank account now.'],
            'thai prior amount and account synonyms' => ['กรุณาชำระยอดก่อนหน้าเข้าบัญชีเดิมครับ'],
            'thai agreed amount and bank synonyms' => ['โปรดจ่ายยอดที่ตกลงไว้เข้าธนาคารได้เลย'],
            'thai current amount and account synonyms' => ['ช่วยโอนยอดปัจจุบันเข้าบัญชีนี้ตอนนี้'],
            'english remit previous and same synonyms' => ['Please remit the previous amount to the same account now.'],
            'english pay current and agreed synonyms' => ['Pay the current amount into the agreed bank account.'],
            'english transfer prior amount to account' => ['You can transfer the prior amount to our account now.'],
            'english payment directive synonym' => ['Kindly make payment of the agreed amount to our bank account.'],
        ];
    }

    #[Test]
    #[DataProvider('nonDirectiveFinancialDiscussion')]
    public function it_preserves_non_directive_financial_discussion(string $text): void
    {
        $this->assertFalse($this->detector()->detects($this->bot(), $text), $text);
    }

    public static function nonDirectiveFinancialDiscussion(): array
    {
        return [
            'cannot transfer' => ['ไม่สามารถโอนเข้าบัญชีเดิมได้ในขณะนี้ครับ'],
            'cannot transfer to known account' => ['ไม่สามารถโอนเข้าบัญชี 223-3-24880-3 ได้ในขณะนี้ครับ'],
            'cannot transfer with spaced modal' => ['ไม่สามารถ โอนเข้าบัญชีเดิมได้ในขณะนี้ครับ'],
            'english cannot transfer' => ['You cannot transfer the agreed amount to our bank account.'],
            'negative transfer and receipt' => ['ห้ามโอนเข้าบัญชี 223-3-24880-3 แล้วส่งสลิปเดิมให้ฝ่าย support'],
            'two negative clauses' => ['ยังไม่ต้องโอนตอนนี้ และห้ามโอนเข้าบัญชีเดิมพรุ่งนี้'],
            'receipt of transfer' => ['กรุณาส่งหลักฐานการโอนเข้าบัญชี 223-3-24880-3 ครับ'],
            'english receipt of transfer' => ['Please send proof of transfer to bank account 223-3-24880-3.'],
            'support transfer discussion' => ['Please contact support to discuss how to transfer to our bank account.'],
            'support about transfer' => ['Please contact support about transfer to our bank account.'],
            'receipt for transfer' => ['Please send a receipt for transfer to our bank account.'],
            'policy explains transfer capability' => ['Our bank policy allows customers to transfer to this account.'],
            'no qr known account' => ['ยังไม่มี QR สำหรับบัญชี 223-3-24880-3 ครับ'],
            'unrelated transfer' => ['Now transfer the file to support.'],
            'unrelated pay' => ['Please pay attention to the product price.'],
            'product price answer' => ['Page ราคา 199 บาทครับ'],
            'product price question' => ['G3D ราคาเท่าไรครับ'],
            'shop has no qr' => ['ตอนนี้ร้านยังไม่มี QR สำหรับรับชำระครับ'],
            'bank policy faq thai' => ['นโยบายธนาคารสำหรับการโอนเงินเป็นอย่างไรครับ'],
            'bank policy faq english' => ['What is your bank transfer policy?'],
            'negative transfer' => ['ยังไม่ต้องโอนเข้าบัญชีเดิมครับ'],
            'prohibited transfer' => ['ห้ามโอนยอดเดิมเข้าบัญชีเดิมครับ'],
            'negative payment' => ['ไม่ต้องชำระยอดที่ตกลงไว้ครับ'],
            'english negative transfer' => ['Please do not transfer the agreed amount yet.'],
            'negative transfer with known account' => ['ห้ามโอนเข้าบัญชี 223-3-24880-3 ครับ'],
            'receipt request' => ['กรุณาส่งสลิปหรือหลักฐานการชำระเงินให้ฝ่าย support'],
            'receipt request with known account' => ['กรุณาส่งสลิปจากบัญชี 223-3-24880-3 ให้ฝ่าย support'],
            'support discussion' => ['ติดต่อฝ่าย support เพื่อสอบถามเรื่องการโอนผ่านธนาคาร'],
            'bank policy with known account' => ['นโยบายธนาคารสำหรับบัญชี 223-3-24880-3 เป็นอย่างไรครับ'],
            'shop capability statement' => ['ร้านรับโอนผ่านธนาคารครับ'],
        ];
    }

    private function detector(): FinancialOutputDetector
    {
        return app(FinancialOutputDetector::class);
    }

    private function bot(): Bot
    {
        $bot = new Bot;
        $bot->setRelation('settings', new BotSetting([
            'slip_receiver_account' => '987-6-54321-0',
        ]));

        return $bot;
    }
}
