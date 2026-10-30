<?php

namespace Tests\Feature\Payment;

use App\Jobs\ReserveAccountStock;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\SlipVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A choice-card approval ("po" callback) must be single use: replaying the same
 * callback — even after the 120 s idempotency window has expired — must never
 * confirm a second payment or queue a second stock reservation.
 */
class ChoiceApprovalSingleUseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Bot, 1: Conversation, 2: SlipVerification}
     */
    private function seedChoiceCard(): array
    {
        $owner = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $owner->id, 'channel_type' => 'line']);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create(['flow_id' => $flow->id, 'type' => 'telegram', 'name' => 'alert', 'enabled' => true,
            'trigger_condition' => 'always', 'config' => ['access_token' => 'TOK', 'chat_id' => '999']]);
        $conv = Conversation::factory()->create(['bot_id' => $bot->id, 'channel_type' => 'line', 'external_customer_id' => 'Udummy']);
        $slip = SlipVerification::create(['bot_id' => $bot->id, 'conversation_id' => $conv->id, 'amount' => 1100,
            'status' => 'needs_choice', 'order_source' => 'llm', 'reconstructed' => [
                'items' => [['name' => 'Item P', 'total' => '1100', 'qty' => 1]],
                'alternatives' => [
                    [['name' => 'Item P', 'total' => '1100', 'qty' => 1]],
                    [['name' => 'Item B', 'total' => '1100', 'qty' => 1]],
                ],
            ]]);

        return [$bot->fresh(), $conv, $slip];
    }

    private function pressPick(int $slipId, int $index = 1)
    {
        config(['services.telegram_alert.secret' => 'SEC']);

        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SEC'])
            ->postJson('/api/webhook/telegram-alert/TOK', [
                'callback_query' => [
                    'id' => 'cb1',
                    'from' => ['id' => 111, 'first_name' => 'owner'],
                    'message' => ['message_id' => 5, 'chat' => ['id' => 999]],
                    'data' => "po|{$slipId}|{$index}",
                ],
            ]);
    }

    public function test_choice_approval_is_single_use(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        Queue::fake();
        [$bot, $conv, $slip] = $this->seedChoiceCard();

        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00:00'));
        $this->pressPick($slip->id)->assertOk();
        $afterFirst = SlipVerification::where('conversation_id', $conv->id)->where('status', 'manual_confirmed')->count();

        // Control: an immediate replay is stopped by the 120 s idempotency window.
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00:30'));
        $this->pressPick($slip->id)->assertOk();
        $afterImmediate = SlipVerification::where('conversation_id', $conv->id)->where('status', 'manual_confirmed')->count();

        // The same authentic callback, replayed after the window has expired.
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:03:00'));
        $this->pressPick($slip->id)->assertOk();
        Carbon::setTestNow();

        $manualConfirmed = SlipVerification::where('conversation_id', $conv->id)->where('status', 'manual_confirmed')->count();

        $this->assertSame(1, $afterFirst, 'control: the first approval confirms the payment');
        $this->assertSame(1, $afterImmediate, 'control: an immediate replay is rejected by the idempotency window');
        $this->assertSame(1, $manualConfirmed, 'Replaying the same choice-card approval after 120 s confirmed a second payment');
        $this->assertSame(1, Queue::pushed(ReserveAccountStock::class)->count(), 'A replayed approval queued a second stock reservation');
    }
}
