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

    public function test_rejects_when_secret_not_configured(): void
    {
        [$bot, $conv] = $this->seedPlugin();
        $this->mock(ManualPaymentConfirmService::class, fn ($m) => $m->shouldNotReceive('confirm'));
        config(['services.telegram_alert.secret' => '']);

        $this->postJson('/api/webhook/telegram-alert/TOK', [
            'callback_query' => [
                'id' => 'cb1', 'from' => ['first_name' => 'X'],
                'message' => ['message_id' => 5, 'chat' => ['id' => 999]],
                'data' => 'pc|'.$conv->id.'|590',
            ],
        ])->assertStatus(401);
    }

    public function test_rejects_conversation_belonging_to_different_bot(): void
    {
        [$bot, $conv] = $this->seedPlugin();

        $otherUser = User::factory()->owner()->create();
        $otherBot = Bot::factory()->create(['user_id' => $otherUser->id, 'channel_type' => 'line']);
        $otherConversation = Conversation::factory()->create(['bot_id' => $otherBot->id]);

        $this->mock(ManualPaymentConfirmService::class, fn ($m) => $m->shouldNotReceive('confirm'));
        $this->mock(TelegramAlertBotService::class);

        $this->postCallback('TOK', [
            'id' => 'cb1', 'from' => ['first_name' => 'X'],
            'message' => ['message_id' => 5, 'chat' => ['id' => 999]],
            'data' => 'pc|'.$otherConversation->id.'|590',
        ])->assertOk();
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

    public function test_non_numeric_conversation_id_does_not_confirm(): void
    {
        [$bot, $conv] = $this->seedPlugin();
        $this->mock(ManualPaymentConfirmService::class, fn ($m) => $m->shouldNotReceive('confirm'));
        $this->mock(TelegramAlertBotService::class);

        $this->postCallback('TOK', [
            'id' => 'cb1', 'from' => ['first_name' => 'X'],
            'message' => ['message_id' => 5, 'chat' => ['id' => 999]],
            'data' => 'pc|abc|590',
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

    public function test_picking_an_option_confirms_the_payment_with_the_chosen_items(): void
    {
        [$bot, $conv] = $this->seedPlugin();
        \Illuminate\Support\Facades\Http::fake(['api.telegram.org/*' => \Illuminate\Support\Facades\Http::response(['ok' => true])]);

        $slip = \App\Models\SlipVerification::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conv->id,
            'amount' => 1100,
            'status' => 'needs_choice',
            'order_source' => 'llm',
            'reconstructed' => [
                'items' => [['name' => 'Nolimit Level Up+ Personal', 'total' => '1100', 'qty' => 1]],
                'alternatives' => [
                    [['name' => 'Nolimit Level Up+ Personal', 'total' => '1100', 'qty' => 1]],
                    [['name' => 'Nolimit Level Up+ BM', 'total' => '1100', 'qty' => 1]],
                ],
            ],
        ]);

        $this->postCallback('TOK', [
            'id' => 'cb1',
            'from' => ['id' => 111, 'first_name' => 'owner'],
            'message' => ['message_id' => 5, 'chat' => ['id' => 999]],
            'data' => "po|{$slip->id}|1",
        ])->assertOk();

        $this->assertDatabaseHas('slip_verifications', [
            'conversation_id' => $conv->id,
            'status' => 'manual_confirmed',
        ]);
    }

    public function test_picking_an_option_that_does_not_exist_is_ignored(): void
    {
        [$bot, $conv] = $this->seedPlugin();
        $this->mock(ManualPaymentConfirmService::class, fn ($m) => $m->shouldNotReceive('confirm'));
        \Illuminate\Support\Facades\Http::fake(['api.telegram.org/*' => \Illuminate\Support\Facades\Http::response(['ok' => true])]);

        $slip = \App\Models\SlipVerification::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conv->id,
            'amount' => 1100,
            'status' => 'needs_choice',
            'reconstructed' => ['items' => [], 'alternatives' => []],
        ]);

        $this->postCallback('TOK', [
            'id' => 'cb2',
            'from' => ['id' => 111, 'first_name' => 'owner'],
            'message' => ['message_id' => 5, 'chat' => ['id' => 999]],
            'data' => "po|{$slip->id}|3",
        ])->assertOk();
    }
}
