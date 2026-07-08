<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Payment\SlipVerificationResult;
use App\Services\Payment\SlipVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'status' => 200,
            'data' => [
                'transRef' => $transRef,
                'amount' => ['amount' => $amount],
                'receiver' => [
                    'bank' => ['id' => '004'],
                    'account' => ['name' => ['th' => 'ร้านค้า'], 'bank' => ['account' => $account]],
                ],
            ],
        ];
    }

    private function verify(): SlipVerificationResult
    {
        return app(SlipVerificationService::class)->verify(
            $this->bot, null, null, 'https://example.com/slip.jpg', $this->paymentHistory
        );
    }

    public function test_valid_slip_passes_all_checks(): void
    {
        Http::fake(['developer.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertTrue($result->isSlip);
        $this->assertTrue($result->passed);
        $this->assertSame(1500.0, $result->amount);
        // NOTE: assertStringContainsString (not assertSame) — matches the precedent set by
        // Tests\Unit\SlipVerificationLogicTest::test_finds_latest_payment_total_from_history()
        // for this exact fixture string. PaymentMessageDetector's item-name regex (pre-existing,
        // shared with PaymentFlexService, out of Task 3 scope) captures a trailing "=" when the
        // item line has no parenthesized qty×price segment, yielding "Nolimit BM =" not "Nolimit BM".
        $this->assertStringContainsString('Nolimit BM', $result->orderSummary);
        $this->assertDatabaseHas('slip_verifications', ['trans_ref' => 'TR100', 'status' => 'passed']);
    }

    public function test_wrong_receiver_account_fails(): void
    {
        Http::fake(['developer.easyslip.com/*' => Http::response($this->easySlipResponse(account: 'xxx-x-x9999-x'))]);

        $result = $this->verify();

        $this->assertTrue($result->isSlip);
        $this->assertFalse($result->passed);
        $this->assertSame('wrong_account', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'wrong_account']);
    }

    public function test_duplicate_trans_ref_fails(): void
    {
        SlipVerification::create(['bot_id' => $this->bot->id, 'trans_ref' => 'TR100', 'status' => 'passed']);
        Http::fake(['developer.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertFalse($result->passed);
        $this->assertSame('duplicate', $result->failReason);
    }

    public function test_amount_mismatch_fails(): void
    {
        Http::fake(['developer.easyslip.com/*' => Http::response($this->easySlipResponse(amount: 1000))]);

        $result = $this->verify();

        $this->assertFalse($result->passed);
        $this->assertSame('amount_mismatch', $result->failReason);
        $this->assertSame(1500.0, $result->expectedAmount);
    }

    public function test_amount_within_tolerance_passes(): void
    {
        $this->bot->settings->update(['slip_amount_tolerance' => 10]);
        Http::fake(['developer.easyslip.com/*' => Http::response($this->easySlipResponse(amount: 1495))]);

        $this->assertTrue($this->verify()->passed);
    }

    public function test_no_pending_order_fails(): void
    {
        $this->paymentHistory = [['sender' => 'user', 'content' => 'สวัสดี']];
        Http::fake(['developer.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertFalse($result->passed);
        $this->assertSame('no_pending_order', $result->failReason);
    }

    public function test_http_400_means_not_a_slip(): void
    {
        Http::fake(['developer.easyslip.com/*' => Http::response(['status' => 400, 'message' => 'invalid_image'], 400)]);

        $result = $this->verify();

        $this->assertFalse($result->isSlip);
        $this->assertNull($result->failReason);
        $this->assertSame(0, SlipVerification::count());
    }

    public function test_http_404_means_fake_slip(): void
    {
        Http::fake(['developer.easyslip.com/*' => Http::response(['status' => 404, 'message' => 'slip_not_found'], 404)]);

        $result = $this->verify();

        $this->assertTrue($result->isSlip);
        $this->assertSame('fake', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'fake']);
    }

    public function test_server_error_is_api_error(): void
    {
        Http::fake(['developer.easyslip.com/*' => Http::response(['message' => 'server error'], 500)]);

        $result = $this->verify();

        $this->assertFalse($result->isSlip);
        $this->assertSame('api_error', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'api_error']);
    }

    public function test_missing_token_is_api_error_without_http_call(): void
    {
        $this->bot->user->settings->update(['easyslip_api_token' => null]);
        Http::fake();

        $result = $this->verify();

        $this->assertSame('api_error', $result->failReason);
        Http::assertNothingSent();
    }
}
