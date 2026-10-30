<?php

namespace Tests\Feature\Payment;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Payment\SlipVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A bank transfer reference (trans_ref) must be usable once, globally: the same
 * transfer must not be accepted as payment twice — not even by a different bot
 * belonging to a different shop that verifies the same slip image.
 */
class TransferRefUsedOnceTest extends TestCase
{
    use RefreshDatabase;

    private function history(): array
    {
        return [['sender' => 'bot', 'content' => "สรุปรายการ\n1. คอร์สออนไลน์ = 1,500 บาท\nรวมยอดโอน: 1,500 บาท\nโอนเข้าบัญชี 223-3-24880-3"]];
    }

    private function makeBot(): Bot
    {
        $user = User::factory()->owner()->create();
        $user->getOrCreateSettings()->update(['easyslip_api_token' => 'dummy-tok']);
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        BotSetting::create(['bot_id' => $bot->id, 'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3', 'slip_amount_tolerance' => 0]);

        return $bot->fresh();
    }

    public function test_transfer_reference_is_usable_once(): void
    {
        Http::fake(['api.easyslip.com/*' => Http::response(['success' => true, 'data' => [
            'isDuplicate' => false, 'amountInSlip' => 1500,
            'rawSlip' => ['transRef' => 'REF-DUP-001', 'amount' => ['amount' => 1500],
                'receiver' => ['bank' => ['id' => '004'], 'account' => ['name' => ['th' => 'shop'], 'bank' => ['account' => 'xxx-x-x4880-x']]]],
        ], 'message' => 'success'])]);

        $botA = $this->makeBot();
        $botB = $this->makeBot();
        $svc = app(SlipVerificationService::class);

        $first = $svc->verify($botA, null, null, 'https://example.invalid/s.jpg', $this->history());
        $replay = $svc->verify($botA, null, null, 'https://example.invalid/s.jpg', $this->history());
        $otherBot = $svc->verify($botB, null, null, 'https://example.invalid/s.jpg', $this->history());

        $passedRows = SlipVerification::where('trans_ref', 'REF-DUP-001')->where('status', 'passed')->count();

        $this->assertTrue($first->passed, 'control: the first use of the transfer passes');
        $this->assertFalse($replay->passed, 'control: replaying the same transfer on the same bot is rejected');
        $this->assertFalse($otherBot->passed, 'The same bank transfer was accepted as payment by a second bot');
        $this->assertSame('duplicate', $otherBot->failReason, 'The second bot must see the transfer as a duplicate');
        $this->assertSame(1, $passedRows, 'More than one passed slip row exists for the same transfer reference');
    }
}
