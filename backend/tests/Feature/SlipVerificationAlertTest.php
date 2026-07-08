<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\User;
use App\Services\Payment\SlipVerificationResult;
use App\Services\Payment\SlipVerificationService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlipVerificationAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_telegram_alert_with_fail_reason(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งออเดอร์',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'tg-token', 'chat_id' => '-100123'],
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $result = new SlipVerificationResult(
            isSlip: true, passed: false, failReason: 'amount_mismatch',
            amount: 1000.0, transRef: 'TR1', expectedAmount: 1500.0,
        );

        app(SlipVerificationService::class)->notifyAdmin($bot->fresh(), null, $result);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org/bottg-token/sendMessage')
                && str_contains($request['text'], '⚠️ ระบบตรวจสลิปไม่ได้ — รบกวนตรวจมือ')
                && str_contains($request['text'], 'ยอดไม่ตรง')
                && str_contains($request['text'], '1,000')
                && str_contains($request['text'], '1,500');
        });
    }

    public function test_alert_uses_conversation_current_flow_over_bot_default_flow(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        // Bot's default flow has NO telegram plugin.
        $defaultFlow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $defaultFlow->id]);

        // Conversation's current flow (a different flow) has the enabled telegram plugin.
        $currentFlow = Flow::factory()->create(['bot_id' => $bot->id]);
        FlowPlugin::create([
            'flow_id' => $currentFlow->id,
            'type' => 'telegram',
            'name' => 'แจ้งออเดอร์',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'tg-token-2', 'chat_id' => '-100456'],
        ]);

        $profile = CustomerProfile::factory()->create(['display_name' => 'สมชาย']);
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $profile->id,
            'current_flow_id' => $currentFlow->id,
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $result = new SlipVerificationResult(
            isSlip: true, passed: false, failReason: 'no_pending_order',
            transRef: 'TR2',
        );

        app(SlipVerificationService::class)->notifyAdmin($bot->fresh(), $conversation, $result);

        Http::assertSent(function ($request) use ($conversation) {
            return str_contains($request->url(), 'api.telegram.org/bottg-token-2/sendMessage')
                && str_contains($request['text'], '⚠️ ระบบตรวจสลิปไม่ได้ — รบกวนตรวจมือ')
                && str_contains($request['text'], "ลูกค้า: สมชาย (แชท #{$conversation->id})")
                && str_contains($request['text'], 'ไม่พบออเดอร์ค้างชำระ');
        });
    }

    public function test_no_telegram_plugin_does_not_throw(): void
    {
        $bot = Bot::factory()->create();
        Http::fake();

        app(SlipVerificationService::class)->notifyAdmin($bot, null, new SlipVerificationResult(
            isSlip: true, passed: false, failReason: 'fake',
        ));

        Http::assertNothingSent();
        $this->addToAssertionCount(1); // ไม่ throw = ผ่าน
    }

    public function test_alert_attaches_confirm_button_for_nonfraud(): void
    {
        [$bot, $conversation, $plugin] = $this->makeBotWithTelegramPlugin();

        $captured = null;
        $this->mock(TelegramAlertBotService::class, function ($m) use (&$captured) {
            $m->shouldReceive('sendMessage')->once()
                ->andReturnUsing(function ($token, $chatId, $text, $keyboard) use (&$captured) {
                    $captured = $keyboard;
                });
        });

        $result = new SlipVerificationResult(
            isSlip: true, passed: false, failReason: 'unreadable',
            amount: null, expectedAmount: 590.0,
        );

        app(SlipVerificationService::class)->notifyAdmin($bot, $conversation, $result);

        $this->assertNotNull($captured);
        $this->assertSame('pc|'.$conversation->id.'|590', $captured[0][0]['callback_data']);
        $this->assertStringContainsString('ยืนยันรับเงิน', $captured[0][0]['text']);
    }

    public function test_alert_shows_two_buttons_when_amounts_differ(): void
    {
        [$bot, $conversation, $plugin] = $this->makeBotWithTelegramPlugin();

        $captured = null;
        $this->mock(TelegramAlertBotService::class, function ($m) use (&$captured) {
            $m->shouldReceive('sendMessage')->once()
                ->andReturnUsing(function ($t, $c, $tx, $kb) use (&$captured) {
                    $captured = $kb;
                });
        });

        $result = new SlipVerificationResult(
            isSlip: true, passed: false, failReason: 'amount_mismatch',
            amount: 600.0, expectedAmount: 590.0,
        );

        app(SlipVerificationService::class)->notifyAdmin($bot, $conversation, $result);

        $this->assertCount(2, $captured);
        $this->assertSame('pc|'.$conversation->id.'|590', $captured[0][0]['callback_data']);
        $this->assertSame('pc|'.$conversation->id.'|600', $captured[1][0]['callback_data']);
    }

    /**
     * เตรียม bot + flow + telegram plugin (enabled) + conversation ผูก bot ตาม pattern
     * ที่ใช้ใน test อื่นในไฟล์นี้ (token TOK, chat_id 999).
     *
     * @return array{0: Bot, 1: Conversation, 2: FlowPlugin}
     */
    private function makeBotWithTelegramPlugin(): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        $plugin = FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งออเดอร์',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'TOK', 'chat_id' => '999'],
        ]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        return [$bot->fresh(), $conversation, $plugin];
    }
}
