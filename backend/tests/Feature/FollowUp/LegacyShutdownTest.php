<?php

namespace Tests\Feature\FollowUp;

use App\Jobs\ProcessLeadRecovery;
use App\Models\Bot;
use App\Models\BotHITLSettings;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\LeadRecoveryLog;
use App\Models\Message;
use App\Services\LeadRecoveryService;
use App\Services\ResponseHoursService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * B1 retirement of the old autonomous follow-up sender.
 *
 * The old sender must never send anything again — not through the hourly job,
 * not through processRecovery, even when the legacy lead_recovery_enabled
 * toggle is still true on the bot. What must keep working:
 *   - sendStaticFollowUp as the generic outage-notice transport (exact text).
 *   - markCustomerResponded as reply tracking, but only for an actually sent
 *     log row, at most 72 hours old, answered by a real newer customer message.
 */
class LegacyShutdownTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Rollback the DB transaction BEFORE Mockery verifies: a violated
        // shouldNotReceive must not abort the RefreshDatabase rollback.
        parent::tearDown();
        Carbon::setTestNow();
        Mockery::close();
    }

    /**
     * Fake the three channel hosts and forbid every other outbound request.
     */
    private function fakeChannels(): void
    {
        Http::fake([
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            'graph.facebook.com/*' => Http::response(['recipient_id' => '1', 'message_id' => 'm_1']),
        ]);
        Http::preventStrayRequests();
    }

    private function botWithLegacyToggleOn(string $channel): Bot
    {
        $bot = Bot::factory()->create([
            'channel_type' => $channel,
            'channel_access_token' => 'test-channel-token',
        ]);

        $botSetting = BotSetting::create(['bot_id' => $bot->id]);
        BotHITLSettings::create([
            'bot_setting_id' => $botSetting->id,
            'lead_recovery_enabled' => true,
        ]);

        return $bot;
    }

    private function silentRoom(Bot $bot): Conversation
    {
        return Conversation::factory()->create([
            'bot_id' => $bot->id,
            'channel_type' => $bot->channel_type,
            'external_customer_id' => 'legacy-customer-1',
            'status' => 'active',
            'is_handover' => false,
            'recovery_attempts' => 0,
            'last_message_at' => now()->subHours(30),
            'last_recovery_at' => null,
        ]);
    }

    private function forceResponseHoursAllowed(): void
    {
        $this->mock(ResponseHoursService::class, function ($mock) {
            $mock->shouldReceive('checkResponseHours')
                ->andReturn(['allowed' => true, 'status' => 'within_test_hours']);
        });
    }

    // =========================================================================
    // Old autonomous follow-up can never send again
    // =========================================================================

    public function test_legacy_job_and_process_never_send_even_with_old_toggle_true(): void
    {
        $this->freezeTime();
        $this->fakeChannels();
        $this->forceResponseHoursAllowed();

        $bot = $this->botWithLegacyToggleOn('telegram');
        $conversation = $this->silentRoom($bot);

        $logsBefore = LeadRecoveryLog::count();
        $messagesBefore = Message::count();

        // Path 1: the hourly schedule and any already-queued instance resolve
        // to the job class. Extra arg tolerated so the same call works before
        // and after the handle() signature loses its service parameter.
        (new ProcessLeadRecovery)->handle(app(LeadRecoveryService::class));

        // Path 2: a direct call to the old entry point.
        $processed = app(LeadRecoveryService::class)->processRecovery($conversation->fresh());

        $this->assertFalse($processed, 'processRecovery must be a hard no-op after retirement');

        Http::assertNothingSent('No HTTP request may leave while the legacy sender is retired');

        $this->assertSame($logsBefore, LeadRecoveryLog::count(), 'no lead_recovery_logs row may be created');
        $this->assertSame($messagesBefore, Message::count(), 'no messages row may be created');

        $conversation->refresh();
        $this->assertSame(0, $conversation->recovery_attempts, 'recovery_attempts counter must stay unchanged');
        $this->assertNull($conversation->last_recovery_at, 'last_recovery_at must stay unchanged');
    }

    // =========================================================================
    // Outage notices keep using the generic transport
    // =========================================================================

    public function test_outage_generic_transport_still_sends_plain_text(): void
    {
        $this->freezeTime();
        $this->fakeChannels();

        $service = app(LeadRecoveryService::class);

        // --- LINE room ---
        $lineBot = $this->botWithLegacyToggleOn('line');
        $lineRoom = $this->silentRoom($lineBot);
        $lineText = 'ขออภัยค่ะ ระบบกลับมาใช้ได้แล้ว รบกวนพิมพ์ใหม่อีกครั้งนะคะ';

        $this->assertTrue($service->sendStaticFollowUp($lineRoom, $lineText, true));

        Http::assertSent(function ($request) use ($lineText, $lineRoom) {
            if (! str_contains($request->url(), 'api.line.me') || ! str_contains($request->url(), 'message/push')) {
                return false;
            }

            $this->assertSame($lineRoom->external_customer_id, $request['to']);
            $this->assertSame($lineText, $request['messages'][0]['text'], 'LINE body must carry the exact notice text');

            return true;
        });

        // --- Telegram room (plain text: no parse_mode key at all) ---
        $telegramBot = $this->botWithLegacyToggleOn('telegram');
        $telegramRoom = $this->silentRoom($telegramBot);
        $telegramText = 'ขออภัยครับพี่ ระบบกลับมาใช้ได้แล้ว รบกวนพิมพ์ใหม่อีกครั้งนะครับ';

        $this->assertTrue($service->sendStaticFollowUp($telegramRoom, $telegramText, true));

        Http::assertSent(function ($request) use ($telegramText, $telegramRoom) {
            if (! str_contains($request->url(), 'api.telegram.org') || ! str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $this->assertSame($telegramRoom->external_customer_id, $request['chat_id']);
            $this->assertSame($telegramText, $request['text'], 'Telegram body must carry the exact notice text');
            $this->assertArrayNotHasKey('parse_mode', $request->data(), 'plain-text notice must omit parse_mode');

            return true;
        });

        // --- Facebook room ---
        $facebookBot = $this->botWithLegacyToggleOn('facebook');
        $facebookRoom = $this->silentRoom($facebookBot);
        $facebookText = 'ขออภัยค่ะ ระบบกลับมาใช้ได้แล้วค่ะ';

        $this->assertTrue($service->sendStaticFollowUp($facebookRoom, $facebookText, true));

        Http::assertSent(function ($request) use ($facebookText, $facebookRoom) {
            if (! str_contains($request->url(), 'graph.facebook.com') || ! str_contains($request->url(), '/me/messages')) {
                return false;
            }

            $this->assertSame($facebookRoom->external_customer_id, $request['recipient']['id']);
            $this->assertSame($facebookText, $request['message']['text'], 'Facebook body must carry the exact notice text');

            return true;
        });
    }

    // =========================================================================
    // Reply tracking: only a real customer reply to a sent, recent log row
    // =========================================================================

    private function makeLog(Conversation $conversation, Carbon $sentAt, string $status): LeadRecoveryLog
    {
        return LeadRecoveryLog::create([
            'conversation_id' => $conversation->id,
            'bot_id' => $conversation->bot_id,
            'attempt_number' => 1,
            'message_mode' => 'static',
            'message_sent' => 'synthetic follow-up text',
            'sent_at' => $sentAt,
            'delivery_status' => $status,
            'customer_responded' => false,
        ]);
    }

    private function makeUserMessage(Conversation $conversation, Carbon $createdAt): Message
    {
        $message = Message::factory()->for($conversation)->fromUser()->create([
            'content' => 'synthetic customer reply',
        ]);

        DB::table('messages')->where('id', $message->id)->update(['created_at' => $createdAt->format('Y-m-d H:i:s')]);

        return $message->refresh();
    }

    /**
     * @param  \Closure(self, Conversation): LeadRecoveryLog  $arrange  builds the log row (+ reply evidence)
     * @param  bool  $expectMarked  whether the row must end up marked
     */
    #[DataProvider('replyTrackingCasesProvider')]
    public function test_only_actual_user_message_within_72h_marks_sent_log(\Closure $arrange, bool $expectMarked, string $case): void
    {
        $this->freezeTime(); // now = frozen; boundaries are exact

        $bot = $this->botWithLegacyToggleOn('telegram');
        $room = $this->silentRoom($bot);
        $service = app(LeadRecoveryService::class);

        $log = $arrange($this, $room);

        $service->markCustomerResponded($room->fresh());

        $log->refresh();
        $expectMarked
            ? $this->assertTrue((bool) $log->customer_responded, "[$case] must be marked")
            : $this->assertFalse((bool) $log->customer_responded, "[$case] must not be marked");
    }

    /** @return array<string, array{\Closure, bool, string}> */
    public static function replyTrackingCasesProvider(): array
    {
        return [
            'sent row inside 72h with a real newer user message' => [
                function (self $t, Conversation $room): LeadRecoveryLog {
                    $log = $t->makeLog($room, now()->subHours(24), 'sent');
                    $t->makeUserMessage($room, now()->subHours(23));

                    return $log;
                },
                true,
                'sent inside 72h',
            ],
            'sent row exactly 72h old (inclusive boundary)' => [
                function (self $t, Conversation $room): LeadRecoveryLog {
                    $log = $t->makeLog($room, now()->subHours(72), 'sent');
                    $t->makeUserMessage($room, now()->subHours(71));

                    return $log;
                },
                true,
                'exactly 72h',
            ],
            'sent row older than 72h' => [
                function (self $t, Conversation $room): LeadRecoveryLog {
                    $log = $t->makeLog($room, now()->subHours(72)->subMinute(), 'sent');
                    $t->makeUserMessage($room, now()->subHours(72)->addMinute());

                    return $log;
                },
                false,
                'past 72h',
            ],
            'failed row inside the window' => [
                function (self $t, Conversation $room): LeadRecoveryLog {
                    $log = $t->makeLog($room, now()->subHours(24), 'failed');
                    $t->makeUserMessage($room, now()->subHours(23));

                    return $log;
                },
                false,
                'failed row',
            ],
            'unsent row inside the window' => [
                function (self $t, Conversation $room): LeadRecoveryLog {
                    // A pending row is claimed with a timestamp but never pushed:
                    // only the delivery_status says it was not sent.
                    $log = $t->makeLog($room, now(), 'pending');
                    $t->makeUserMessage($room, now());

                    return $log;
                },
                false,
                'unsent row',
            ],
            'only bot output after the follow-up' => [
                function (self $t, Conversation $room): LeadRecoveryLog {
                    $log = $t->makeLog($room, now()->subHours(24), 'sent');
                    Message::factory()->for($room)->create([
                        'sender' => 'bot',
                        'content' => 'synthetic bot output',
                    ]);

                    return $log;
                },
                false,
                'bot output only',
            ],
        ];
    }

    public function test_mark_customer_responded_is_idempotent_and_stamps_the_reply_time(): void
    {
        $this->freezeTime();

        $bot = $this->botWithLegacyToggleOn('telegram');
        $room = $this->silentRoom($bot);
        $service = app(LeadRecoveryService::class);

        $log = $this->makeLog($room, now()->subHours(24), 'sent');
        $reply = $this->makeUserMessage($room, now()->subHours(23));

        $service->markCustomerResponded($room->fresh());

        $log->refresh();
        $this->assertTrue((bool) $log->customer_responded, 'a sent row replied to within 72h must be marked');
        $this->assertEquals(
            $reply->created_at,
            $log->responded_at,
            'responded_at must carry the actual reply message timestamp'
        );
        $respondedAtAfterFirstCall = $log->responded_at;

        $service->markCustomerResponded($room->fresh());

        $log->refresh();
        $this->assertEquals(
            $respondedAtAfterFirstCall,
            $log->responded_at,
            'calling markCustomerResponded twice must not change the row a second time'
        );
    }
}
