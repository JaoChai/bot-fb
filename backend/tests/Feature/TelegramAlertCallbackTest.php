<?php

namespace Tests\Feature;

use App\Exceptions\RecentManualConfirmException;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\User;
use App\Services\Payment\ManualPaymentConfirmService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TelegramAlertCallbackTest extends TestCase
{
    use RefreshDatabase;

    private function seedPlugin(): array
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งออเดอร์',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'TOK', 'chat_id' => '999'],
        ]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        return [$bot->fresh(), $conversation];
    }

    private function postCallback(string $token, array $callback): TestResponse
    {
        config(['services.telegram_alert.secret' => 'SEC']);

        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SEC'])
            ->postJson("/api/webhook/telegram-alert/{$token}", ['callback_query' => $callback]);
    }

    public function test_rejects_wrong_secret(): void
    {
        [$bot, $conv] = $this->seedPlugin();
        config(['services.telegram_alert.secret' => 'SEC']);

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'WRONG'])
            ->postJson('/api/webhook/telegram-alert/TOK', ['callback_query' => []])
            ->assertStatus(401);
    }

    public function test_wrong_chat_id_does_not_confirm(): void
    {
        [$bot, $conv] = $this->seedPlugin();
        $this->mock(ManualPaymentConfirmService::class, fn ($m) => $m->shouldNotReceive('confirm'));
        $this->mock(TelegramAlertBotService::class);

        $this->postCallback('TOK', [
            'id' => 'cb1', 'from' => ['first_name' => 'X'],
            'message' => ['message_id' => 5, 'chat' => ['id' => 111]], // ผิด (คาด 999)
            'data' => 'pc|'.$conv->id.'|590',
        ])->assertOk();
    }

    public function test_confirm_press_calls_service_and_edits_message(): void
    {
        [$bot, $conv] = $this->seedPlugin();

        $this->mock(ManualPaymentConfirmService::class, function ($m) use ($bot) {
            $m->shouldReceive('confirm')->once()
                ->with(\Mockery::any(), \Mockery::any(), 590.0, $bot->user_id)
                ->andReturn(['message' => new Message, 'order_created' => true]);
        });
        $this->mock(TelegramAlertBotService::class, function ($m) {
            $m->shouldReceive('editMessageText')->once();
            $m->shouldReceive('answerCallbackQuery')->once();
        });

        $this->postCallback('TOK', [
            'id' => 'cb1', 'from' => ['first_name' => 'Admin'],
            'message' => ['message_id' => 5, 'chat' => ['id' => 999]],
            'data' => 'pc|'.$conv->id.'|590',
        ])->assertOk();
    }

    public function test_fraud_arm_press_only_edits_keyboard(): void
    {
        [$bot, $conv] = $this->seedPlugin();
        $this->mock(ManualPaymentConfirmService::class, fn ($m) => $m->shouldNotReceive('confirm'));
        $this->mock(TelegramAlertBotService::class, function ($m) {
            $m->shouldReceive('editMessageText')->once(); // แก้ปุ่มเป็น "กดอีกครั้ง"
            $m->shouldReceive('answerCallbackQuery')->once();
        });

        $this->postCallback('TOK', [
            'id' => 'cb1', 'from' => ['first_name' => 'Admin'],
            'message' => ['message_id' => 5, 'chat' => ['id' => 999]],
            'data' => 'pa|'.$conv->id.'|590',
        ])->assertOk();
    }

    public function test_recent_confirm_shows_already_confirmed(): void
    {
        [$bot, $conv] = $this->seedPlugin();
        $this->mock(ManualPaymentConfirmService::class, fn ($m) => $m->shouldReceive('confirm')->andThrow(new RecentManualConfirmException));
        $this->mock(TelegramAlertBotService::class, function ($m) {
            $m->shouldReceive('editMessageText')->once();
            $m->shouldReceive('answerCallbackQuery')->once();
        });

        $this->postCallback('TOK', [
            'id' => 'cb1', 'from' => ['first_name' => 'Admin'],
            'message' => ['message_id' => 5, 'chat' => ['id' => 999]],
            'data' => 'pc|'.$conv->id.'|590',
        ])->assertOk();
    }
}
