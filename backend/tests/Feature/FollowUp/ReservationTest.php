<?php

namespace Tests\Feature\FollowUp;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\LeadRecoveryLog;
use App\Models\User;
use App\Services\FollowUp\FollowUpEligibility;
use App\Services\FollowUp\FollowUpReservationService;
use App\Services\FollowUp\FollowUpSalesProjector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด B2c — แถว candidate และการจองโควตาร่างรายวันแบบ atomic
 *
 * สัญญาจาก plan §3.3 lines 140-141, 155 และ b2c.md:
 * - candidate(): insert-or-read ตาม unique (conversation_id, silence_user_message_id)
 *   anchor/ธงเอาจากแถว follow_up_sales_signals ล่าสุดของห้อง ห้องไม่มี signal/ไม่มี
 *   ข้อความลูกค้าสุดท้าย = ไม่สร้างแถว
 * - reserveDraft(): ใน transaction เดียว ล็อก bot_settings นับด้วยกฎเดียวกับประตู
 *   (draft_day = วันนี้กรุงเทพฯ และ drafted_at/draft_reserved_at ไม่ null) ต่ำกว่า cap
 *   จึงเขียนจอง + claim_token; เกิน cap คืน false แล้วแถวคงเดิม
 * - markDraftProduced / releaseDraft ทำงานเฉพาะ token ตรงกับ claim_token ที่เก็บ
 * - เวลา $now โซนไหนก็ได้ ต้อง normalized เป็นกรุงเทพฯ ทุกเมธอดสาธารณะ
 *
 * หมายเหตุ: เทสนี้รันบน SQLite แบบ deterministic — ไม่ใช่พิสูจน์ concurrency
 * สอง connection จริงบน PostgreSQL เป็นของการ์ด QA (plan line 353)
 */
class ReservationTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 10:00:00';

    private FollowUpReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new FollowUpReservationService;
    }

    // ------------------------------------------------------------------
    // plan line 351 — ส่วน "แถว": candidate ซ้ำ ได้แถวเดิม ไม่สร้างซ้ำ
    // ------------------------------------------------------------------

    #[Test]
    public function test_same_silence_and_customer_gap(): void
    {
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'U111');
        $now = $this->now();

        $first = $this->service->candidate($room, $now);
        $rowAfterFirst = $this->logRow($first->id);

        // ครั้งที่สองของเงียบเดียวกัน = แถวเดิม ทุกคอลัมน์คงเดิม
        $second = $this->service->candidate($room, $now);
        $this->assertSame($first->id, $second->id, 'second candidate() for the same silence must return the same row');
        $this->assertRowUnchanged($rowAfterFirst, $first->id, 'second candidate() must not change the row');
        $this->assertSame(1, $this->logCount(), 'one silence must produce exactly one log row');

        // skipped → คืนแถวเดิมตามที่เป็น ห้ามสร้างใหม่
        DB::table('lead_recovery_logs')->where('id', $first->id)->update(['delivery_status' => 'skipped']);
        $afterSkip = $this->service->candidate($room, $now);
        $this->assertSame($first->id, $afterSkip->id);
        $this->assertSame('skipped', $afterSkip->delivery_status, 'a skipped log must be returned as it is');
        $this->assertSame(1, $this->logCount());

        // expired → เช่นเดียวกัน
        DB::table('lead_recovery_logs')->where('id', $first->id)->update(['delivery_status' => 'expired']);
        $afterExpire = $this->service->candidate($room, $now);
        $this->assertSame($first->id, $afterExpire->id);
        $this->assertSame('expired', $afterExpire->delivery_status, 'an expired log must be returned as it is');
        $this->assertSame(1, $this->logCount());

        // ลูกค้าเขียนใหม่ = เงียบใหม่ = แถวใหม่ anchor ใหม่
        $this->addMessage($room->id, 'user', '2026-11-15 20:00:00', 'พร้อมโอนแล้วครับ');
        $room->refresh();
        FollowUpSalesProjector::refresh($room, $this->at('2026-11-16 12:00:00'));
        $later = $this->service->candidate($room, $this->at('2026-11-16 12:00:00'));

        $this->assertNotSame($first->id, $later->id, 'a new customer message must start a new silence row');
        $this->assertSame(
            (int) DB::table('messages')->where('conversation_id', $room->id)->where('sender', 'user')->max('id'),
            $later->silence_user_message_id,
            'new row must anchor at the newest customer message'
        );
        $this->assertSame(2, $this->logCount());
    }

    #[Test]
    public function test_candidate_never_creates_row_without_signal_or_customer_message(): void
    {
        $bot = $this->seedBot();
        $now = $this->now();

        // ห้องยังไม่มี signal row (ยังไม่เคย refresh)
        $roomNoSignal = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'U900']);
        $this->addMessage($roomNoSignal->id, 'user', '2026-11-15 08:55:00', 'สอบถามครับ');
        $this->addMessage($roomNoSignal->id, 'user', '2026-11-15 08:59:00', 'สนใจครับ');

        try {
            $this->service->candidate($roomNoSignal, $now);
            $this->fail('candidate() without a signal row must not succeed');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame(0, $this->logCount(), 'no row may be created without a signal row');

        // มี signal แต่ไม่มีข้อความลูกค้า (มีแต่ข้อความบอท)
        $roomBotOnly = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'U901']);
        $this->addMessage($roomBotOnly->id, 'bot', '2026-11-15 08:55:00', 'สวัสดีครับ');
        FollowUpSalesProjector::refresh($roomBotOnly, $now);

        try {
            $this->service->candidate($roomBotOnly, $now);
            $this->fail('candidate() without a last customer message must not succeed');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame(0, $this->logCount(), 'no row may be created without a last customer message');
    }

    #[Test]
    public function test_candidate_takes_anchors_flags_and_customer_key_from_signal_row(): void
    {
        $bot = $this->seedBot();
        $room = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'U222']);
        $this->addMessage($room->id, 'user', '2026-11-15 08:55:00', 'สนใจสินค้าครับ');
        $this->addMessage($room->id, 'user', '2026-11-15 08:57:00', 'ราคาเท่าไหร่ครับ');
        $this->addMessage($room->id, 'bot', '2026-11-15 08:58:00', 'แจ้งราคาให้แล้วนะครับ');
        FollowUpSalesProjector::refresh($room, $this->now());

        // จำลอง signal ที่บอกสเตปัชำระเงิน (ค่าที่ projector เคยเขียนไว้)
        DB::table('follow_up_sales_signals')->where('conversation_id', $room->id)->update([
            'payment_step' => true,
            'transfer_summary_sent' => true,
            'sales_started_at' => '2026-11-01 09:00:00',
        ]);

        $log = $this->service->candidate($room, $this->now());
        $signal = DB::table('follow_up_sales_signals')->where('conversation_id', $room->id)->first();

        $this->assertSame(LeadRecoveryLog::DELIVERY_STATUS_CANDIDATE, $log->delivery_status);
        $this->assertSame((int) $signal->last_user_message_id, $log->silence_user_message_id, 'silence anchor = last customer message');
        $this->assertSame((int) $signal->last_message_id, $log->observed_last_message_id, 'observed last = last message of the room');
        $this->assertNotSame($log->silence_user_message_id, $log->observed_last_message_id, 'bot message after the anchor must be the observed last');
        $this->assertSame('2026-11-01 09:00:00', $log->sales_started_at->format('Y-m-d H:i:s'));
        $this->assertTrue($log->payment_step);
        $this->assertTrue($log->transfer_summary_sent);
        $this->assertSame(hash('sha256', $bot->id.'|line|U222'), $log->customer_key, 'customer_key = SHA256(bot_id|line|external_customer_id)');
        $this->assertNull($log->message_sent, 'a candidate is not a send');
        $this->assertNull($log->sent_at, 'a candidate is not a send');
        $this->assertSame(0, $log->attempt_number);
        $this->assertSame('ai', $log->message_mode);
    }

    // ------------------------------------------------------------------
    // plan line 353 — งบร่างรายวัน 10 และ claim token
    // ------------------------------------------------------------------

    #[Test]
    public function test_atomic_daily_cap_and_claim_token(): void
    {
        $bot = $this->seedBot();
        $now = $this->now();

        // 10 การจองสำเร็จ (9 filler + ห้องหลัก)
        $fillers = [];
        foreach (range(1, 9) as $i) {
            $log = $this->service->candidate($this->makeRoom($bot, "UF$i"), $now);
            $this->assertTrue($this->service->reserveDraft($log, $now), "filler $i must reserve");
            $fillers[$i] = $log;
        }
        $mainLog = $this->service->candidate($this->makeRoom($bot, 'UMAIN'), $now);
        $this->assertTrue($this->service->reserveDraft($mainLog, $now), 'the tenth reservation must succeed');
        $this->assertSame(10, $this->usedSlots($bot->id, '2026-11-16'));

        // หลังจอง: ร่างผลิตแล้ว (เช็คไม่ผ่านก็ตาม) และบางแถวถูก skip/expired — ยังกินโควตาเต็มที่
        $mainRow = $this->logRow($mainLog->id);
        $this->assertTrue($this->service->markDraftProduced($mainLog, $mainRow->claim_token, $now), 'mark must succeed with the stored token');
        $mainRow = $this->logRow($mainLog->id);
        $this->assertNotNull($mainRow->drafted_at, 'produced draft must stamp drafted_at');
        $this->assertNull($mainRow->claim_token, 'mark must consume the claim so release cannot free the slot');
        $this->assertSame('2026-11-16', $mainRow->draft_day, 'the slot stays on the produced day');
        $this->assertSame(10, $this->usedSlots($bot->id, '2026-11-16'), 'a produced draft still occupies its slot');
        $this->assertFalse(
            $this->service->releaseDraft($mainLog, '00000000-0000-0000-0000-000000000000', $now),
            'release after mark must fail: a produced draft never frees its slot'
        );
        DB::table('lead_recovery_logs')->where('id', $fillers[1]->id)->update(['delivery_status' => 'skipped']);
        DB::table('lead_recovery_logs')->where('id', $fillers[2]->id)->update(['delivery_status' => 'expired']);
        $this->assertSame(10, $this->usedSlots($bot->id, '2026-11-16'), 'skipped/expired drafts still count');

        // คนที่สิบเอ็ด ติด cap — แถวคงเดิมทุกคอลัมน์
        $eleventh = $this->service->candidate($this->makeRoom($bot, 'U11'), $now);
        $beforeEleventh = $this->logRow($eleventh->id);
        $this->assertFalse($this->service->reserveDraft($eleventh, $now), 'the eleventh reservation must fail at the cap');
        $this->assertRowUnchanged($beforeEleventh, $eleventh->id, 'a failed reserve must leave the row unchanged');

        // token ผิด: release และ mark ต้อง false และแถวคงเดิม
        $f4Row = $this->logRow($fillers[4]->id);
        $this->assertFalse($this->service->releaseDraft($fillers[4], '00000000-0000-0000-0000-000000000000', $now));
        $this->assertRowUnchanged($f4Row, $fillers[4]->id, 'wrong-token release must change nothing');
        $f5Row = $this->logRow($fillers[5]->id);
        $this->assertFalse($this->service->markDraftProduced($fillers[5], 'not-the-token', $now));
        $this->assertRowUnchanged($f5Row, $fillers[5]->id, 'wrong-token mark must change nothing');
        $this->assertNull($this->logRow($fillers[5]->id)->drafted_at);

        // จองซ้ำ log เดิม = ใช้ slot เดิม ไม่กินเพิ่ม token คงเดิม
        $f6Row = $this->logRow($fillers[6]->id);
        $this->assertTrue($this->service->reserveDraft($fillers[6], $now), 're-reserving the same log must succeed');
        $this->assertSame(10, $this->usedSlots($bot->id, '2026-11-16'), 'double reserve must still use one slot');
        $f6After = $this->logRow($fillers[6]->id);
        $this->assertSame($f6Row->claim_token, $f6After->claim_token, 're-reserve keeps the original claim');
        $this->assertSame($f6Row->draft_reserved_at, $f6After->draft_reserved_at, 're-reserve must not restamp the reservation');

        // release คืน slot พอดีหนึ่ง
        $this->assertTrue($this->service->releaseDraft($fillers[7], $this->logRow($fillers[7]->id)->claim_token, $now), 'release with the stored token must succeed');
        $this->assertSame(9, $this->usedSlots($bot->id, '2026-11-16'), 'a release must free exactly one slot');
        $f7After = $this->logRow($fillers[7]->id);
        $this->assertNull($f7After->draft_reserved_at, 'release must null draft_reserved_at');
        $this->assertNull($f7After->draft_day, 'release must null draft_day');
        $this->assertNull($f7After->claimed_at, 'release must null claimed_at');
        $this->assertNull($f7After->claim_token, 'release must null claim_token');
        $this->assertTrue($this->service->reserveDraft($eleventh, $now), 'the freed slot must be reservable again');
        $this->assertSame(10, $this->usedSlots($bot->id, '2026-11-16'));

        // จอง 23:59 กรุงเทพฯ = วันที่ 16 แต่ไม่นับเป็นโควตาวันที่ 17
        $lateLog = $this->service->candidate($this->makeRoom($bot, 'ULATE'), $this->at('2026-11-16 23:59:00'));
        $this->assertFalse($this->service->reserveDraft($lateLog, $this->at('2026-11-16 23:59:00')), 'day 16 is already full');
        $this->assertTrue($this->service->releaseDraft($eleventh, $this->logRow($eleventh->id)->claim_token, $now), 'release one day-16 slot');
        $this->assertTrue($this->service->reserveDraft($lateLog, $this->at('2026-11-16 23:59:00')), '23:59 reservation takes a day-16 slot');
        $this->assertSame('2026-11-16', $this->logRow($lateLog->id)->draft_day, '23:59 Bangkok belongs to that day');
        $this->assertSame(10, $this->usedSlots($bot->id, '2026-11-16'));
        $this->assertSame(0, $this->usedSlots($bot->id, '2026-11-17'), 'no slot is used on the next Bangkok day yet');

        $nextDay = $this->service->candidate($this->makeRoom($bot, 'UNEXT'), $this->at('2026-11-17 00:00:00'));
        $this->assertTrue($this->service->reserveDraft($nextDay, $this->at('2026-11-17 00:00:00')), '00:00 next day must not inherit day-16 reservations');
        $this->assertSame('2026-11-17', $this->logRow($nextDay->id)->draft_day);
        $this->assertSame(1, $this->usedSlots($bot->id, '2026-11-17'), 'the 23:59 reservation must not count at 00:00 next day');
        $this->assertSame(10, $this->usedSlots($bot->id, '2026-11-16'), 'day-16 rows keep their own day');
    }

    #[Test]
    public function test_gate_and_reservation_agree_on_daily_cap(): void
    {
        $seeded = $this->seedGateRoom();
        $bot = $seeded['bot'];
        $room = $seeded['room'];
        $now = $this->now();

        $roomLog = $this->service->candidate($room, $now);

        // เติมครบ 10 ด้วย service เดียวกัน
        $fillerLogs = [];
        foreach (range(1, 10) as $i) {
            $log = $this->service->candidate($this->makeRoom($bot, "UG$i"), $now);
            $this->assertTrue($this->service->reserveDraft($log, $now), "filler $i must reserve");
            $fillerLogs[$i] = $log;
        }

        $this->assertFalse($this->service->reserveDraft($roomLog, $now), 'reserve must fail at the cap');
        $this->assertNotContains(
            $room->id,
            FollowUpEligibility::query($bot, $now)->get()->pluck('id')->all(),
            'the gate must agree: the room drops out at the cap'
        );

        $releaseRow = $this->logRow($fillerLogs[1]->id);
        $this->assertTrue($this->service->releaseDraft($fillerLogs[1], $releaseRow->claim_token, $now), 'release one filler slot');
        $this->assertContains(
            $room->id,
            FollowUpEligibility::query($bot, $now)->get()->pluck('id')->all(),
            'after one release the gate returns the room again'
        );
        $this->assertTrue($this->service->reserveDraft($roomLog, $now), 'the room log can now reserve the freed slot');
    }

    #[Test]
    public function test_last_slot_race_exactly_one_winner(): void
    {
        $bot = $this->seedBot();
        $now = $this->now();

        foreach (range(1, 9) as $i) {
            $log = $this->service->candidate($this->makeRoom($bot, "UR$i"), $now);
            $this->assertTrue($this->service->reserveDraft($log, $now), "filler $i must reserve");
        }

        $first = $this->service->candidate($this->makeRoom($bot, 'URA'), $now);
        $second = $this->service->candidate($this->makeRoom($bot, 'URB'), $now);

        $firstWon = $this->service->reserveDraft($first, $now);
        $secondWon = $this->service->reserveDraft($second, $now);

        $this->assertTrue($firstWon, 'exactly one of the two logs wins the last slot');
        $this->assertFalse($secondWon, 'the loser must not take a slot');
        $this->assertSame(10, $this->usedSlots($bot->id, '2026-11-16'));
        $secondRow = $this->logRow($second->id);
        $this->assertNull($secondRow->draft_day);
        $this->assertNull($secondRow->draft_reserved_at);
        $this->assertNull($secondRow->claim_token);
    }

    // ------------------------------------------------------------------
    // b2c.md acceptance — $now คนละโซน เขียน draft_day เดียวกัน
    // ------------------------------------------------------------------

    #[Test]
    public function test_draft_day_is_bangkok_date_whatever_timezone_now_came_in(): void
    {
        $bot = $this->seedBot();

        // 16:59 UTC = 23:59 กรุงเทพฯ — ทั้ง candidate() และ reserveDraft() ต้องได้วันที่ 16
        $utcInstant = CarbonImmutable::parse('2026-11-16 16:59:00', 'UTC');
        $viaUtc = $this->service->candidate($this->makeRoom($bot, 'UUTC'), $utcInstant);
        $this->assertTrue($this->service->reserveDraft($viaUtc, $utcInstant));
        $this->assertSame('2026-11-16', $this->logRow($viaUtc->id)->draft_day, 'UTC 16:59 = Bangkok 23:59 → day 16');

        // จังหวะเดียวกันส่งมาเป็นเวลากรุงเทพฯ ต้องได้ draft_day เดียวกัน
        $bkkInstant = CarbonImmutable::parse('2026-11-16 23:59:00', 'Asia/Bangkok');
        $this->assertTrue($utcInstant->equalTo($bkkInstant), 'pre-condition: both arguments are the same instant');
        $viaBkk = $this->service->candidate($this->makeRoom($bot, 'UBKK'), $bkkInstant);
        $this->assertTrue($this->service->reserveDraft($viaBkk, $bkkInstant));
        $this->assertSame(
            $this->logRow($viaUtc->id)->draft_day,
            $this->logRow($viaBkk->id)->draft_day,
            'the same instant in UTC and Bangkok must write the same draft_day'
        );

        // 17:30 UTC = 00:30 กรุงเทพฯ วันถัดไป → วันที่ 17 (ไม่ใช่วัน UTC)
        $nextDayUtc = CarbonImmutable::parse('2026-11-16 17:30:00', 'UTC');
        $viaNext = $this->service->candidate($this->makeRoom($bot, 'UNEXT2'), $nextDayUtc);
        $this->assertTrue($this->service->reserveDraft($viaNext, $nextDayUtc));
        $this->assertSame('2026-11-17', $this->logRow($viaNext->id)->draft_day, 'UTC 17:30 = Bangkok 00:30 next day → day 17');
    }

    // ------------------------------------------------------------------
    // Fixtures and helpers
    // ------------------------------------------------------------------

    private function seedBot(): Bot
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-6-luna',
        ]);
        config(['customer_follow_up.bot_ids' => [$bot->id]]);
        config(['customer_follow_up.admin_web_url' => 'https://admin.example.test']);
        config(['services.telegram_alert.secret' => 'webhook-secret-test']);

        DB::table('bot_settings')->insert([
            'bot_id' => $bot->id,
            'follow_up_enabled' => true,
            'follow_up_mode' => 'approve',
            'follow_up_started_at' => '2026-10-17 10:00:00',
            'follow_up_activation_message_id' => 0,
            'follow_up_activated_at' => '2026-10-17 10:00:00',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return $bot;
    }

    /** ห้องผ่านประตูครบ (plugin telegram + allowlist) สำหรับเทสที่เทียบกับ FollowUpEligibility */
    private function seedGateRoom(): array
    {
        $bot = $this->seedBot();

        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งเตือน',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'tok-123', 'chat_id' => '999', 'authorized_user_ids' => ['555']],
        ]);

        $room = $this->makeRoom($bot, 'U111');

        return ['bot' => $bot, 'room' => $room];
    }

    /** ห้อง + ข้อความลูกค้าสองข้อความ (เงียบ 25 ชม. ณ NOW) + projector refresh — พร้อม candidate */
    private function makeRoom(Bot $bot, string $externalCustomerId): Conversation
    {
        $room = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => $externalCustomerId,
        ]);
        $this->addMessage($room->id, 'user', '2026-11-15 08:55:00', 'สนใจสินค้าครับ');
        $this->addMessage($room->id, 'user', '2026-11-15 08:59:00', 'ประเมินราคาให้หน่อยครับ');
        FollowUpSalesProjector::refresh($room, $this->now());

        return $room;
    }

    private function addMessage(int $conversationId, string $sender, string $at, string $content): int
    {
        return DB::table('messages')->insertGetId([
            'conversation_id' => $conversationId,
            'sender' => $sender,
            'content' => $content,
            'type' => 'text',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function now(): CarbonImmutable
    {
        return $this->at(self::NOW);
    }

    private function at(string $localBangkok): CarbonImmutable
    {
        return CarbonImmutable::parse($localBangkok, 'Asia/Bangkok');
    }

    /** กฎนับเดียวกับประตู (FollowUpEligibility.php:196-197) */
    private function usedSlots(int $botId, string $day): int
    {
        return DB::table('lead_recovery_logs')
            ->where('bot_id', $botId)
            ->where('draft_day', $day)
            ->where(function ($query): void {
                $query->whereNotNull('drafted_at')->orWhereNotNull('draft_reserved_at');
            })
            ->count();
    }

    private function logRow(int $logId): object
    {
        $row = DB::table('lead_recovery_logs')->where('id', $logId)->first();
        $this->assertNotNull($row, 'log row must exist');

        return $row;
    }

    /** เทียบแถว "คงเดิมทุกคอลัมน์" — assertSame บน stdClass สองตัวเช็ค identity ไม่ใช่ค่า */
    private function assertRowUnchanged(object $before, int $logId, string $message): void
    {
        $this->assertSame((array) $before, (array) $this->logRow($logId), $message);
    }

    private function logCount(): int
    {
        return DB::table('lead_recovery_logs')->count();
    }
}
