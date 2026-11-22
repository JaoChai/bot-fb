<?php

namespace Tests\Feature\FollowUp;

use App\Models\Bot;
use App\Models\User;
use App\Services\FollowUp\FollowUpResponseHours;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HR1 — ประตูเวลาทำการของบอทสำหรับ "ตามลูกค้า" (shared gate, pure นาฬิกาเข้า-คำตอบออก)
 *
 * สัญญาจากการ์ด HR1:
 * - หน้าต่างที่ทำงานได้ = จุดตัดของหน้าต่างคงที่ 08:00-22:00 กรุงเทพฯ กับสล็อต response hours ของบอท
 * - เปิด = null; ปิด = reason เสถียร: day_closed (วันนั้นไม่มีสล็อต) หรือ outside_hours
 *   (นอกหน้าต่างคงที่ หรือนอกสล็อตของบอท)
 * - วันและนาฬิกาของสล็อตเอาจาก response_hours_timezone ของบอท (fallback Asia/Bangkok)
 *   คำนวณจาก $now ที่ caller ส่งเข้ามา ไม่อ่านนาฬิกาจริงเอง
 * - setting ปิด / ค่าว่าง / ไม่มีแถว settings = ไม่เพิ่มข้อจำกัด (หน้าต่างคงที่ยังกั้นเหมือนเดิม)
 * - สล็อตเสียรูป (ไม่ใช่ array, ขาด start/end, เวลาอ่านไม่ได้) = ปิดเสมอ (fail-closed)
 */
class ResponseHoursGateTest extends TestCase
{
    use RefreshDatabase;

    /** config จริงของบอท 26 (production, อ่านจาก Neon 22 พ.ย. 2026) — จ-ส 08:00-23:00, ไม่มี sun */
    private const BOT26_HOURS = [
        'mon' => [['start' => '08:00', 'end' => '23:00']],
        'tue' => [['start' => '08:00', 'end' => '23:00']],
        'wed' => [['start' => '08:00', 'end' => '23:00']],
        'thu' => [['start' => '08:00', 'end' => '23:00']],
        'fri' => [['start' => '08:00', 'end' => '23:00']],
        'sat' => [['start' => '08:00', 'end' => '23:00']],
    ];

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function bot26ClockCases(): array
    {
        return [
            // 16/11/2026 = จันทร์, 21/11 = เสาร์, 22/11 = อาทิตย์
            'sunday 10:00 is a closed day' => ['2026-11-22 10:00:00', 'day_closed'],
            'saturday 07:59:59 before fixed window' => ['2026-11-21 07:59:59', 'outside_hours'],
            'saturday 08:00:00 opens the day' => ['2026-11-21 08:00:00', null],
            'saturday 21:59:59 still open' => ['2026-11-21 21:59:59', null],
            'saturday 22:00:00 fixed window wins over slot end 23:00' => ['2026-11-21 22:00:00', 'outside_hours'],
            'monday 10:00 open' => ['2026-11-16 10:00:00', null],
        ];
    }

    #[Test]
    #[DataProvider('bot26ClockCases')]
    public function test_bot26_real_config_with_travelled_bangkok_clock(string $clock, ?string $expected): void
    {
        $bot = $this->botWithHours(self::BOT26_HOURS);

        $this->assertSame($expected, FollowUpResponseHours::closureReason((int) $bot->id, $this->at($clock)));
    }

    #[Test]
    public function test_custom_slot_edges_answer_outside_hours(): void
    {
        $bot = $this->botWithHours(['mon' => [['start' => '09:00', 'end' => '12:00']]]);

        $this->assertSame('outside_hours', FollowUpResponseHours::closureReason((int) $bot->id, $this->at('2026-11-16 08:30:00')), 'ก่อนสล็อตบอท = ปิด');
        $this->assertNull(FollowUpResponseHours::closureReason((int) $bot->id, $this->at('2026-11-16 09:00:00')), 'ขอบเปิดสล็อต = เปิด');
        $this->assertSame('outside_hours', FollowUpResponseHours::closureReason((int) $bot->id, $this->at('2026-11-16 12:00:00')), 'ขอบปิดสล็อต (end เข้มงวด) = ปิด');
    }

    #[Test]
    public function test_day_key_comes_from_the_bot_timezone_not_bangkok(): void
    {
        // นิวยอร์ก (EST = กรุงเทพฯ - 12 ชม.): จันทร์ 10:00 กรุงเทพฯ = อาทิตย์ 22:00 นิวยอร์ก → วันเปิด (มีแต่ sun)
        $bot = $this->botWithHours(['sun' => [['start' => '00:00', 'end' => '23:59']]], tz: 'America/New_York');

        $this->assertNull(
            FollowUpResponseHours::closureReason((int) $bot->id, $this->at('2026-11-16 10:00:00')),
            'day key ต้องเอาจากโซนของบอท: จันทร์กรุงเทพฯ = อาทิตย์นิวยอร์ก → เปิด',
        );

        // จันทร์ 20:00 กรุงเทพฯ = จันทร์ 08:00 นิวยอร์ก → วันจันทร์ไม่มีสล็อต → วันปิด
        $this->assertSame(
            'day_closed',
            FollowUpResponseHours::closureReason((int) $bot->id, $this->at('2026-11-16 20:00:00')),
        );
    }

    #[Test]
    public function test_disabled_or_empty_or_missing_setting_adds_no_restriction(): void
    {
        // enabled = false: อาทิตย์ก็เปิด (ไม่มี day_closed) เหลือแค่หน้าต่างคงที่
        $disabled = $this->botWithHours(self::BOT26_HOURS, enabled: false);
        $this->assertNull(FollowUpResponseHours::closureReason((int) $disabled->id, $this->at('2026-11-22 10:00:00')));
        $this->assertSame('outside_hours', FollowUpResponseHours::closureReason((int) $disabled->id, $this->at('2026-11-22 23:00:00')));

        // response_hours = [] — 24/7 ตามเดิม เหลือแค่หน้าต่างคงที่
        $empty = $this->botWithHours([], enabled: true);
        $this->assertNull(FollowUpResponseHours::closureReason((int) $empty->id, $this->at('2026-11-22 10:00:00')));
        $this->assertSame('outside_hours', FollowUpResponseHours::closureReason((int) $empty->id, $this->at('2026-11-22 06:00:00')));

        // ไม่มีแถว bot_settings — ไม่มีข้อจำกัดเพิ่ม
        $noRow = $this->botWithoutSettings();
        $this->assertNull(FollowUpResponseHours::closureReason((int) $noRow->id, $this->at('2026-11-22 10:00:00')));
        $this->assertSame('outside_hours', FollowUpResponseHours::closureReason((int) $noRow->id, $this->at('2026-11-22 23:30:00')));
    }

    #[Test]
    public function test_malformed_slots_fail_closed(): void
    {
        // เวลาอ่านไม่ได้ → สล็อตนั้นปิด
        $unparseableStart = $this->botWithHours(['mon' => [['start' => 'abc', 'end' => '12:00']]]);
        $this->assertSame('outside_hours', FollowUpResponseHours::closureReason((int) $unparseableStart->id, $this->at('2026-11-16 10:00:00')));

        // ขาด end → สล็อตนั้นปิด
        $missingEnd = $this->botWithHours(['mon' => [['start' => '09:00']]]);
        $this->assertSame('outside_hours', FollowUpResponseHours::closureReason((int) $missingEnd->id, $this->at('2026-11-16 10:00:00')));

        // เวลานอกช่วง 0-23:59 → สล็อตนั้นปิด
        $outOfRange = $this->botWithHours(['mon' => [['start' => '09:00', 'end' => '25:99']]]);
        $this->assertSame('outside_hours', FollowUpResponseHours::closureReason((int) $outOfRange->id, $this->at('2026-11-16 10:00:00')));

        // สล็อตไม่ใช่ array → สล็อตนั้นปิด
        $nonArraySlot = $this->botWithHours(['mon' => ['09:00-12:00']]);
        $this->assertSame('outside_hours', FollowUpResponseHours::closureReason((int) $nonArraySlot->id, $this->at('2026-11-16 10:00:00')));

        // list สล็อตของวันไม่ใช่ array / เป็น null → วันนั้นปิดทั้งวัน (เหมือน ResponseHoursService เดิม)
        $slotListString = $this->botWithHours(['mon' => '09:00-12:00']);
        $this->assertSame('day_closed', FollowUpResponseHours::closureReason((int) $slotListString->id, $this->at('2026-11-16 10:00:00')));

        $slotListNull = $this->botWithHours(['mon' => null]);
        $this->assertSame('day_closed', FollowUpResponseHours::closureReason((int) $slotListNull->id, $this->at('2026-11-16 10:00:00')));

        // response_hours ที่ decode แล้วไม่ใช่ array → ไม่จำกัด
        // (คอลัมน์ json ของ PostgreSQL เขียน JSON เสียไม่ได้ จึงทดสอบด้วยเอกสาร JSON ที่ถูกต้องแต่รูปร่างไม่ใช่ map)
        $bot = $this->botWithHours(null);
        DB::table('bot_settings')->where('bot_id', $bot->id)->update(['response_hours' => json_encode('mon')]);
        $this->assertNull(FollowUpResponseHours::closureReason((int) $bot->id, $this->at('2026-11-16 10:00:00')), 'JSON ที่เป็น string ไม่ใช่ array = ไม่จำกัด');
        DB::table('bot_settings')->where('bot_id', $bot->id)->update(['response_hours' => 'null']);
        $this->assertNull(FollowUpResponseHours::closureReason((int) $bot->id, $this->at('2026-11-16 10:00:00')), 'JSON null = ไม่จำกัด');
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function botWithHours(?array $hours, bool $enabled = true, string $tz = 'Asia/Bangkok'): Bot
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create(['user_id' => $user->id]);

        DB::table('bot_settings')->insert([
            'bot_id' => $bot->id,
            'follow_up_enabled' => true,
            'follow_up_mode' => 'approve',
            'follow_up_started_at' => '2026-10-17 10:00:00',
            'follow_up_activation_message_id' => 0,
            'follow_up_activated_at' => '2026-10-17 10:00:00',
            'response_hours_enabled' => $enabled,
            'response_hours' => $hours === null ? null : json_encode($hours, JSON_UNESCAPED_UNICODE),
            'response_hours_timezone' => $tz,
            'created_at' => '2026-11-16 10:00:00',
            'updated_at' => '2026-11-16 10:00:00',
        ]);

        return $bot;
    }

    private function botWithoutSettings(): Bot
    {
        $user = User::factory()->owner()->create();

        return Bot::factory()->active()->line()->create(['user_id' => $user->id]);
    }

    private function at(string $localBangkok): CarbonImmutable
    {
        return CarbonImmutable::parse($localBangkok, 'Asia/Bangkok');
    }
}
