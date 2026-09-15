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
            'round five file processing fee' => ['Please transfer the file processing fee to the agreed account.'],
            'round five file fee currency' => ['Please transfer the file fee of 199 THB to our bank account.'],
            'file fee is local financial object' => ['Please transfer the file processing fee.'],
            'image value currency symbol' => ['Please transfer the image value of $199.'],
            'document processing fee to account' => ['Please transfer the document processing fee to our bank account.'],
            'image processing fee with currency' => ['Please transfer the image processing fee of 199 THB.'],
            'file object with currency' => ['Please transfer the file value of 199 THB.'],
            'file object with amount reference' => ['Please transfer the file amount previously agreed.'],
            'document object with bank destination' => ['Please transfer the document fee to our bank.'],
            'completed proof followed by actual transfer' => ['Please send a receipt showing you did transfer the agreed amount and please transfer the file processing fee to our bank account.'],
            'thai completed proof followed by actual transfer' => ['กรุณาส่งหลักฐานว่าทำการโอนยอดเดิมเรียบร้อยแล้วให้ฝ่าย support แล้วกรุณาทำการชำระยอดเดิมครับ'],
            'no qr followed by actual payment' => ['ยอด 199 บาทครับ ตอนนี้ยังไม่มี QR สำหรับทำการชำระครับ แต่กรุณาทำการโอนยอดเดิมเข้าบัญชีเดิมครับ'],
            'attention followed by actual payment' => ['The current amount is ready. Please pay close attention to support and please pay now.'],
            'thai perform transfer directive' => ['กรุณาทำการโอนยอดเดิมเข้าบัญชีเดิมครับ'],
            'thai perform payment directive' => ['กรุณาทำการชำระยอดเดิมครับ'],
            'transfer before comma separated context' => ['Please transfer, using the agreed amount and account.'],
            'pay before coordinated receipt context' => ['Please pay and send the receipt for the agreed amount to support.'],
            'transfer before currency context' => ['Please transfer; the amount is 199.50 THB.'],
            'currency context before transfer' => ['ยอด 199.50 บาท แล้วกรุณาโอนได้เลยครับ'],
            'current reference before pay' => ['The current amount is ready. Please pay now.'],
            'previous reference after remit' => ['Please remit; use the previous amount.'],
            'local negation before positive with shared context' => ['Do not transfer the agreed amount today; please pay tomorrow.'],
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
    #[DataProvider('financialDiscussionPolicy')]
    public function it_applies_the_conservative_interlock_to_financial_discussion(string $text, bool $denied): void
    {
        $this->assertSame($denied, $this->detector()->detects($this->bot(), $text), $text);
    }

    public static function financialDiscussionPolicy(): array
    {
        // A2 adjudication: action-token FAQ/negation exceptions are deferred to C1.
        // Keep the complete prior corpus, with explicit expected decisions.
        return [
            'round five receipt completed transfer' => ['The agreed amount is ready. Please send a receipt showing you did transfer.', true],
            'round five thai proof completed transfer' => ['กรุณาส่งหลักฐานว่าทำการโอนยอดเดิมเรียบร้อยแล้วให้ฝ่าย support ครับ', true],
            'round five no qr perform payment' => ['ยอด 199 บาทครับ ตอนนี้ยังไม่มี QR สำหรับทำการชำระครับ', true],
            'round five pay close attention' => ['The current amount is ready. Please pay close attention to support.', true],
            'document transfer with shared currency' => ['Page costs 199 THB. Please transfer the document to support.', true],
            'image transfer with shared amount' => ['The agreed amount is ready. Now transfer these images to support.', true],
            'file fee negated' => ['Please do not transfer the file processing fee to the agreed account.', true],
            'document fee cannot transfer' => ['You cannot transfer the document fee to our bank account.', true],
            'receipt completed transfer known account' => ['Please send a receipt showing you did transfer to account 987-6-54321-0.', true],
            'thai proof completed transfer known account' => ['กรุณาส่งหลักฐานว่าทำการโอนเข้าบัญชี 987-6-54321-0 เรียบร้อยแล้วให้ฝ่าย support ครับ', true],
            'receipt completed pay reference' => ['The current amount is ready. Please send proof that you did pay.', true],
            'transfer capability unavailable' => ['The option to transfer the agreed amount is currently unavailable.', true],
            'no qr infinitive transfer reference' => ['The agreed amount is ready. No QR is available to transfer to our bank account.', true],
            'thai no qr nominal transfer reference' => ['ยอด 199 บาทครับ ตอนนี้ไม่มี QR สำหรับดำเนินการโอนครับ', true],
            'completed transfer statement' => ['You did transfer the agreed amount to our bank account.', true],
            'genuine nominal policy' => ['นโยบายการโอนเงินเป็นอย่างไร', true],
            'negated thai perform transfer' => ['กรุณาอย่าทำการโอนยอดเดิมเข้าบัญชีเดิมครับ', true],
            'negated thai perform payment' => ['ไม่ต้องทำการชำระยอดเดิมครับ', true],
            'split context with local negation' => ['Please do not transfer, using the agreed amount and account.', true],
            'split context with negated pay' => ['Please do not pay and send the receipt for the agreed amount to support.', true],
            'receipt only with shared context' => ['The agreed amount is ready. Please send proof of transfer to support.', true],
            'thai receipt only with shared currency' => ['ยอด 199 บาท แล้วกรุณาส่งหลักฐานการโอนให้ฝ่าย support', true],
            'policy only with shared context' => ['The current amount is ready. Our bank policy allows customers to transfer to this account.', true],
            'thai policy only with shared currency' => ['ยอด 199 บาท นโยบายการโอนเงินเป็นอย่างไร', true],
            'support only with shared currency' => ['The amount is 199 THB. Please contact support to discuss how to transfer.', true],
            'no qr with shared context' => ['The agreed amount is ready. ตอนนี้ร้านยังไม่มี QR สำหรับรับชำระครับ', true],
            'product price with shared context' => ['The agreed amount is ready. Page ราคา 199 บาทครับ', false],
            'file transfer with shared currency' => ['Page costs 199 THB. Now transfer the file to support.', true],
            'file transfer with shared reference' => ['The agreed amount is ready. Please transfer files to support.', true],
            'file transfer with shared configured account' => ['Please send the receipt for account 987-6-54321-0. Now transfer the file to support.', true],
            'pay attention with shared currency' => ['Page costs 199 THB. Please pay attention to the product price.', true],
            'pay attention with shared reference' => ['The current amount is ready. Please pay attention to support.', true],
            'pay attention with shared configured account' => ['Please send the receipt for account 987-6-54321-0. Please pay attention to support.', true],
            'generic account is not shared financial context' => ['Our account is ready. Please transfer the file to support.', true],
            'cannot transfer' => ['ไม่สามารถโอนเข้าบัญชีเดิมได้ในขณะนี้ครับ', true],
            'cannot transfer to known account' => ['ไม่สามารถโอนเข้าบัญชี 223-3-24880-3 ได้ในขณะนี้ครับ', true],
            'cannot transfer with spaced modal' => ['ไม่สามารถ โอนเข้าบัญชีเดิมได้ในขณะนี้ครับ', true],
            'english cannot transfer' => ['You cannot transfer the agreed amount to our bank account.', true],
            'negative transfer and receipt' => ['ห้ามโอนเข้าบัญชี 223-3-24880-3 แล้วส่งสลิปเดิมให้ฝ่าย support', true],
            'two negative clauses' => ['ยังไม่ต้องโอนตอนนี้ และห้ามโอนเข้าบัญชีเดิมพรุ่งนี้', true],
            'receipt of transfer' => ['กรุณาส่งหลักฐานการโอนเข้าบัญชี 223-3-24880-3 ครับ', true],
            'english receipt of transfer' => ['Please send proof of transfer to bank account 223-3-24880-3.', true],
            'support transfer discussion' => ['Please contact support to discuss how to transfer to our bank account.', true],
            'support about transfer' => ['Please contact support about transfer to our bank account.', true],
            'receipt for transfer' => ['Please send a receipt for transfer to our bank account.', true],
            'policy explains transfer capability' => ['Our bank policy allows customers to transfer to this account.', true],
            'no qr known account' => ['ยังไม่มี QR สำหรับบัญชี 223-3-24880-3 ครับ', true],
            'unrelated transfer' => ['Now transfer the file to support.', true],
            'unrelated pay' => ['Please pay attention to the product price.', true],
            'product price answer' => ['Page ราคา 199 บาทครับ', false],
            'product price question' => ['G3D ราคาเท่าไรครับ', false],
            'shop has no qr' => ['ตอนนี้ร้านยังไม่มี QR สำหรับรับชำระครับ', true],
            'bank policy faq thai' => ['นโยบายธนาคารสำหรับการโอนเงินเป็นอย่างไรครับ', true],
            'bank policy faq english' => ['What is your bank transfer policy?', true],
            'negative transfer' => ['ยังไม่ต้องโอนเข้าบัญชีเดิมครับ', true],
            'prohibited transfer' => ['ห้ามโอนยอดเดิมเข้าบัญชีเดิมครับ', true],
            'negative payment' => ['ไม่ต้องชำระยอดที่ตกลงไว้ครับ', true],
            'english negative transfer' => ['Please do not transfer the agreed amount yet.', true],
            'negative transfer with known account' => ['ห้ามโอนเข้าบัญชี 223-3-24880-3 ครับ', true],
            'receipt request' => ['กรุณาส่งสลิปหรือหลักฐานการชำระเงินให้ฝ่าย support', true],
            'receipt request with known account' => ['กรุณาส่งสลิปจากบัญชี 223-3-24880-3 ให้ฝ่าย support', true],
            'support discussion' => ['ติดต่อฝ่าย support เพื่อสอบถามเรื่องการโอนผ่านธนาคาร', true],
            'bank policy with known account' => ['นโยบายธนาคารสำหรับบัญชี 223-3-24880-3 เป็นอย่างไรครับ', true],
            'shop capability statement' => ['ร้านรับโอนผ่านธนาคารครับ', true],
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
