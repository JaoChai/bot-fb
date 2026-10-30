<?php

namespace Tests\Feature\Payment;

use App\Jobs\ReserveAccountStock;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Payment\ManualPaymentConfirmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public function test_choice_stays_consumed_when_confirmation_fails_after_commit(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        Http::preventStrayRequests();
        Queue::fake();
        [$bot, $conv, $slip] = $this->seedChoiceCard();
        $dispatcher = Conversation::getEventDispatcher();
        Conversation::setEventDispatcher(clone $dispatcher);
        $failed = false;
        Conversation::updating(function (Conversation $conversation) use ($conv, &$failed) {
            if ($conversation->id === $conv->id && $conversation->isDirty('last_message_id') && ! $failed) {
                $failed = true;
                throw new \RuntimeException('Transient conversation update failure');
            }
        });

        try {
            $this->freezeTime();
            $this->pressPick($slip->id)->assertOk();
            $this->assertTrue($failed, 'The real confirmation must reach the post-commit broadcast update');
            $this->assertSame(1, SlipVerification::where('conversation_id', $conv->id)->where('status', 'manual_confirmed')->count());
            Queue::assertPushed(ReserveAccountStock::class, 1);
            $statusAfterFailure = $slip->fresh()->status;

            $this->travel(180)->seconds();
            $this->pressPick($slip->id)->assertOk();

            $this->assertSame(1, SlipVerification::where('conversation_id', $conv->id)->where('status', 'manual_confirmed')->count(),
                'A post-commit failure must not allow the same choice to confirm another payment');
            Queue::assertPushed(ReserveAccountStock::class, 1);
            $this->assertSame('choice_confirmed', $statusAfterFailure);
            $this->assertSame('choice_confirmed', $slip->fresh()->status);
        } finally {
            Conversation::setEventDispatcher($dispatcher);
            $this->travelBack();
        }
    }

    public function test_pre_commit_failure_restores_choice_despite_an_older_confirmation(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        Http::preventStrayRequests();
        Queue::fake();
        [$bot, $conv, $slip] = $this->seedChoiceCard();
        SlipVerification::create(['bot_id' => $bot->id, 'conversation_id' => $conv->id,
            'status' => 'manual_confirmed'])->forceFill(['created_at' => now()->subMinutes(5)])->save();
        $dispatcher = SlipVerification::getEventDispatcher();
        SlipVerification::setEventDispatcher(clone $dispatcher);
        $failed = false;
        SlipVerification::creating(function (SlipVerification $reservation) use (&$failed) {
            if ($reservation->status === 'manual_confirmed' && ! $failed) {
                $failed = true;
                throw new \RuntimeException('Transient reservation insert failure');
            }
        });

        try {
            $this->pressPick($slip->id)->assertOk();
            $this->assertTrue($failed);
            $this->assertSame('needs_choice', $slip->fresh()->status);
            $this->assertSame(1, SlipVerification::where('conversation_id', $conv->id)->where('status', 'manual_confirmed')->count());
            Queue::assertNotPushed(ReserveAccountStock::class);

            $this->pressPick($slip->id)->assertOk();
            $this->assertSame('choice_confirmed', $slip->fresh()->status);
            $this->assertSame(2, SlipVerification::where('conversation_id', $conv->id)->where('status', 'manual_confirmed')->count());
            Queue::assertPushed(ReserveAccountStock::class, 1);
        } finally {
            SlipVerification::setEventDispatcher($dispatcher);
        }
    }

    public function test_choice_restore_failure_keeps_card_consumed_and_acknowledges_callback(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        Http::preventStrayRequests();
        [$bot, $conv, $slip] = $this->seedChoiceCard();
        $this->mock(ManualPaymentConfirmService::class,
            fn ($mock) => $mock->shouldReceive('confirm')->once()->andThrow(new \RuntimeException('Confirmation unavailable')));
        // The local SQLite test DB rejects only the recovery UPDATE, not consumption.
        DB::unprepared("CREATE TRIGGER reject_choice_restore BEFORE UPDATE OF status ON slip_verifications
            WHEN NEW.status = 'needs_choice' BEGIN SELECT RAISE(ABORT, 'Restore unavailable'); END");

        try {
            $this->pressPick($slip->id)->assertOk();
            $this->assertSame('choice_confirmed', $slip->fresh()->status);
            Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery'));
        } finally {
            DB::unprepared('DROP TRIGGER reject_choice_restore');
        }
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
