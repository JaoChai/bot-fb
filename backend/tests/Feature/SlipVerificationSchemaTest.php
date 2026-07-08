<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\SlipVerification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlipVerificationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_bot_settings_has_slip_verification_fields(): void
    {
        $bot = Bot::factory()->create();
        $settings = BotSetting::create([
            'bot_id' => $bot->id,
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
            'slip_amount_tolerance' => 5.00,
            'slip_success_message' => 'เงินเข้าแล้ว {amount} บาท',
            'slip_fail_message' => 'ขอตรวจสอบสักครู่',
        ]);

        $this->assertTrue($settings->fresh()->slip_verification_enabled);
        $this->assertSame('223-3-24880-3', $settings->fresh()->slip_receiver_account);
    }

    public function test_slip_verification_records_persist(): void
    {
        $bot = Bot::factory()->create();
        $row = SlipVerification::create([
            'bot_id' => $bot->id,
            'conversation_id' => null,
            'message_id' => null,
            'trans_ref' => 'TR001',
            'amount' => 1500.00,
            'receiver_account' => 'xxx-x-x4880-x',
            'status' => 'passed',
            'raw_response' => ['data' => ['transRef' => 'TR001']],
        ]);

        $this->assertSame('passed', $row->fresh()->status);
        $this->assertSame(['data' => ['transRef' => 'TR001']], $row->fresh()->raw_response);
    }

    public function test_duplicate_passed_trans_ref_rejected_per_bot(): void
    {
        $bot = Bot::factory()->create();
        SlipVerification::create(['bot_id' => $bot->id, 'trans_ref' => 'TRDUP', 'status' => 'passed']);

        // แถว failed ซ้ำ trans_ref ได้ (partial index)
        SlipVerification::create(['bot_id' => $bot->id, 'trans_ref' => 'TRDUP', 'status' => 'duplicate']);
        $this->assertSame(2, SlipVerification::count());

        // แถว passed ซ้ำ trans_ref ใน bot เดียวกันต้องพัง
        $this->expectException(QueryException::class);
        SlipVerification::create(['bot_id' => $bot->id, 'trans_ref' => 'TRDUP', 'status' => 'passed']);
    }

    public function test_user_settings_easyslip_token_helpers(): void
    {
        $user = User::factory()->create();
        $settings = $user->getOrCreateSettings();

        $this->assertFalse($settings->hasEasySlipToken());

        $settings->easyslip_api_token = 'test-token-1234';
        $settings->save();

        $this->assertTrue($settings->fresh()->hasEasySlipToken());
        $this->assertSame('test-token-1234', $settings->fresh()->getEasySlipApiToken());
        $this->assertStringEndsWith('1234', $settings->fresh()->masked_easyslip_token);
    }
}
