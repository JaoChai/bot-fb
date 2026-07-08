<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotSlipSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_slip_settings(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
            'slip_amount_tolerance' => 5,
            'slip_success_message' => 'เงินเข้าแล้ว {amount} บาท ✅ [ยืนยันชำระเงิน]',
            'slip_fail_message' => 'ขอตรวจสอบสักครู่ครับ',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('bot_settings', [
            'bot_id' => $bot->id,
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
        ]);
    }

    public function test_tolerance_over_limit_rejected(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [
            'slip_amount_tolerance' => 99999,
        ])->assertStatus(422);
    }
}
