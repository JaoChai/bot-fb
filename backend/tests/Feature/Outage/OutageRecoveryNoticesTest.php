<?php

namespace Tests\Feature\Outage;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\User;
use App\Services\OutageRecoveryService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด t_44675510 — outage:send-recovery-notices
 *
 * ห้องที่ "รอ" = ข้อความล่าสุดของแชท (messages.id สูงสุด) เป็นแถวบอทที่ติด tag
 * model_error = "auth" และยังไม่มี recovery_notice, อายุ ≤ 24 ชม., และไม่อยู่ในโหมด handover
 *
 * ลำดับการทำงานต่อรอบ: มีห้องรอ → อยู่ในช่วง 08:00-22:00 (Asia/Bangkok) → probe /key = 200
 * → ต่อห้อง: จอง "sending" → ส่ง → สำเร็จ: เขียนแถวแจ้งลูกค้า + "sent", ล้ม: "failed" ไม่มีแถว
 * ห้าม retry ห้องไหนเลย และปิดท้ายส่ง "Back" ให้เจ้าของต่อบอท 1 ข้อความ
 */
class OutageRecoveryNoticesTest extends TestCase
{
    use RefreshDatabase;

    private const AUTH_TEXT = 'ระบบขัดข้องชั่วคราวครับ 🙏 เดี๋ยวแอดมินรีบตรวจสอบให้นะครับ';

    private const CUSTOMER_THAI = 'ขออภัยครับพี่ 🙏 ก่อนหน้านี้ระบบขัดข้องชั่วคราว ตอนนี้กลับมาใช้งานได้ปกติแล้วครับ รบกวนพี่พิมพ์ข้อความเข้ามาใหม่อีกครั้งนะครับ';

    private const CUSTOMER_EN = 'Sorry for the inconvenience 🙏 Our system had a temporary issue and is working again now. Please send your message again.';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-14 10:00:00'));

        config([
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.base_url' => 'https://openrouter.test/api/v1',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // ห้องที่รอ (waiting room)
    // ------------------------------------------------------------------

    #[Test]
    public function test_a_waiting_room_gets_exactly_one_notice(): void
    {
        $this->fakeAllWorking();
        [$bot, $conversation] = $this->roomWithOutage('เอา2ชุดครับ');

        $this->runRecovery();

        $this->assertSame([self::CUSTOMER_THAI], $this->pushTexts());
        $tagged = $conversation->messages()->orderByDesc('id')->where('metadata->model_error', 'auth')->first();
        $tagged->refresh();
        $this->assertSame('sent', $tagged->metadata['recovery_notice']);
    }

    #[Test]
    public function test_a_room_in_handover_gets_nothing(): void
    {
        $this->fakeAllWorking();
        $bot = $this->makeBot();
        $conversation = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'is_handover' => true,
        ]);
        $this->seedOutageTurn($conversation, 'สวัสดี');

        $this->runRecovery();

        Http::assertNothingSent();
        $this->assertNoNoticeRow();
    }

    #[Test]
    public function test_an_agent_reply_after_the_tagged_row_blocks_the_notice(): void
    {
        $this->fakeAllWorking();
        [$bot, $conversation, $tagged] = $this->roomWithOutage('สวัสดี');
        Message::factory()->fromAgent()->create(['conversation_id' => $conversation->id, 'content' => 'แอดมินตอบเองครับ']);

        $this->runRecovery();

        Http::assertNothingSent();
        $tagged->refresh();
        $this->assertArrayNotHasKey('recovery_notice', $tagged->metadata ?? []);
    }

    #[Test]
    public function test_a_normal_bot_reply_after_the_tagged_row_blocks_the_notice(): void
    {
        $this->fakeAllWorking();
        [$bot, $conversation, $tagged] = $this->roomWithOutage('สวัสดี');
        Message::factory()->fromBot()->create(['conversation_id' => $conversation->id, 'content' => 'ตอบปกติครับ']);

        $this->runRecovery();

        Http::assertNothingSent();
    }

    #[Test]
    public function test_a_customer_message_after_the_tagged_row_blocks_the_notice(): void
    {
        $this->fakeAllWorking();
        [$bot, $conversation, $tagged] = $this->roomWithOutage('สวัสดี');
        Message::factory()->fromUser()->create(['conversation_id' => $conversation->id, 'content' => 'เขียนใหม่เองแล้ว']);

        $this->runRecovery();

        Http::assertNothingSent();
    }

    #[Test]
    public function test_a_tagged_row_older_than_24h_is_skipped_and_exactly_24h_still_counts(): void
    {
        $this->fakeAllWorking();
        $bot = $this->makeBot();
        $old = Conversation::factory()->line()->create(['bot_id' => $bot->id]);
        $oldTagged = $this->seedOutageTurn($old, 'ลูกค้าเก่า', Carbon::now()->subHours(24)->subMinute());
        $fresh = Conversation::factory()->line()->create(['bot_id' => $bot->id]);
        $freshTagged = $this->seedOutageTurn($fresh, 'ลูกค้าใหม่', Carbon::now()->subHours(24));

        $this->runRecovery();

        // ห้องเดียวที่ถูกส่งคือห้องที่แถวอายุ 24 ชม.พอดี (to = external_customer_id ของห้อง fresh)
        $pushes = $this->linePushes();
        $this->assertCount(1, $pushes);
        $this->assertSame($fresh->external_customer_id, $pushes[0]['to']);
        $freshTagged->refresh();
        $this->assertSame('sent', $freshTagged->metadata['recovery_notice']);
        $oldTagged->refresh();
        $this->assertArrayNotHasKey('recovery_notice', $oldTagged->metadata ?? []);
    }

    #[Test]
    public function test_a_room_already_noticed_is_never_sent_twice(): void
    {
        $this->fakeAllWorking();
        [$bot, $conversation, $tagged] = $this->roomWithOutage('สวัสดี');
        $metadata = $tagged->metadata;
        $metadata['recovery_notice'] = 'sent';
        $tagged->forceFill(['metadata' => $metadata])->save();

        $this->runRecovery();

        Http::assertNothingSent();
        $this->assertNoNoticeRow();
    }

    #[Test]
    public function test_two_tagged_rows_in_one_room_still_mean_one_notice(): void
    {
        $this->fakeAllWorking();
        $bot = $this->makeBot();
        $conversation = Conversation::factory()->line()->create(['bot_id' => $bot->id]);
        $this->seedOutageTurn($conversation, 'สวัสดี', Carbon::now()->subHours(2));
        $this->seedOutageTurn($conversation, 'สวัสดี', Carbon::now()->subMinutes(30));

        $this->runRecovery();

        $this->assertCount(1, $this->linePushes());
        $this->assertCount(1, $this->noticeRows());
    }

    #[Test]
    public function test_two_rooms_on_two_bots_get_one_notice_and_one_back_alert_each(): void
    {
        $this->fakeAllWorking();
        $botA = $this->makeBot();
        $roomA = Conversation::factory()->line()->create(['bot_id' => $botA->id]);
        $this->seedOutageTurn($roomA, 'สวัสดี');
        $botB = $this->makeBot();
        $roomB = Conversation::factory()->line()->create(['bot_id' => $botB->id]);
        $this->seedOutageTurn($roomB, 'สวัสดี');

        $this->runRecovery();

        $this->assertCount(2, $this->linePushes());
        $alerts = $this->telegramAlerts();
        $this->assertCount(2, $alerts);
        $texts = collect($alerts)->pluck('text');
        $this->assertStringContainsString($this->esc($botA->name), $texts[0]);
        $this->assertStringContainsString($this->esc($botB->name), $texts[1]);
    }

    #[Test]
    public function test_overlapping_runs_push_each_room_once_and_count_only_their_own_claims(): void
    {
        $bot = $this->makeBot();
        $first = Conversation::factory()->line()->create(['bot_id' => $bot->id]);
        $this->seedOutageTurn($first, 'สวัสดี');
        $second = Conversation::factory()->line()->create(['bot_id' => $bot->id]);
        $this->seedOutageTurn($second, 'สวัสดี');
        $pushes = [];
        $nested = false;
        Http::fake([
            'openrouter.test/api/v1/key' => Http::response(['data' => []], 200),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            'api.line.me/*' => function ($request) use (&$pushes, &$nested) {
                $pushes[] = $request['to'];
                if (! $nested) {
                    $nested = true;
                    $this->runRecovery();
                }

                return Http::response([], 200);
            },
        ]);

        $this->runRecovery();

        $counts = collect($pushes)->countBy()->all();
        $evidence = 'pushes per room: '.json_encode([
            'room1' => $counts[$first->external_customer_id] ?? 0,
            'room2' => $counts[$second->external_customer_id] ?? 0,
        ]);
        $this->assertSame(1, $counts[$second->external_customer_id] ?? 0, $evidence);
        $this->assertSame(1, $counts[$first->external_customer_id] ?? 0, $evidence);
        foreach ([$first, $second] as $room) {
            $this->assertSame(1, $this->noticeRows()->where('conversation_id', $room->id)->count());
        }
        $alerts = $this->telegramAlerts();
        $this->assertCount(2, $alerts);
        foreach ($alerts as $alert) {
            $this->assertStringContainsString('ส่งข้อความขอให้ลูกค้าพิมพ์ใหม่ 1 ห้อง · ส่งไม่สำเร็จ 0 ห้อง', $alert['text']);
        }
        if (getenv('OUTAGE_CLAIM_EVIDENCE')) {
            fwrite(STDOUT, "\n".$evidence.'; notice rows per room: '.json_encode([
                'room1' => $this->noticeRows()->where('conversation_id', $first->id)->count(),
                'room2' => $this->noticeRows()->where('conversation_id', $second->id)->count(),
            ])."; Back summaries: 1 sent / 0 failed each\n");
        }
    }

    #[Test]
    public function test_a_run_that_loses_every_claim_sends_no_notice_or_back_alert(): void
    {
        $bot = $this->makeBot();
        $rooms = [];
        foreach (range(1, 2) as $i) {
            $room = Conversation::factory()->line()->create(['bot_id' => $bot->id]);
            $this->seedOutageTurn($room, 'สวัสดี');
            $rooms[] = $room;
        }
        $nested = false;
        Http::fake([
            'openrouter.test/api/v1/key' => function () use (&$nested) {
                if (! $nested) {
                    $nested = true;
                    $this->runRecovery();
                }

                return Http::response(['data' => []], 200);
            },
            'api.line.me/*' => Http::response([], 200),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $this->runRecovery();

        $this->assertCount(2, $this->linePushes());
        foreach ($rooms as $room) {
            $this->assertSame(1, collect($this->linePushes())->filter(fn ($r) => $r['to'] === $room->external_customer_id)->count());
            $this->assertSame(1, $this->noticeRows()->where('conversation_id', $room->id)->count());
        }
        $alerts = $this->telegramAlerts();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('ส่งข้อความขอให้ลูกค้าพิมพ์ใหม่ 2 ห้อง · ส่งไม่สำเร็จ 0 ห้อง', $alerts[0]['text']);
    }

    #[Test]
    public function test_a_process_stopped_after_claim_before_push_is_never_retried(): void
    {
        $this->fakeAllWorking();
        [$bot, $room, $tagged] = $this->roomWithOutage('สวัสดี');
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $dispatcher);
        $connection->listen(function (QueryExecuted $query) {
            if (str_starts_with($query->sql, 'update "messages"')
                && collect($query->bindings)->contains(fn ($value) => is_string($value) && str_contains($value, '"recovery_notice":"sending"'))) {
                throw new \RuntimeException('Process stopped after durable claim');
            }
        });
        try {
            $this->runRecovery();
            $this->fail('The process-stop injection was not reached');
        } catch (\RuntimeException $e) {
            $this->assertSame('Process stopped after durable claim', $e->getMessage());
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }

        $this->assertSame('sending', $tagged->refresh()->metadata['recovery_notice']);
        $this->assertCount(0, $this->linePushes());
        $this->assertNoNoticeRow();
        $this->assertCount(0, $this->telegramAlerts());
        Http::fake($this->workingFakes());
        $this->runRecovery();
        Http::assertNothingSent();
        $this->assertNoNoticeRow();
    }

    #[Test]
    public function test_a_recovery_key_added_as_json_null_after_loading_still_loses_the_claim(): void
    {
        [$bot, $room, $tagged] = $this->roomWithOutage('สวัสดี');
        Http::fake([
            'openrouter.test/api/v1/key' => function () use ($tagged) {
                $tagged->update(['metadata' => ['model_error' => 'auth', 'recovery_notice' => null]]);

                return Http::response(['data' => []], 200);
            },
        ]);

        $this->runRecovery();

        $this->assertCount(0, $this->linePushes());
        $this->assertNoNoticeRow();
        $this->assertCount(0, $this->telegramAlerts());
        $this->assertSame(['model_error' => 'auth', 'recovery_notice' => null], $tagged->refresh()->metadata);
    }

    #[Test]
    public function test_claim_writes_whole_json_and_compiles_for_postgres_json_columns(): void
    {
        $this->fakeAllWorking();
        [$bot, $room, $tagged] = $this->roomWithOutage('สวัสดี');
        DB::connection()->enableQueryLog();
        try {
            $this->runRecovery();
            $claims = collect(DB::getQueryLog())->filter(fn ($query) => str_starts_with($query['query'], 'update "messages"')
                && in_array('{"model_error":"auth","recovery_notice":"sending"}', $query['bindings'], true));
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }
        $this->assertCount(1, $claims);
        $sqlite = $claims->first();
        $this->assertStringContainsString('set "metadata" = ?', $sqlite['query']);
        $this->assertStringContainsString('not json_type("metadata", \'$."recovery_notice"\') is not null', $sqlite['query']);
        $this->assertSame('sent', $tagged->refresh()->metadata['recovery_notice']);
        $this->assertCount(1, $this->noticeRows());

        // Compile the actual production claim, without connecting to PostgreSQL.
        $tagged->setConnection('pgsql');
        // pretend() still quotes bindings for its log; use local PDO, never a server.
        DB::connection('pgsql')->setPdo(new \PDO('sqlite::memory:'));
        $postgres = DB::connection('pgsql')->pretend(fn () => (new \ReflectionMethod(OutageRecoveryService::class, 'claim'))
            ->invoke(app(OutageRecoveryService::class), $tagged));
        $this->assertCount(1, $postgres);
        $this->assertStringContainsString('set "metadata" = \'{"model_error":"auth","recovery_notice":"sending"}\'', $postgres[0]['query']);
        $this->assertStringContainsString('not coalesce(("metadata")::jsonb ? \'recovery_notice\', false)', $postgres[0]['query']);
        $this->assertSame('{"model_error":"auth","recovery_notice":"sending"}', $postgres[0]['bindings'][0]);
        if (getenv('OUTAGE_CLAIM_EVIDENCE')) {
            fwrite(STDOUT, "\nSQLite claim: ".json_encode($sqlite)."\nPostgres claim (pretend): ".json_encode($postgres[0])."\n");
        }
    }

    #[Test]
    public function test_manual_recovery_command_sends_once_and_does_not_retry(): void
    {
        $this->fakeAllWorking();
        $this->roomWithOutage('สวัสดี');

        $this->artisan('outage:send-recovery-notices')->assertExitCode(0);
        $this->assertCount(1, $this->linePushes());
        $this->assertCount(1, $this->noticeRows());
        Http::fake($this->workingFakes());
        $this->artisan('outage:send-recovery-notices')->assertExitCode(0);
        Http::assertNothingSent();
        $this->assertCount(1, $this->noticeRows());
    }

    // ------------------------------------------------------------------
    // ช่วงเวลา 08:00-22:00 (Asia/Bangkok)
    // ------------------------------------------------------------------

    #[Test]
    public function test_at_07_59_59_nothing_is_sent_and_nothing_is_marked(): void
    {
        $this->fakeAllWorking();
        Carbon::setTestNow(Carbon::parse('2026-11-14 07:59:59'));
        [$bot, $conversation, $tagged] = $this->roomWithOutage('สวัสดี', Carbon::now()->subMinutes(30));

        $this->runRecovery();

        Http::assertNothingSent();
        $tagged->refresh();
        $this->assertArrayNotHasKey('recovery_notice', $tagged->metadata ?? []);
        $this->assertNoNoticeRow();
    }

    #[Test]
    public function test_at_22_00_00_nothing_is_sent_and_nothing_is_marked(): void
    {
        $this->fakeAllWorking();
        Carbon::setTestNow(Carbon::parse('2026-11-14 22:00:00'));
        [$bot, $conversation, $tagged] = $this->roomWithOutage('สวัสดี', Carbon::now()->subMinutes(30));

        $this->runRecovery();

        Http::assertNothingSent();
        $tagged->refresh();
        $this->assertArrayNotHasKey('recovery_notice', $tagged->metadata ?? []);
        $this->assertNoNoticeRow();
    }

    #[Test]
    public function test_at_08_00_00_exactly_the_notice_is_sent(): void
    {
        $this->fakeAllWorking();
        Carbon::setTestNow(Carbon::parse('2026-11-14 08:00:00'));
        [$bot, $conversation] = $this->roomWithOutage('สวัสดี', Carbon::now()->subMinutes(30));

        $this->runRecovery();

        $this->assertSame([self::CUSTOMER_THAI], $this->pushTexts());
    }

    #[Test]
    public function test_at_21_59_59_the_notice_is_still_sent(): void
    {
        $this->fakeAllWorking();
        Carbon::setTestNow(Carbon::parse('2026-11-14 21:59:59'));
        [$bot, $conversation] = $this->roomWithOutage('สวัสดี', Carbon::now()->subMinutes(30));

        $this->runRecovery();

        $this->assertCount(1, $this->linePushes());
    }

    #[Test]
    public function test_a_room_that_waited_overnight_is_sent_at_the_first_run_from_08(): void
    {
        $this->fakeAllWorking();
        // แถวติด tag ตอน 23:30 ของเมื่อวาน (นอกหน้าต่าง) — รอบแรกของเช้า 08:00 ต้องส่ง
        Carbon::setTestNow(Carbon::parse('2026-11-14 08:00:00'));
        [$bot, $conversation] = $this->roomWithOutage('สวัสดี', Carbon::parse('2026-11-13 23:30:00'));

        $this->runRecovery();

        $this->assertSame([self::CUSTOMER_THAI], $this->pushTexts());
    }

    // ------------------------------------------------------------------
    // Probe กุญแจ
    // ------------------------------------------------------------------

    #[Test]
    public function test_a_401_probe_sends_nothing_and_marks_nothing(): void
    {
        [$bot, $conversation, $tagged] = $this->roomWithOutage('สวัสดี');
        Http::fake([
            'openrouter.test/api/v1/key' => Http::response(['error' => ['message' => 'User not found.', 'code' => 401]], 401),
        ]);

        $this->runRecovery();

        $this->assertCount(0, $this->linePushes());
        $this->assertCount(0, $this->telegramAlerts());
        $this->assertNoNoticeRow();
        $tagged->refresh();
        $this->assertArrayNotHasKey('recovery_notice', $tagged->metadata ?? []);
    }

    #[Test]
    public function test_a_500_probe_sends_nothing_and_marks_nothing(): void
    {
        [$bot, $conversation, $tagged] = $this->roomWithOutage('สวัสดี');
        Http::fake([
            'openrouter.test/api/v1/key' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        $this->runRecovery();

        $this->assertCount(0, $this->linePushes());
        $this->assertCount(0, $this->telegramAlerts());
        $this->assertNoNoticeRow();
        $tagged->refresh();
        $this->assertArrayNotHasKey('recovery_notice', $tagged->metadata ?? []);
    }

    #[Test]
    public function test_a_probe_timeout_sends_nothing_and_marks_nothing(): void
    {
        [$bot, $conversation, $tagged] = $this->roomWithOutage('สวัสดี');
        Http::fake([
            'openrouter.test/api/v1/key' => fn () => throw new ConnectionException('timed out'),
        ]);

        $this->runRecovery();

        $this->assertCount(0, $this->linePushes());
        $this->assertCount(0, $this->telegramAlerts());
        $this->assertNoNoticeRow();
        $tagged->refresh();
        $this->assertArrayNotHasKey('recovery_notice', $tagged->metadata ?? []);
    }

    #[Test]
    public function test_a_200_probe_means_the_notice_is_sent(): void
    {
        $this->fakeAllWorking();
        [$bot, $conversation] = $this->roomWithOutage('สวัสดี');

        $this->runRecovery();

        $this->assertSame([self::CUSTOMER_THAI], $this->pushTexts());
    }

    #[Test]
    public function test_with_no_waiting_room_the_run_makes_no_http_call_at_all(): void
    {
        $this->fakeAllWorking();
        // ไม่มีห้องรอเลย — probe ห้ามยิงด้วย
        $this->runRecovery();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // ข้อความลูกค้า
    // ------------------------------------------------------------------

    #[Test]
    public function test_an_english_speaking_customer_gets_thai_plus_english(): void
    {
        $this->fakeAllWorking();
        [$bot, $conversation] = $this->roomWithOutage('Hello');

        $this->runRecovery();

        $this->assertSame([self::CUSTOMER_THAI."\n\n".self::CUSTOMER_EN], $this->pushTexts());
    }

    #[Test]
    public function test_hi_also_gets_the_english_appendix(): void
    {
        $this->fakeAllWorking();
        [$bot, $conversation] = $this->roomWithOutage('Hi');

        $this->runRecovery();

        $this->assertSame([self::CUSTOMER_THAI."\n\n".self::CUSTOMER_EN], $this->pushTexts());
    }

    #[Test]
    public function test_a_thai_customer_gets_thai_only(): void
    {
        $this->fakeAllWorking();
        [$bot, $conversation] = $this->roomWithOutage('เอา2ชุดครับ');

        $this->runRecovery();

        $this->assertSame([self::CUSTOMER_THAI], $this->pushTexts());
    }

    // ------------------------------------------------------------------
    // แถวหลังส่ง / ส่งล้ม / รอบที่สอง
    // ------------------------------------------------------------------

    #[Test]
    public function test_after_a_successful_send_the_rows_record_the_notice(): void
    {
        $this->fakeAllWorking();
        [$bot, $conversation, $tagged] = $this->roomWithOutage('สวัสดี');

        $this->runRecovery();

        $notice = $this->noticeRows()->first();
        $this->assertNotNull($notice);
        $this->assertSame('bot', $notice->sender);
        $this->assertSame('text', $notice->type);
        $this->assertNull($notice->model_used);
        $this->assertTrue($notice->metadata['outage_notice']);
        $this->assertSame($this->pushTexts()[0], $notice->content);

        $tagged->refresh();
        $this->assertSame('sent', $tagged->metadata['recovery_notice']);

        // รอบที่สองต้องไม่ส่งอะไรอีก
        Http::fake($this->workingFakes());
        $this->runRecovery();
        Http::assertNothingSent();
        $this->assertCount(1, Message::query()->where('metadata->outage_notice', true)->get());
    }

    #[Test]
    public function test_a_failed_line_send_marks_failed_and_never_retries(): void
    {
        [$bot, $conversation, $tagged] = $this->roomWithOutage('สวัสดี');
        Http::fake([
            'openrouter.test/api/v1/key' => Http::response(['data' => []], 200),
            'api.line.me/*' => Http::response(['message' => 'Failed to send messages'], 500),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $this->runRecovery();

        $this->assertCount(0, $this->noticeRows());
        $tagged->refresh();
        $this->assertSame('failed', $tagged->metadata['recovery_notice']);

        // "Back" รายงาน failed 1 ห้อง
        $alerts = $this->telegramAlerts();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('ส่งไม่สำเร็จ 1 ห้อง', $alerts[0]['text']);

        // รอบถัดไปไม่ส่งซ้ำให้ห้องนั้น (ไม่ retry)
        Http::fake($this->workingFakes());
        $this->runRecovery();
        Http::assertNothingSent();
    }

    #[Test]
    public function test_the_back_alert_carries_the_exact_counts_per_bot(): void
    {
        $bot = $this->makeBot();
        $ok = Conversation::factory()->line()->create(['bot_id' => $bot->id]);
        $this->seedOutageTurn($ok, 'สวัสดี');
        $bad = Conversation::factory()->line()->create(['bot_id' => $bot->id]);
        $this->seedOutageTurn($bad, 'สวัสดี');

        // ห้องแรกส่งได้ ห้องที่สอง LINE ล้ม
        Http::fake([
            'openrouter.test/api/v1/key' => Http::response(['data' => []], 200),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            'api.line.me/*' => Http::sequence()
                ->push([], 200)
                ->push(['message' => 'nope'], 500),
        ]);

        $this->runRecovery();

        $alerts = $this->telegramAlerts();
        $this->assertCount(1, $alerts);
        $expected = '✅ <b>บอทกลับมาตอบได้แล้ว</b> ('.$this->esc($bot->name).")\n"
            .'ส่งข้อความขอให้ลูกค้าพิมพ์ใหม่ 1 ห้อง · ส่งไม่สำเร็จ 1 ห้อง';
        $this->assertSame($expected, $alerts[0]['text']);
    }

    #[Test]
    public function test_facebook_and_telegram_rooms_get_their_notice_via_their_channel(): void
    {
        $this->fakeAllWorking();
        $fbBot = $this->makeBot();
        $fbRoom = Conversation::factory()->facebook()->create(['bot_id' => $fbBot->id]);
        $this->seedOutageTurn($fbRoom, 'สวัสดี');
        $tgBot = $this->makeBot();
        $tgRoom = Conversation::factory()->create([
            'bot_id' => $tgBot->id,
            'channel_type' => 'telegram',
            'external_customer_id' => '999888',
        ]);
        $this->seedOutageTurn($tgRoom, 'สวัสดี');

        $this->runRecovery();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com')
            && str_contains($r->url(), 'me/messages'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.telegram.org')
            && str_contains($r->url(), 'sendMessage')
            && $r['chat_id'] === '999888');
        $this->assertSame(2, $this->noticeRows()->count());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeBot(bool $withTelegramPlugin = true): Bot
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'channel_access_token' => 'line-token',
        ]);

        if ($withTelegramPlugin) {
            $flow = Flow::factory()->create(['bot_id' => $bot->id]);
            $bot->update(['default_flow_id' => $flow->id]);
            FlowPlugin::create([
                'flow_id' => $flow->id,
                'type' => 'telegram',
                'name' => 'แจ้งเตือน',
                'enabled' => true,
                'trigger_condition' => 'always',
                'config' => ['access_token' => 'TOK', 'chat_id' => '999'],
            ]);
        }

        return $bot;
    }

    /** ห้อง + เทิร์นล่ม: ลูกค้าพิมพ์ → บอทตอบ auth text ติด tag แล้ว */
    private function roomWithOutage(string $customerText, ?Carbon $taggedAt = null): array
    {
        $bot = $this->makeBot();
        $conversation = Conversation::factory()->line()->create(['bot_id' => $bot->id]);
        $tagged = $this->seedOutageTurn($conversation, $customerText, $taggedAt);

        return [$bot, $conversation, $tagged];
    }

    private function seedOutageTurn(Conversation $conversation, string $customerText, ?Carbon $at = null): Message
    {
        $at ??= Carbon::now()->subMinutes(30);

        $customer = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => $customerText,
            'type' => 'text',
        ]);
        $customer->forceFill(['created_at' => $at->copy()->subMinute(), 'updated_at' => $at->copy()->subMinute()])->save();

        $tagged = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'content' => self::AUTH_TEXT,
            'type' => 'text',
            'model_used' => null,
            'metadata' => ['model_error' => 'auth'],
        ]);
        $tagged->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $tagged;
    }

    private function fakeAllWorking(): void
    {
        Http::fake($this->workingFakes());
    }

    private function workingFakes(): array
    {
        return [
            'openrouter.test/api/v1/key' => Http::response(['data' => []], 200),
            'api.line.me/*' => Http::response([], 200),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm1'], 200),
        ];
    }

    private function runRecovery(): void
    {
        app(OutageRecoveryService::class)->run();
    }

    private function linePushes(): array
    {
        return Http::recorded(fn ($r) => str_contains($r->url(), 'api.line.me') && str_contains($r->url(), 'message/push'))
            ->map(fn ($pair) => $pair[0])
            ->values()
            ->all();
    }

    private function pushTexts(): array
    {
        return collect($this->linePushes())
            ->map(fn ($r) => $r['messages'][0]['text'] ?? null)
            ->all();
    }

    private function telegramAlerts(): array
    {
        return Http::recorded(fn ($r) => str_contains($r->url(), 'api.telegram.org') && str_contains($r->url(), 'sendMessage'))
            ->map(fn ($pair) => $pair[0])
            ->values()
            ->all();
    }

    private function noticeRows()
    {
        return Message::query()->where('metadata->outage_notice', true)->orderBy('id')->get();
    }

    private function assertNoNoticeRow(): void
    {
        $this->assertCount(0, $this->noticeRows());
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
