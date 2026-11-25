<?php

namespace Tests\Feature\FollowUp;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\LeadRecoveryLog;
use App\Models\User;
use App\Services\FollowUp\FollowUpPipeline;
use App\Services\SupportRouter\LunaPayloadScrubber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * อนุมัติเจ้านาย 25 พ.ย.: เส้นทาง follow-up ต้องผ่าน LunaPayloadScrubber กับ
 * ข้อความลูกค้า (บรรทัด rule ระดับบรรทัดใช้กับข้อความลูกค้าเท่านั้น บอทเหลือ
 * value rules) ก่อนสร้าง request ถึง Luna Decisions — ปิดช่องประเภทที่
 * FollowUpAccountDataDetector ไม่จับ (EAA token, base32 ล้วน, โค้ด/OTP หลังคำว่า
 * โค้ด, cookie datr/fr) โดยไม่เปลี่ยน: สิ่งที่เก็บ DB, ข้อความถึงลูกค้า,
 * ข้อความ Telegram/การ์ด, ถ้อยคำคำถาม, เกณฑ์ตัดสินใจ
 *
 * fail-safe: ตัวล้างพัง = contextLines คืน null = แถวถูก exclude ก่อนเรียก
 * Luna เหมือนเส้นทาง account-data เดิม (fail closed) — ห้ามส่งอัตโนมัติด้วยข้อความดิบ
 *
 * IRON RULE: credential ทุกตัวในไฟล์นี้เป็นสตริงสังเคราะห์ (FAKE-...) เท่านั้น
 */
class FollowUpContextScrubTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_customer_token_types_the_redactor_misses_are_masked_before_context(): void
    {
        $bot = $this->seedBot();
        $room = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'USCRUB1']);
        $this->addMessage($room->id, 'user', '2026-11-20 10:00:00', 'token ของผม EAAFAKE12345678 ครับ');
        $this->addMessage($room->id, 'user', '2026-11-20 10:01:00', 'JBSWY3DPEHPK3PXPJBSWY3DP');
        $this->addMessage($room->id, 'user', '2026-11-20 10:02:00', 'โค้ดยืนยัน 123456 ครับ');
        $this->addMessage($room->id, 'user', '2026-11-20 10:03:00', 'บัญชี 12345 ครับ');
        $log = $this->candidateRow($room->id, $bot->id, '2026-11-19 00:00:00', 4);

        $lines = app(FollowUpPipeline::class)->contextLines($log);

        $joined = implode("\n", (array) $lines);
        $this->assertStringNotContainsString('EAAFAKE12345678', $joined);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXPJBSWY3DP', $joined);
        $this->assertStringNotContainsString('123456', $joined);
        $this->assertStringNotContainsString('12345', $joined);
    }

    #[Test]
    public function test_bot_history_lines_keep_value_rules_without_line_nuking(): void
    {
        $bot = $this->seedBot([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        $room = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'USCRUB2']);
        $this->addMessage($room->id, 'bot', '2026-11-20 10:00:00', 'token ตัวอย่าง EAAFAKE12345678 ครับ');
        $log = $this->candidateRow($room->id, $bot->id, '2026-11-19 00:00:00', 1);

        $lines = app(FollowUpPipeline::class)->contextLines($log);

        $joined = implode("\n", (array) $lines);
        $this->assertStringNotContainsString('EAAFAKE12345678', $joined);
    }

    #[Test]
    public function test_scrubber_failure_returns_null_and_never_throws(): void
    {
        $bot = $this->seedBot();
        $room = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'USCRUB3']);
        // ชนิดที่ FollowUpRedactor/detector ไม่จับ (EAA) — ถ้าไม่มี pre-scrub บรรทัดนี้
        // จะหลุดไป Luna ตรงๆ และเทสต์นี้จะล้ม ตอนตัวล้างพังจะต้องได้ null (fail-closed)
        $this->addMessage($room->id, 'user', '2026-11-20 10:00:00', 'token ของผม EAAFAKE12345678 ครับ');
        $log = $this->candidateRow($room->id, $bot->id, '2026-11-19 00:00:00', 1);

        $this->mock(LunaPayloadScrubber::class, function ($mock) {
            $mock->shouldReceive('scrub')->andThrow(new RuntimeException('scrub boom'));
        });

        $lines = app(FollowUpPipeline::class)->contextLines($log);

        // fail-closed เดิมของเส้นทางนี้: null = แถวถูก exclude ก่อนเรียก Luna — ไม่มีการส่งอัตโนมัติ
        $this->assertNull($lines);
    }

    #[Test]
    public function test_credential_free_context_lines_are_byte_identical(): void
    {
        $bot = $this->seedBot();
        $room = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'USCRUB4']);
        $this->addMessage($room->id, 'user', '2026-11-20 10:00:00', 'สวัสดีครับ ราคา 1,600 สนใจครับ');
        $this->addMessage($room->id, 'user', '2026-11-20 10:01:00', 'โอน 1500 มาแล้วนะครับ ปริมาณ 40 อัน');
        $this->addMessage($room->id, 'bot', '2026-11-20 10:02:00', 'ได้รับแล้วครับ ส่งของให้ใน 5-10 นาทีนะครับ');
        $log = $this->candidateRow($room->id, $bot->id, '2026-11-19 00:00:00', 3);

        $lines = app(FollowUpPipeline::class)->contextLines($log);

        $this->assertSame($this->expectedCleanLines(), $lines);
    }

    /**
     * fixture ไบต์ตายตัวของบริบทปลอด credential — freeze จากพฤติกรรมปัจจุบัน
     * (ใช้กันอนาคต: อะไรก็ตามที่เปลี่ยน output แม้ไบต์เดียวต้องล้มเทสต์นี้)
     *
     * @return list<string>
     */
    private function expectedCleanLines(): array
    {
        return [
            'C: สวัสดีครับ ราคา 1,600 สนใจครับ',
            'C: โอน 1500 มาแล้วนะครับ ปริมาณ 40 อัน',
            'B: ได้รับแล้วครับ ส่งของให้ใน 5-10 นาทีนะครับ',
        ];
    }

    private function seedBot(array $botAttributes = []): Bot
    {
        $user = User::factory()->create();

        return Bot::factory()->create(['user_id' => $user->id, ...$botAttributes]);
    }

    private function addMessage(int $conversationId, string $sender, string $at, string $content): int
    {
        return (int) DB::table('messages')->insertGetId([
            'conversation_id' => $conversationId,
            'sender' => $sender,
            'content' => $content,
            'type' => 'text',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function candidateRow(int $roomId, int $botId, string $salesStartedAt, int $lastMsgId): LeadRecoveryLog
    {
        $id = (int) DB::table('lead_recovery_logs')->insertGetId([
            'conversation_id' => $roomId,
            'bot_id' => $botId,
            'attempt_number' => 0,
            'message_mode' => 'ai',
            'message_sent' => null,
            'sent_at' => null,
            'delivery_status' => 'candidate',
            'customer_responded' => false,
            'observed_last_message_id' => $lastMsgId,
            'sales_started_at' => $salesStartedAt,
            'created_at' => '2026-11-20 10:05:00',
            'updated_at' => '2026-11-20 10:05:00',
        ]);

        $log = new LeadRecoveryLog;
        $log->id = $id;
        $log->conversation_id = $roomId;
        $log->observed_last_message_id = $lastMsgId;
        $log->setRelations([]);

        return $log;
    }
}
