<?php

namespace Tests\Feature\FollowUp;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\FollowUpApproval;
use App\Models\FollowUpSalesSignal;
use App\Models\LeadRecoveryLog;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด B2a — ตารางเก็บข้อมูลและค่าตั้งต้นของ "ตามลูกค้า" (customer follow-up)
 *
 * สัญญาจาก plan §3.1:
 * - migration 1 เพิ่ม 5 คอลัมน์ bot_settings — ทุกบอทปิด (false/approve), คัตออฟ 3 ช่อง null,
 *   ห้ามดึงโทนเก่า lead_recovery_enabled (bot_hitl_settings) มาเปิดให้
 * - migration 2 ขยาย lead_recovery_logs (message_sent/sent_at เป็น nullable, anchor เงียบ,
 *   unique ต่อ silence, FK) และสร้าง follow_up_approvals + follow_up_sales_signals
 * - down() ลดกลับครบ และแถวเดิมต้องอ่านได้
 */
class SchemaTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS_MIGRATION_PATH = 'database/migrations/2026_11_14_210001_add_customer_follow_up_settings.php';

    private const EXPAND_MIGRATION_PATH = 'database/migrations/2026_11_14_210002_expand_lead_recovery_for_customer_follow_up.php';

    private const NEW_LOG_COLUMNS = [
        'silence_user_message_id', 'observed_last_message_id', 'customer_key',
        'sales_started_at', 'payment_step', 'transfer_summary_sent',
        'group_code', 'group_confidence', 'declined_score', 'decision_version',
        'context_redacted', 'sort_answers', 'check_answers',
        'draft', 'drafted_at', 'draft_day', 'draft_reserved_at', 'draft_model',
        'owner_action', 'owner_telegram_user_id', 'owner_action_at', 'check_overridden',
        'retry_key', 'first_push_at', 'line_request_id', 'message_id',
        'next_attempt_at', 'attempt_count', 'claimed_at', 'claim_token', 'reason_code',
        'sort_latency_ms', 'draft_latency_ms', 'check_latency_ms', 'model_cost', 'usage_totals',
        'replied_within_72h', 'response_window_closed_at',
        'ordered_within_7d', 'order_id', 'ordered_at', 'order_window_closed_at',
    ];

    private object $settingsMigration;

    private object $expandMigration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsMigration = require base_path(self::SETTINGS_MIGRATION_PATH);
        $this->expandMigration = require base_path(self::EXPAND_MIGRATION_PATH);
    }

    #[Test]
    public function test_migration_defaults_every_bot_off_and_keeps_legacy_data(): void
    {
        // เริ่มจาก DB migrate ครบ (RefreshDatabase) แล้วถอด schema ใหม่ออก เพื่อจำลอง DB ก่อนการ์ดนี้
        $this->expandMigration->down();
        $this->settingsMigration->down();
        $this->assertFalse(Schema::hasColumn('bot_settings', 'follow_up_enabled'), 'pre-condition: follow-up columns must be absent');
        $this->assertFalse(Schema::hasTable('follow_up_approvals'), 'pre-condition: approvals table must be absent');

        $owner = User::factory()->owner()->create();
        $botWithOldToggle = Bot::factory()->create(['user_id' => $owner->id]);
        $plainBot = Bot::factory()->create(['user_id' => $owner->id]);

        // แถว bot_settings สองแถว + โทนอัตโนมัติเก่า (bot_hitl_settings) เปิดค้างไว้หนึ่งบอท
        DB::table('bot_settings')->insert([
            ['bot_id' => $botWithOldToggle->id, 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00'],
            ['bot_id' => $plainBot->id, 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00'],
        ]);
        $oldToggleSettingId = (int) DB::table('bot_settings')->where('bot_id', $botWithOldToggle->id)->value('id');
        DB::table('bot_hitl_settings')->insert([
            'bot_setting_id' => $oldToggleSettingId,
            'lead_recovery_enabled' => true, // โทน "ตามลูกค้า" รุ่นเก่าเปิดอยู่ — ห้ามยกมาเปิดบอทใหม่
            'created_at' => '2026-11-01 10:00:00',
            'updated_at' => '2026-11-01 10:00:00',
        ]);

        // แถว lead_recovery_logs รูปเก่า (ส่งจริงแล้ว) หนึ่งแถว
        $conversation = Conversation::factory()->create(['bot_id' => $plainBot->id, 'channel_type' => 'line']);
        DB::table('lead_recovery_logs')->insert([
            'conversation_id' => $conversation->id,
            'bot_id' => $plainBot->id,
            'attempt_number' => 1,
            'message_mode' => 'static',
            'message_sent' => 'ข้อความเดิมก่อนการ์ดนี้',
            'sent_at' => '2026-11-01 10:00:00',
            'delivery_status' => 'sent',
            'customer_responded' => false,
            'created_at' => '2026-11-01 10:00:00',
            'updated_at' => '2026-11-01 10:00:00',
        ]);
        $legacyLogId = (int) DB::table('lead_recovery_logs')->max('id');
        $legacyBefore = (array) DB::table('lead_recovery_logs')->where('id', $legacyLogId)->first();

        try {
            $this->settingsMigration->up();
            $this->expandMigration->up();

            // ทั้งสองบอทต้องปิด โหมด approve คัตออฟ 3 ช่อง null — แม้โทนเก่าเปิดอยู่
            foreach ([$botWithOldToggle->id, $plainBot->id] as $botId) {
                $row = DB::table('bot_settings')->where('bot_id', $botId)->first();
                $this->assertFalse((bool) $row->follow_up_enabled, "bot {$botId}: follow-up must default to off");
                $this->assertSame('approve', $row->follow_up_mode, "bot {$botId}: mode must default to approve");
                $this->assertNull($row->follow_up_started_at, "bot {$botId}: started_at must be null");
                $this->assertNull($row->follow_up_activation_message_id, "bot {$botId}: activation_message_id must be null");
                $this->assertNull($row->follow_up_activated_at, "bot {$botId}: activated_at must be null");
            }
            $this->assertFalse(
                (bool) DB::table('bot_settings')->where('bot_id', $botWithOldToggle->id)->value('follow_up_enabled'),
                'old lead_recovery_enabled=true must NOT enable the new follow-up'
            );

            // แถว log เก่าต้องคงเดิมทุกคอลัมน์หลัง up
            $legacyAfter = (array) DB::table('lead_recovery_logs')->where('id', $legacyLogId)->first();
            foreach ($legacyBefore as $column => $value) {
                $this->assertSame($value, $legacyAfter[$column], "legacy column {$column} must be unchanged by up()");
            }

            // down แล้ว up ใหม่: ค่าตั้งต้นต้องเหมือนเดิม และแถวเก่าอ่านได้หลัง down
            $this->expandMigration->down();
            $this->settingsMigration->down();

            $survivor = DB::table('lead_recovery_logs')->where('id', $legacyLogId)->first();
            $this->assertNotNull($survivor, 'legacy log row must survive down()');
            $this->assertSame('ข้อความเดิมก่อนการ์ดนี้', $survivor->message_sent, 'legacy log must stay readable after down()');
            $this->assertSame('2026-11-01 10:00:00', $survivor->sent_at, 'legacy sent_at must survive down()');

            $this->settingsMigration->up();
            $this->expandMigration->up();

            foreach ([$botWithOldToggle->id, $plainBot->id] as $botId) {
                $row = DB::table('bot_settings')->where('bot_id', $botId)->first();
                $this->assertFalse((bool) $row->follow_up_enabled, "bot {$botId}: after down+up follow-up must still be off");
                $this->assertSame('approve', $row->follow_up_mode, "bot {$botId}: after down+up mode must still be approve");
                $this->assertNull($row->follow_up_started_at, "bot {$botId}: after down+up started_at must still be null");
                $this->assertNull($row->follow_up_activation_message_id, "bot {$botId}: after down+up activation_message_id must still be null");
                $this->assertNull($row->follow_up_activated_at, "bot {$botId}: after down+up activated_at must still be null");
            }
        } finally {
            // กู้สคีมากลับสู่สถานะ migrate ครบเสมอ (รันบน connection ที่ใช้ร่วมกับเทสอื่นด้วย)
            if (! Schema::hasColumn('bot_settings', 'follow_up_enabled')) {
                $this->settingsMigration->up();
            }
            if (! Schema::hasTable('follow_up_approvals')) {
                $this->expandMigration->up();
            }
        }
    }

    #[Test]
    public function test_candidate_row_saves_with_null_message_and_sent_at(): void
    {
        [$bot, $conversation, $silenceMessage] = $this->createCandidateFixture();

        $log = LeadRecoveryLog::query()->create([
            'conversation_id' => $conversation->id,
            'bot_id' => $bot->id,
            'attempt_number' => 0,
            'message_mode' => 'ai',
            'delivery_status' => 'candidate',
            'silence_user_message_id' => $silenceMessage->id,
        ]);
        $log->refresh();

        $this->assertNull($log->message_sent, 'a candidate is not a send: message_sent must stay null');
        $this->assertNull($log->sent_at, 'a candidate is not a send: sent_at must stay null');
        $this->assertFalse($log->payment_step);
        $this->assertFalse($log->transfer_summary_sent);
        $this->assertFalse($log->check_overridden);
        $this->assertSame(0, $log->attempt_count);
    }

    #[Test]
    public function test_duplicate_conversation_and_silence_anchor_rejected(): void
    {
        [$bot, $conversation, $silenceMessage] = $this->createCandidateFixture();
        $this->insertCandidateRow($conversation->id, $bot->id, ['silence_user_message_id' => $silenceMessage->id]);

        $this->expectException(QueryException::class);
        $this->insertCandidateRow($conversation->id, $bot->id, ['silence_user_message_id' => $silenceMessage->id]);
    }

    #[Test]
    public function test_two_legacy_rows_with_null_silence_are_both_accepted(): void
    {
        [$bot, $conversation] = $this->createCandidateFixture();

        foreach ([1, 2] as $attempt) {
            LeadRecoveryLog::query()->create([
                'conversation_id' => $conversation->id,
                'bot_id' => $bot->id,
                'attempt_number' => $attempt,
                'message_mode' => 'static',
                'message_sent' => "ครั้งที่ {$attempt}",
                'sent_at' => now(),
                'delivery_status' => 'sent',
            ]);
        }

        $this->assertSame(2, LeadRecoveryLog::query()->count(), 'legacy rows with null silence anchor must coexist');
    }

    #[Test]
    public function test_duplicate_retry_key_rejected(): void
    {
        [$bot, $conversation, $silenceMessage] = $this->createCandidateFixture();
        $this->insertCandidateRow($conversation->id, $bot->id, [
            'silence_user_message_id' => $silenceMessage->id,
            'retry_key' => '6d3ab4a2-1111-4c2e-9f6a-000000000001',
        ]);

        $this->expectException(QueryException::class);
        $this->insertCandidateRow($conversation->id, $bot->id, [
            'silence_user_message_id' => $silenceMessage->id,
            'retry_key' => '6d3ab4a2-1111-4c2e-9f6a-000000000001',
        ]);
    }

    #[Test]
    public function test_duplicate_sent_message_id_rejected(): void
    {
        [$bot, $conversation] = $this->createCandidateFixture();
        $sentMessage = Message::factory()->fromBot()->create(['conversation_id' => $conversation->id]);

        $this->insertCandidateRow($conversation->id, $bot->id, ['message_id' => $sentMessage->id]);

        $this->expectException(QueryException::class);
        $this->insertCandidateRow($conversation->id, $bot->id, ['message_id' => $sentMessage->id]);
    }

    #[Test]
    public function test_second_approval_for_same_log_rejected(): void
    {
        [$bot, $conversation, $silenceMessage] = $this->createCandidateFixture();
        $log = LeadRecoveryLog::query()->create([
            'conversation_id' => $conversation->id,
            'bot_id' => $bot->id,
            'attempt_number' => 0,
            'message_mode' => 'ai',
            'delivery_status' => 'candidate',
            'silence_user_message_id' => $silenceMessage->id,
        ]);
        $this->createApproval($log);

        $this->expectException(QueryException::class);
        $this->createApproval($log);
    }

    #[Test]
    public function test_deleting_conversation_cascades_logs_approvals_and_signal(): void
    {
        [$bot, $conversation, $silenceMessage] = $this->createCandidateFixture();
        $log = LeadRecoveryLog::query()->create([
            'conversation_id' => $conversation->id,
            'bot_id' => $bot->id,
            'attempt_number' => 0,
            'message_mode' => 'ai',
            'delivery_status' => 'candidate',
            'silence_user_message_id' => $silenceMessage->id,
        ]);
        $this->createApproval($log);
        FollowUpSalesSignal::query()->create([
            'conversation_id' => $conversation->id,
            'sales_started_at' => '2026-11-01 09:00:00',
            'projected_at' => '2026-11-01 10:00:00',
        ]);

        $conversation->forceDelete();

        $this->assertSame(0, LeadRecoveryLog::query()->where('conversation_id', $conversation->id)->count(), 'log must cascade with its conversation');
        $this->assertSame(0, FollowUpApproval::query()->count(), 'approval must cascade through its log');
        $this->assertSame(0, FollowUpSalesSignal::query()->where('conversation_id', $conversation->id)->count(), 'signal row must cascade with its conversation');
    }

    #[Test]
    public function test_deleting_silence_message_nulls_reference_and_keeps_log(): void
    {
        [$bot, $conversation, $silenceMessage] = $this->createCandidateFixture();
        $log = LeadRecoveryLog::query()->create([
            'conversation_id' => $conversation->id,
            'bot_id' => $bot->id,
            'attempt_number' => 0,
            'message_mode' => 'ai',
            'delivery_status' => 'candidate',
            'silence_user_message_id' => $silenceMessage->id,
        ]);

        $silenceMessage->delete();
        $log->refresh();

        $this->assertNotNull(LeadRecoveryLog::query()->find($log->id), 'log must survive its silence message');
        $this->assertNull($log->silence_user_message_id, 'deleting the anchor message must null the reference');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{0: Bot, 1: Conversation, 2: Message} */
    private function createCandidateFixture(): array
    {
        $bot = Bot::factory()->create();
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'channel_type' => 'line']);
        $silenceMessage = Message::factory()->fromUser()->create(['conversation_id' => $conversation->id]);

        return [$bot, $conversation, $silenceMessage];
    }

    private function insertCandidateRow(int $conversationId, int $botId, array $extra = []): void
    {
        DB::table('lead_recovery_logs')->insert(array_merge([
            'conversation_id' => $conversationId,
            'bot_id' => $botId,
            'attempt_number' => 0,
            'message_mode' => 'ai',
            'message_sent' => null,
            'sent_at' => null,
            'delivery_status' => 'candidate',
            'customer_responded' => false,
        ], $extra));
    }

    private function createApproval(LeadRecoveryLog $log): FollowUpApproval
    {
        return FollowUpApproval::query()->create([
            'lead_recovery_log_id' => $log->id,
            'nonce' => bin2hex(random_bytes(8)),
            'text' => 'ข้อความฉบับร่าง',
            'expires_at' => now()->addHours(12),
        ]);
    }
}
