<?php

namespace Tests\Feature;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Delivery\AccountDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountDeliverySendCardTest extends TestCase
{
    use RefreshDatabase;

    private function makeDelivery(bool $withPlugin = true): AccountDelivery
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);

        if ($withPlugin) {
            FlowPlugin::create([
                'flow_id' => $flow->id, 'type' => 'telegram', 'name' => 'แจ้งออเดอร์',
                'enabled' => true, 'trigger_condition' => 'always',
                'config' => ['access_token' => 'TOK', 'chat_id' => '999'],
            ]);
        }

        $conv = Conversation::factory()->create(['bot_id' => $bot->id]);
        $slip = SlipVerification::create([
            'bot_id' => $bot->id, 'conversation_id' => $conv->id, 'amount' => 1100, 'status' => 'passed',
        ]);

        return AccountDelivery::create([
            'bot_id' => $bot->id, 'conversation_id' => $conv->id,
            'slip_verification_id' => $slip->id, 'status' => AccountDelivery::STATUS_RESERVED,
            'amount' => 1100,
        ]);
    }

    public function test_send_card_returns_true_when_telegram_accepts(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $sent = app(AccountDeliveryService::class)->sendCard($this->makeDelivery());

        $this->assertTrue($sent);
    }

    public function test_send_card_returns_false_when_telegram_is_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $sent = app(AccountDeliveryService::class)->sendCard($this->makeDelivery());

        $this->assertFalse($sent);
    }

    public function test_send_card_returns_false_when_flow_has_no_telegram_plugin(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $sent = app(AccountDeliveryService::class)->sendCard($this->makeDelivery(withPlugin: false));

        $this->assertFalse($sent);
        Http::assertNothingSent();
    }

    public function test_send_card_records_the_telegram_message_id(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true, 'result' => ['message_id' => 8899],
        ])]);
        $delivery = $this->makeDelivery();

        $sent = app(AccountDeliveryService::class)->sendCard($delivery);

        $this->assertTrue($sent);
        $this->assertSame(8899, $delivery->fresh()->card_message_id);
    }

    public function test_send_card_leaves_message_id_null_when_telegram_is_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $delivery = $this->makeDelivery();

        $sent = app(AccountDeliveryService::class)->sendCard($delivery);

        $this->assertFalse($sent);
        $this->assertNull($delivery->fresh()->card_message_id);
    }
}
