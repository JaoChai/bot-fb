<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\User;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetTelegramAlertWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function seedTelegramPlugin(string $token): FlowPlugin
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);

        return FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งออเดอร์',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => $token, 'chat_id' => '999'],
        ]);
    }

    public function test_sets_webhook_for_each_enabled_telegram_plugin(): void
    {
        config(['app.url' => 'https://ex.com', 'services.telegram_alert.secret' => 'SEC']);
        $this->seedTelegramPlugin('TOK');

        $this->mock(TelegramAlertBotService::class, function ($m) {
            $m->shouldReceive('setWebhook')->once()
                ->with('TOK', 'https://ex.com/api/webhook/telegram-alert/TOK', 'SEC')
                ->andReturn(true);
        });

        $this->artisan('telegram:alert-webhook')->assertExitCode(0);
    }
}
