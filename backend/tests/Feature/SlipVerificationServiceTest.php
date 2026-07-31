<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\Payment\LLMOrderItemExtractor;
use App\Services\Payment\PaymentMessageDetector;
use App\Services\Payment\SlipVerificationResult;
use App\Services\Payment\SlipVerificationService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlipVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private array $paymentHistory;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['easyslip_api_token' => 'tok-123']);

        $this->bot = Bot::factory()->create(['user_id' => $user->id]);
        BotSetting::create([
            'bot_id' => $this->bot->id,
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
            'slip_amount_tolerance' => 0,
        ]);

        $this->paymentHistory = [
            ['sender' => 'bot', 'content' => "สรุปรายการ\n1. Nolimit BM = 1,500 บาท\nรวมยอดโอน: 1,500 บาท\nโอนเข้าบัญชี 223-3-24880-3"],
        ];
    }

    private function easySlipResponse(float $amount = 1500, string $transRef = 'TR100', string $account = 'xxx-x-x4880-x'): array
    {
        return [
            'success' => true,
            'data' => [
                'isDuplicate' => false,
                'matchedAccount' => null,
                'amountInSlip' => $amount,
                'rawSlip' => [
                    'transRef' => $transRef,
                    'amount' => ['amount' => $amount],
                    'receiver' => [
                        'bank' => ['id' => '004'],
                        'account' => ['name' => ['th' => 'ร้านค้า'], 'bank' => ['account' => $account]],
                    ],
                ],
            ],
            'message' => 'success',
        ];
    }

    private function verify(?\Closure $isSlipCheck = null): SlipVerificationResult
    {
        return app(SlipVerificationService::class)->verify(
            $this->bot, null, null, 'https://example.com/slip.jpg', $this->paymentHistory, $isSlipCheck
        );
    }

    public function test_valid_slip_passes_all_checks(): void
    {
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertTrue($result->isSlip);
        $this->assertTrue($result->passed);
        $this->assertSame(1500.0, $result->amount);
        $this->assertSame('Nolimit BM', $result->orderSummary);
        $this->assertDatabaseHas('slip_verifications', ['trans_ref' => 'TR100', 'status' => 'passed']);
    }

    public function test_wrong_receiver_account_fails(): void
    {
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse(account: 'xxx-x-x9999-x'))]);

        $result = $this->verify();

        $this->assertTrue($result->isSlip);
        $this->assertFalse($result->passed);
        $this->assertSame('wrong_account', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'wrong_account']);
    }

    public function test_duplicate_trans_ref_fails(): void
    {
        SlipVerification::create(['bot_id' => $this->bot->id, 'trans_ref' => 'TR100', 'status' => 'passed']);
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertFalse($result->passed);
        $this->assertSame('duplicate', $result->failReason);
    }

    public function test_easyslip_passes_after_manual_confirm_and_lets_owner_decide(): void
    {
        // เจ้าของกดยืนยันเงินเองไปแล้ว แล้วสลิปเข้ามาทีหลัง — ระบบแยกไม่ออกว่าเป็นเงินก้อนเดิม
        // หรือลูกค้าซื้อซ้ำ. ห้ามปฏิเสธสลิปต่อหน้าลูกค้า: ปล่อยผ่านแล้วให้การ์ดงานส่งของ
        // เตือนเจ้าของว่ายอดซ้ำ (ดู AccountDeliveryService::duplicateWarning)
        $conversation = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        config(['delivery.enabled' => true]);
        $this->bot->update(['auto_delivery_enabled' => true]);
        SlipVerification::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $conversation->id,
            'amount' => 1500, 'status' => 'manual_confirmed',
        ]);
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = app(SlipVerificationService::class)->verify(
            $this->bot, $conversation, null, 'https://example.com/slip.jpg', $this->paymentHistory
        );

        $this->assertTrue($result->passed);
    }

    public function test_amount_mismatch_fails(): void
    {
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse(amount: 1000))]);

        $result = $this->verify();

        $this->assertFalse($result->passed);
        $this->assertSame('amount_mismatch', $result->failReason);
        $this->assertSame(1500.0, $result->expectedAmount);
    }

    public function test_amount_within_tolerance_passes(): void
    {
        $this->bot->settings->update(['slip_amount_tolerance' => 10]);
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse(amount: 1495))]);

        $this->assertTrue($this->verify()->passed);
    }

    public function test_no_pending_order_fails(): void
    {
        $this->paymentHistory = [['sender' => 'user', 'content' => 'สวัสดี']];
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertFalse($result->passed);
        $this->assertSame('no_pending_order', $result->failReason);
    }

    public function test_http_400_without_pending_order_means_not_a_slip(): void
    {
        // No pending order in history → a 400 (unreadable) image is just a non-slip → vision.
        $this->paymentHistory = [['sender' => 'user', 'content' => 'สวัสดี']];
        Http::fake(['api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400)]);

        $result = $this->verify();

        $this->assertFalse($result->isSlip);
        $this->assertNull($result->failReason);
        $this->assertSame(0, SlipVerification::count());
    }

    public function test_http_400_with_pending_order_is_unreadable(): void
    {
        // With a pending order in history, a 400 is almost certainly an unreadable slip.
        Http::fake(['api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400)]);

        $result = $this->verify();

        $this->assertTrue($result->isSlip);
        $this->assertFalse($result->passed);
        $this->assertSame('unreadable', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'unreadable']);
    }

    public function test_http_404_means_fake_slip(): void
    {
        Http::fake(['api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'SLIP_NOT_FOUND', 'message' => 'slip not found']], 404)]);

        $result = $this->verify();

        $this->assertTrue($result->isSlip);
        $this->assertSame('fake', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'fake']);
    }

    public function test_http_404_slip_pending_means_pending(): void
    {
        Http::fake(['api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'SLIP_PENDING', 'message' => 'slip pending']], 404)]);

        $result = $this->verify();

        $this->assertTrue($result->isSlip);
        $this->assertSame('pending', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'pending']);
    }

    public function test_server_error_is_api_error(): void
    {
        Http::fake(['api.easyslip.com/*' => Http::response(['message' => 'server error'], 500)]);

        $result = $this->verify();

        $this->assertFalse($result->isSlip);
        $this->assertSame('api_error', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'api_error']);
    }

    public function test_connection_exception_is_api_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $result = $this->verify();

        $this->assertFalse($result->isSlip);
        $this->assertSame('api_error', $result->failReason);
    }

    public function test_missing_token_is_config_error_without_http_call(): void
    {
        $this->bot->user->settings->update(['easyslip_api_token' => null]);
        Http::fake();

        $result = $this->verify();

        $this->assertSame('config_error', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'config_error']);
        Http::assertNothingSent();
    }

    public function test_api_error_stays_silent_when_vision_says_not_a_slip(): void
    {
        // เคสจริง prod 27 ก.ค. (แชท #361, slip_verifications id=116): ลูกค้าส่ง screenshot
        // หน้าเพจ FB ตอน EasySlip timeout → เดิมเด้งการ์ด "ยืนยันยอด" หาเจ้าของทั้งที่ไม่ใช่สลิป
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $result = $this->verify(fn (): ?bool => false);

        $this->assertFalse($result->isSlip);
        $this->assertNull($result->failReason);
        $this->assertSame(0, SlipVerification::count());
    }

    public function test_api_error_still_records_when_vision_says_slip(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $result = $this->verify(fn (): ?bool => true);

        $this->assertSame('api_error', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'api_error']);
    }

    public function test_api_error_records_when_vision_is_unsure(): void
    {
        // fail-safe: vision parse ไม่ได้/เรียกไม่ได้ (null) ต้องเข้าข้างเงิน = alert ไว้ก่อน
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $result = $this->verify(fn (): ?bool => null);

        $this->assertSame('api_error', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'api_error']);
    }

    public function test_missing_token_stays_silent_when_vision_says_not_a_slip(): void
    {
        // token หาย + รูปทั่วไป → ไม่ควรเด้ง config_error ทุกรูปที่ลูกค้าส่งมา
        $this->bot->user->settings->update(['easyslip_api_token' => null]);
        Http::fake();

        $result = $this->verify(fn (): ?bool => false);

        $this->assertFalse($result->isSlip);
        $this->assertSame(0, SlipVerification::count());
        Http::assertNothingSent();
    }

    public function test_slip_passes_against_cart_confirm_message_when_no_payment_summary(): void
    {
        // เคสจริง slip_verifications id=67 (แชท #1072): ลูกค้าขาประจำโอนทันทีหลังบอทพิมพ์
        // ข้อความตะกร้า โดยไม่รอข้อความสรุปยอด+เลขบัญชี → เดิมได้ no_pending_order + กวนเจ้าของ
        $this->paymentHistory = [
            ['sender' => 'user', 'content' => 'เอา Nolimit Personal 1 เฟสครับ'],
            ['sender' => 'bot', 'content' => "เพิ่มลงตะกร้าแล้วครับ\n1. Nolimit BM = 1,500 บาท\nรวม: 1,500 บาท\nถูกต้องไหมครับ? พิมพ์ “ยืนยัน” ได้เลย"],
            ['sender' => 'user', 'content' => 'ยืนยัน'],
        ];
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertTrue($result->passed);
        $this->assertSame(1500.0, $result->amount);
        $this->assertSame('Nolimit BM', $result->orderSummary);
        $this->assertDatabaseHas('slip_verifications', ['trans_ref' => 'TR100', 'status' => 'passed']);
    }

    public function test_cart_confirm_with_different_amount_still_no_pending_order(): void
    {
        // ตะกร้า 900 แต่สลิป 1,500 → ห้ามผ่าน ต้องให้เจ้าของตัดสิน
        $this->paymentHistory = [
            ['sender' => 'bot', 'content' => "เพิ่มลงตะกร้าแล้วครับ\n1. Nolimit BM = 900 บาท\nรวม: 900 บาท\nถูกต้องไหมครับ? พิมพ์ “ยืนยัน” ได้เลย"],
        ];
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertFalse($result->passed);
        $this->assertSame('no_pending_order', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'no_pending_order']);
    }

    public function test_payment_summary_still_wins_over_cart_message(): void
    {
        // มีทั้งข้อความตะกร้า (900) และข้อความสรุปยอด+เลขบัญชี (1,500)
        // → ต้องใช้ข้อความสรุปยอดเป็นหลัก fallback ห้ามแย่งงาน
        $this->paymentHistory = [
            ['sender' => 'bot', 'content' => "เพิ่มลงตะกร้าแล้วครับ\n1. Nolimit Personal = 900 บาท\nรวม: 900 บาท\nถูกต้องไหมครับ? พิมพ์ “ยืนยัน” ได้เลย"],
            ['sender' => 'bot', 'content' => "สรุปรายการ\n1. Nolimit BM = 1,500 บาท\nรวมยอดโอน: 1,500 บาท\nโอนเข้าบัญชี 223-3-24880-3"],
        ];
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertTrue($result->passed);
        $this->assertSame('Nolimit BM', $result->orderSummary);
    }

    public function test_slip_passes_against_confirm_message_using_word_ratcha(): void
    {
        // เคสจริง slip_verifications id=124 (แชท #92, 31 ก.ค. 16:02): บอทเขียน "ราคา 1,100 บาท"
        // แทน "รวม 1,100 บาท" → isConfirmMessage เดิมมองไม่เห็น กลายเป็น no_pending_order
        $this->paymentHistory = [
            ['sender' => 'user', 'content' => 'Nolimit Level Up+ Personal'],
            ['sender' => 'user', 'content' => 'ผูกบัตร'],
            ['sender' => 'bot', 'content' => 'เรียบร้อยครับพี่ เพิ่ม Nolimit Level Up+ Personal (ผูกบัตร) 1 ตัว ราคา 1,500 บาทครับ|||ถูกต้องไหมครับ? พิมพ์ “ยืนยัน” ได้เลยครับ'],
        ];

        // prod จริง (bot 26) ตั้ง utility_model + openrouter key ไว้ เลยเข้า LLM fallback ได้
        $this->bot->update(['utility_model' => 'openai/gpt-4o-mini']);
        $this->bot->user->getOrCreateSettings()->update(['openrouter_api_key' => 'sk-test']);

        // ข้อความยืนยันเป็น prose ล้วน (ไม่มี bullet/ตัวคั่นให้ regex ดึงรายการ) จึงต้องพึ่อ LLM
        // fallback ซึ่งบน prod มี utility_model ตั้งไว้จริง → จำลอง chat() ให้คืนรายการตรงยอด 1,500
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(['content' => '{"items":[{"name":"Nolimit Level Up+ Personal","qty":1,"total":"1500"}]}']);
        });

        // LLMOrderItemExtractor เป็น optional param ของ SlipVerificationService → Laravel ไม่ auto-inject
        // ตอน resolve ผ่าน app() (verify() ใช้ app()) ต้อง bind service เองถึงจะเข้า LLM fallback ได้
        $this->app->bind(SlipVerificationService::class, function ($app) {
            return new SlipVerificationService(
                $app->make(PaymentMessageDetector::class),
                $app->make(TelegramAlertBotService::class),
                new LLMOrderItemExtractor($app->make(OpenRouterService::class)),
            );
        });

        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertTrue($result->passed);
        $this->assertSame(1500.0, $result->amount);
        $this->assertStringContainsString('Nolimit Level Up+ Personal', (string) $result->orderSummary);
    }

    public function test_confirm_fallback_keeps_looking_when_items_cannot_be_parsed(): void
    {
        // ข้อความยืนยันล่าสุดยอดตรงแต่เป็น prose ล้วน ดึงรายการไม่ได้ → ต้องไล่ดูข้อความก่อนหน้าต่อ
        // ไม่ใช่ยอมแพ้ทั้งลูป (เคสจริงแชท #1072)
        config(['delivery.llm_item_fallback_enabled' => false]);
        $this->paymentHistory = [
            ['sender' => 'bot', 'content' => "เพิ่มลงตะกร้าแล้วครับ\n1. Nolimit Personal = 1,500 บาท\nรวม: 1,500 บาท\nถูกต้องไหมครับ? พิมพ์ “ยืนยัน” ได้เลย"],
            ['sender' => 'bot', 'content' => 'สรุปอีกครั้งนะครับ รวม 1,500 บาท พิมพ์ “ยืนยัน” ได้เลยครับ'],
        ];
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertTrue($result->passed);
        $this->assertSame('Nolimit Personal', $result->orderSummary);
    }

    public function test_price_line_of_a_single_upsell_item_does_not_hijack_the_total(): void
    {
        // ข้อความมีทั้ง "Page ราคา 199 บาท" และยอดรวมจริง 1,500 → ต้องยึดยอดรวม ไม่ใช่ 199
        $this->paymentHistory = [
            ['sender' => 'bot', 'content' => "รับ Page เพิ่มไหมครับ ราคา 199 บาท\n1. Nolimit Personal = 1,500 บาท\nรวม 1,500 บาท ถูกต้องไหมครับ? พิมพ์ “ยืนยัน”"],
        ];
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertTrue($result->passed);
        $this->assertSame(1500.0, $result->expectedAmount);
    }

    public function test_confirm_message_with_price_wording_is_detected_even_without_item_extraction(): void
    {
        // pin เฉพาะด่านตรวจข้อความ (PaymentMessageDetector) โดยไม่พึ่ง LLM/EasySlip:
        // ข้อความยืนยันรูป "ราคา N บาท" (ไม่ใช่ "รวม") ต้องถูกจดจำเป็นข้อความยืนยันขั้น 2 + ดึงยอดถูก
        $detector = app(PaymentMessageDetector::class);
        $prose = 'เรียบร้อยครับพี่ เพิ่ม Nolimit Level Up+ Personal (ผูกบัตร) 1 ตัว ราคา 1,500 บาทครับ|||ถูกต้องไหมครับ? พิมพ์ “ยืนยัน” ได้เลยครับ';

        $this->assertTrue($detector->isConfirmMessage($prose));
        $this->assertSame('1,500', $detector->parseConfirmData($prose)['total']);
    }
}
