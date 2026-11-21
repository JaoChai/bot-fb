<?php

namespace Tests\Feature\FollowUp;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\FollowUpApproval;
use App\Models\LeadRecoveryLog;
use App\Models\User;
use App\Services\FollowUp\FollowUpAutomaticRule;
use App\Services\FollowUp\FollowUpSalesProjector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด B1 — กฎ A1 ตัดสิน "ข้อความไหนส่งเองได้" (FollowUpAutomaticRule::reason)
 *
 * สัญญา (spec A1 + คำสั่ง Lead A14-A15):
 * - null = ทุกเงื่อนไขผ่าน; ไม่งั้นคืนโค้ดเสถียรตัวแรกที่ตก ตามลำดับ:
 *   auto_switch_off, auto_mode_approve, auto_not_allowed, auto_group, auto_confidence,
 *   auto_declined, auto_check, auto_revision, auto_eligibility
 * - ไม่มีเงื่อนไขโควต้า (A15) และไม่มี caller — คลาสนี้อ่าน database + config เท่านั้น
 * - คะแนนต้องเป็นตัวเลขจริงในช่วง [0,1]: null, boolean, string ไม่เป็นเลข, NaN, INF,
 *   นอกช่วง = fail closed ทั้งหมด (ห้ามแปลง null เป็น 0)
 * - ขาดบอท / ขาด bot_settings / ขาดบัตรอนุมัติ = fail closed ด้วยโค้ดที่ตรงข้อนั้น
 */
class AutomaticRuleTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 10:00:00'; // จันทร์ 16 พ.ย. 2026 กรุงเทพฯ อยู่ในชั่วโมงส่ง

    private const DRAFT = 'สวัสดีครับ ยังสนใจสินค้าตัวเดิมอยู่ไหมครับ';

    private const RECIPIENT = 'Utest00000000000000000000000001';

    /** ห้องล่าสุดที่ seed — ใช้โดย mutation เพิ่มข้อความบอท */
    private int $roomId = 0;

    #[Test]
    public function test_auto_rule_baseline_passes(): void
    {
        [$logId, $approvalId] = $this->makeReady();

        $this->assertNull($this->rule()->reason($this->logRow($logId), $this->approvalRow($approvalId), $this->now()));

        // ซ้ำด้วยกลุ่ม G3 — ผลลัพธ์ต้องเป็น null เหมือนกัน
        [$g3LogId, $g3ApprovalId] = $this->makeReady(['group_code' => 'G3']);

        $this->assertNull($this->rule()->reason($this->logRow($g3LogId), $this->approvalRow($g3ApprovalId), $this->now()));
    }

    /** @return iterable<string, array{0: string, 1: ?string}> */
    public static function singleFailureProvider(): iterable
    {
        yield 'switch off' => ['switch_off', 'auto_switch_off'];
        yield 'mode approve' => ['mode_approve', 'auto_mode_approve'];
        yield 'host list without the bot' => ['host_absent', 'auto_not_allowed'];
        yield 'started 13d 23:59:59 earlier' => ['started_13d_235959', 'auto_not_allowed'];
        yield 'started exactly 14d passes' => ['started_14d_exact', null];
        yield 'G1' => ['group_g1', 'auto_group'];
        yield 'G4' => ['group_g4', 'auto_group'];
        yield 'confidence 0.7999' => ['confidence_07999', 'auto_confidence'];
        yield 'declined 0.50' => ['declined_050', 'auto_declined'];
        yield 'failed check item' => ['check_failed_item', 'auto_check'];
        yield 'error true' => ['check_error_true', 'auto_check'];
        yield 'eligibility failure (new bot message)' => ['eligibility_new_bot_message', 'auto_eligibility'];
        yield 'time 07:59:59' => ['time_075959', 'auto_eligibility'];
        yield 'time 22:00:00' => ['time_220000', 'auto_eligibility'];
        yield 'time 08:00:00 passes' => ['time_080000', null];
        yield 'time 21:59:59 passes' => ['time_215959', null];
    }

    #[Test]
    #[DataProvider('singleFailureProvider')]
    public function test_auto_rule_each_single_failure(string $mutation, ?string $expected): void
    {
        [$logId, $approvalId, $botId] = $this->makeReady();

        $now = match ($mutation) {
            'time_075959' => $this->at('2026-11-16 07:59:59'),
            'time_220000' => $this->at('2026-11-16 22:00:00'),
            'time_080000' => $this->at('2026-11-16 08:00:00'),
            'time_215959' => $this->at('2026-11-16 21:59:59'),
            default => $this->now(),
        };

        match ($mutation) {
            'switch_off' => $this->setSettings($botId, ['follow_up_enabled' => false]),
            'mode_approve' => $this->setSettings($botId, ['follow_up_mode' => 'approve']),
            'host_absent' => config(['customer_follow_up.automatic_bot_ids' => [$botId + 1]]),
            'started_13d_235959' => $this->setSettings($botId, ['follow_up_started_at' => '2026-11-02 10:00:01']),
            'started_14d_exact' => $this->setSettings($botId, ['follow_up_started_at' => '2026-11-02 10:00:00']),
            'group_g1' => DB::table('lead_recovery_logs')->where('id', $logId)->update(['group_code' => 'G1']),
            'group_g4' => DB::table('lead_recovery_logs')->where('id', $logId)->update(['group_code' => 'G4']),
            'confidence_07999' => DB::table('lead_recovery_logs')->where('id', $logId)->update(['group_confidence' => 0.7999]),
            'declined_050' => DB::table('lead_recovery_logs')->where('id', $logId)->update(['declined_score' => 0.5]),
            'check_failed_item' => $this->setApproval($approvalId, ['check_answers' => $this->flaggedCheck()]),
            'check_error_true' => $this->setApproval($approvalId, ['check_answers' => $this->errorCheck()]),
            'eligibility_new_bot_message' => $this->addMessage($this->roomId, 'bot', '2026-11-15 09:00:00', 'เตรียมสินค้าเสร็จแล้วครับ'),
            default => null,
        };

        $result = $this->rule()->reason($this->logRow($logId), $this->approvalRow($approvalId), $now);

        $this->assertSame($expected, $result);
    }

    /** @return iterable<string, array{0: string, 1: mixed, 2: ?string}> */
    public static function badScoreProvider(): iterable
    {
        // ทุกค่าที่ไม่ใช่ตัวเลขจริงใน [0,1] ต้อง fail closed — null ห้ามกลายเป็น 0
        foreach ([
            'null' => null,
            'boolean true' => true,
            'boolean false' => false,
            'non numeric string' => 'abc',
            'NaN' => NAN,
            'INF' => INF,
            'below zero' => -0.01,
            'above one' => 1.01,
        ] as $label => $bad) {
            yield "group_confidence {$label}" => ['group_confidence', $bad, 'auto_confidence'];
            yield "declined_score {$label}" => ['declined_score', $bad, 'auto_declined'];
        }

        // string ตัวเลข (รูปที่ PostgreSQL คืนสำหรับคอลัมน์ decimal) ต้องยังผ่าน
        yield 'group_confidence numeric string passes' => ['group_confidence', '0.8000', null];
        yield 'declined_score numeric string passes' => ['declined_score', '0.4900', null];
    }

    #[Test]
    #[DataProvider('badScoreProvider')]
    public function test_auto_rule_bad_numeric_values(string $column, mixed $bad, ?string $expected): void
    {
        [$logId, $approvalId] = $this->makeReady();
        $log = $this->logRow($logId);

        // ฝังค่าดิบ (ไม่ผ่าน cast decimal ของ Eloquent) เพื่อจำลองแถวที่เก็บค่าไม่สะอาด
        $log->setRawAttributes(array_merge($log->getRawOriginal(), [$column => $bad]), true);

        $this->assertSame($expected, $this->rule()->reason($log, $this->approvalRow($approvalId), $this->now()));
    }

    #[Test]
    public function test_auto_rule_bad_checks_or_revision(): void
    {
        [$logId, $approvalId, $botId] = $this->makeReady();
        $rule = $this->rule();
        $now = $this->now();

        // ฝัง check_answers รูปดิบที่ไม่สะอาดทีละแบบ → auto_check ทั้งหมด
        $badChecks = [
            'missing check_answers' => null,
            'invalid json' => 'not-json{',
            'failed is object' => '{"failed":{},"error":false}',
            'error 0' => '{"failed":[],"error":0}',
            'error null' => '{"failed":[],"error":null}',
            'error string false' => '{"failed":[],"error":"false"}',
        ];
        foreach ($badChecks as $label => $raw) {
            $log = $this->logRow($logId);
            $approval = $this->approvalRow($approvalId);
            $approval->setRawAttributes(array_merge($approval->getRawOriginal(), ['check_answers' => $raw]), true);

            $this->assertSame('auto_check', $rule->reason($log, $approval, $now), $label);
        }

        // บัตรขาดหาย → fail closed ที่เงื่อนไขแรกของบัตร = auto_check
        $this->assertSame('auto_check', $rule->reason($this->logRow($logId), null, $now), 'missing approval');

        // revision 2 / ข้อความไม่ตรงร่าง / ยอมรับข้อห้ามแล้ว → auto_revision
        $revisionCases = [
            'revision 2' => ['revision' => 2],
            'text differs from draft' => ['text' => 'ข้อความที่ถูกแก้ไขแล้ว'],
            'check override true' => ['check_overridden' => true],
        ];
        foreach ($revisionCases as $label => $raw) {
            $log = $this->logRow($logId);
            $approval = $this->approvalRow($approvalId);
            $approval->setRawAttributes(array_merge($approval->getRawOriginal(), $raw), true);

            $this->assertSame('auto_revision', $rule->reason($log, $approval, $now), $label);
        }

        // บอทขาดหาย (bot_id ชี้ไม่ถึงแถวไหน) → auto_switch_off
        $log = $this->logRow($logId);
        $log->setRawAttributes(array_merge($log->getRawOriginal(), ['bot_id' => $botId + 999]), true);
        $this->assertSame('auto_switch_off', $rule->reason($log, $this->approvalRow($approvalId), $now), 'missing bot');

        // ขาด bot_settings → auto_switch_off
        DB::table('bot_settings')->where('bot_id', $botId)->delete();
        $this->assertSame('auto_switch_off', $rule->reason($this->logRow($logId), $this->approvalRow($approvalId), $now), 'missing settings');
    }

    /** บัตรรูป object ล้วน (แถวจาก query builder — check_answers เป็น JSON string ดิบ) ต้องตัดสินได้เหมือนโมเดล */
    #[Test]
    public function test_auto_rule_accepts_plain_object_approval(): void
    {
        [$logId, $approvalId] = $this->makeReady();
        $log = $this->logRow($logId);

        $plain = (object) DB::table('follow_up_approvals')->where('id', $approvalId)->first();

        $this->assertNull($this->rule()->reason($log, $plain, $this->now()), 'plain clean approval passes');

        $flagged = (object) array_merge((array) $plain, ['check_answers' => '{"failed":["no_pressure"],"error":false}']);

        $this->assertSame('auto_check', $this->rule()->reason($log, $flagged, $this->now()), 'plain flagged approval');
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * คู่พร้อมส่งเอง: บอทอยู่ใน host list + เปิดมา 30 วัน, mode automatic,
     * ห้องผ่าน gate ครบ, log G2 + confidence 0.80 + declined 0.49,
     * บัตร revision 1 ข้อความตรงร่าง check สะอาด ไม่ override
     *
     * @return array{0: int, 1: int, 2: int} [logId, approvalId, botId]
     */
    private function makeReady(array $logOverrides = [], array $approvalOverrides = []): array
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-6-luna',
        ]);

        config(['customer_follow_up.bot_ids' => [$bot->id]]);
        config(['customer_follow_up.automatic_bot_ids' => [$bot->id]]);
        config(['customer_follow_up.admin_web_url' => 'https://admin.example.test']);
        config(['services.telegram_alert.secret' => 'webhook-secret-test']);

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

        DB::table('bot_settings')->insert([
            'bot_id' => $bot->id,
            'follow_up_enabled' => true,
            'follow_up_mode' => 'automatic',
            'follow_up_started_at' => '2026-10-17 10:00:00', // 30 วันก่อน NOW — ครบ 14 วัน
            'follow_up_activation_message_id' => 0,
            'follow_up_activated_at' => '2026-10-17 10:00:00',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        $room = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => self::RECIPIENT,
            'last_message_at' => '2026-11-15 07:29:00', // เวลาข้อความ user ล่าสุดที่ seed — คงที่เสมอ ไม่สุ่มตามเครื่อง
        ]);
        $this->roomId = $room->id;

        $this->addMessage($room->id, 'user', '2026-11-15 07:25:00', 'สนใจสินค้าครับ');
        $this->addMessage($room->id, 'bot', '2026-11-15 07:26:00', 'ต้องการกี่ตัวครับ');
        $this->addMessage($room->id, 'user', '2026-11-15 07:27:00', 'เอา 2 ตัวครับ');
        $this->addMessage($room->id, 'bot', '2026-11-15 07:28:00', 'ได้ครับ กำลังเตรียมสินค้าให้ครับ');
        $anchorId = $this->addMessage($room->id, 'user', '2026-11-15 07:29:00', 'ประเมินราคาให้หน่อยครับ');
        // anchor เงียบ > 24 ชม. ตั้งแต่ 07:29 ของวันถัดไป เพื่อให้เคสเวลา 08:00:00 (ขอบเปิดชั่วโมงส่ง) ผ่าน gate เงียบได้

        FollowUpSalesProjector::refresh($room, $this->now());

        $logId = DB::table('lead_recovery_logs')->insertGetId(array_merge([
            'conversation_id' => $room->id,
            'bot_id' => $room->bot_id,
            'attempt_number' => 0,
            'attempt_count' => 0,
            'message_mode' => 'ai',
            'delivery_status' => 'approved',
            'silence_user_message_id' => $anchorId,
            'observed_last_message_id' => $anchorId,
            'customer_key' => hash('sha256', $room->bot_id.'|line|'.$room->external_customer_id),
            'group_code' => 'G2',
            'group_confidence' => 0.8,
            'declined_score' => 0.49,
            'draft' => self::DRAFT,
            'drafted_at' => '2026-11-15 09:00:00',
            'draft_day' => '2026-11-15',
            'draft_model' => 'openai/gpt-6-luna',
            'check_answers' => $this->cleanCheck(),
            'check_overridden' => false,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ], $logOverrides));

        $approvalId = DB::table('follow_up_approvals')->insertGetId(array_merge([
            'lead_recovery_log_id' => $logId,
            'status' => 'approved',
            'revision' => 1,
            'nonce' => 'abcd1234abcd1234',
            'text' => self::DRAFT,
            'check_answers' => $this->cleanCheck(),
            'check_overridden' => false,
            'expires_at' => '2026-11-16 12:00:00',
            'telegram_delivery_state' => 'new',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ], $approvalOverrides));

        return [$logId, $approvalId, $bot->id];
    }

    private function addMessage(int $roomId, string $sender, string $at, string $content): int
    {
        return DB::table('messages')->insertGetId([
            'conversation_id' => $roomId,
            'sender' => $sender,
            'content' => $content,
            'type' => 'text',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function rule(): FollowUpAutomaticRule
    {
        return new FollowUpAutomaticRule;
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW, 'Asia/Bangkok');
    }

    private function at(string $localBangkok): CarbonImmutable
    {
        return CarbonImmutable::parse($localBangkok, 'Asia/Bangkok');
    }

    private function logRow(int $logId): LeadRecoveryLog
    {
        return LeadRecoveryLog::query()->findOrFail($logId);
    }

    private function approvalRow(int $approvalId): FollowUpApproval
    {
        return FollowUpApproval::query()->findOrFail($approvalId);
    }

    private function setSettings(int $botId, array $values): void
    {
        DB::table('bot_settings')->where('bot_id', $botId)->update($values);
    }

    private function setApproval(int $approvalId, array $values): void
    {
        DB::table('follow_up_approvals')->where('id', $approvalId)->update($values);
    }

    private function cleanCheck(): string
    {
        return json_encode(['scores' => ['safe_to_send' => 0.9], 'failed' => [], 'error' => false, 'version' => 1], JSON_UNESCAPED_UNICODE);
    }

    private function flaggedCheck(): string
    {
        return json_encode(['scores' => ['safe_to_send' => 0.9], 'failed' => ['no_pressure'], 'error' => false, 'version' => 1], JSON_UNESCAPED_UNICODE);
    }

    private function errorCheck(): string
    {
        return json_encode(['scores' => ['safe_to_send' => 0.9], 'failed' => [], 'error' => true, 'version' => 1], JSON_UNESCAPED_UNICODE);
    }
}
