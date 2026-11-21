<?php

namespace Tests\Feature\FollowUp;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Http\Resources\MessageResource;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\User;
use App\Services\FollowUp\FollowUpQuotaService;
use App\Services\FollowUp\FollowUpSalesProjector;
use App\Services\FollowUp\FollowUpSendService;
use App\Services\FollowUp\FollowUpTelegramService;
use App\Services\LINEService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * การ์ด B4c — ตัวส่งข้อความตามลูกค้า (D46-D52): FollowUpSendService::send
 *
 * - Step A อ่านอย่างเดียว เขียนไม่ได้ ยิง HTTP ไม่ได้
 * - claim แบบ compare-and-set รับเฉพาะ approved/retry(ถึงคิว)/sending(ค้าง 10 นาที)
 * - gate ตามลำดับ expiry → recheck(forSend) → ผู้รับ → ข้อความ(ยังไม่ยิง) → โควต้า → ข้อความใหม่
 * - freeze ก่อน POST เสมอ (first_push_at/message_sent/attempt_number) แล้ว pushFollowUp นอก transaction
 * - payload แช่งตาย ห้ามอ่าน approval.text ซ้ำหลังยิงครั้งแรก (D49); key เดิมรีไลน์ 24 ชม.
 * - ทุก HTTP โดน fake — ไม่มี request จริง
 */
class SendTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 10:00:00'; // จันทร์ 16 พ.ย. 2026 กรุงเทพฯ อยู่ในชั่วโมงส่ง

    private const QUOTA_URL = 'https://api.line.me/v2/bot/message/quota';

    private const CONSUMPTION_URL = 'https://api.line.me/v2/bot/message/quota/consumption';

    private const PUSH_URL = 'https://api.line.me/v2/bot/message/push';

    private const TEXT = 'สวัสดีครับ ยังสนใจสินค้าอยู่ไหมครับ 🙏';

    private const RECIPIENT = 'Ua1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const REQUEST_ID = 'req-accept-1';

    /** @var array<string, int> */
    private array $hits = [];

    /** @var list<array{0: string, 1: string}> คู่ [X-Line-Retry-Key, body] ของทุก push */
    private array $pushes = [];

    /** @var list<MessageLogged> */
    private array $logLines = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logLines[] = $event;
        });
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW, 'Asia/Bangkok');
    }

    private function at(string $localBangkok): CarbonImmutable
    {
        return CarbonImmutable::parse($localBangkok, 'Asia/Bangkok');
    }

    private function token(): string
    {
        return implode('', ['fake', 'Line', 'Access', 'Token', '777']);
    }

    private function retryKey(): string
    {
        return (string) Str::uuid();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * ห้องสะอาดผ่านทุก gate: m1-m5 จบที่ข้อความลูกค้า 08:59 เงียบ > 24 ชม.,
     * log approved + บัตร approved (revision 1, ข้อความตรงร่าง, check สะอาด, ไม่หมดอายุ)
     */
    private function seedReady(array $logOverrides = [], array $approvalOverrides = []): array
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-6-luna',
            'channel_access_token' => $this->token(),
        ]);

        config(['customer_follow_up.bot_ids' => [$bot->id]]);
        config(['customer_follow_up.admin_web_url' => 'https://admin.example.test']);
        config(['services.telegram_alert.secret' => 'webhook-secret-test']);
        config(['customer_follow_up.quota_reserve_messages' => 100]);

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
            'follow_up_mode' => 'approve',
            'follow_up_started_at' => '2026-10-17 10:00:00',
            'follow_up_activation_message_id' => 0,
            'follow_up_activated_at' => '2026-10-17 10:00:00',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        $room = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => self::RECIPIENT,
            'last_message_at' => '2026-11-15 08:59:00', // เวลาของข้อความ user ล่าสุดที่ seed — คงที่เสมอ ไม่สุ่มตามเครื่อง
        ]);

        $this->addMessage($room->id, 'user', '2026-11-15 08:55:00', 'สนใจ Nolimit Personal ครับ');
        $this->addMessage($room->id, 'bot', '2026-11-15 08:56:00', 'ต้องการกี่ตัวครับ');
        $this->addMessage($room->id, 'user', '2026-11-15 08:57:00', 'เอา 2 ตัวครับ');
        $this->addMessage($room->id, 'bot', '2026-11-15 08:58:00', 'ได้ครับ กำลังเตรียมสินค้าให้ครับ');
        $anchorId = $this->addMessage($room->id, 'user', '2026-11-15 08:59:00', 'ประเมินราคาให้หน่อยครับ');

        FollowUpSalesProjector::refresh($room, $this->now());

        $logId = $this->insertLog($room, $anchorId, $logOverrides);
        $approvalId = $this->insertApproval($logId, $approvalOverrides);

        return ['bot' => $bot, 'room' => $room, 'anchorId' => $anchorId, 'logId' => $logId, 'approvalId' => $approvalId];
    }

    private function insertLog(Conversation $room, int $anchorId, array $overrides = []): int
    {
        return DB::table('lead_recovery_logs')->insertGetId(array_merge([
            'conversation_id' => $room->id,
            'bot_id' => $room->bot_id,
            'attempt_number' => 0,
            'attempt_count' => 0,
            'message_mode' => 'ai',
            'delivery_status' => 'approved',
            'silence_user_message_id' => $anchorId,
            'observed_last_message_id' => $anchorId,
            'customer_key' => hash('sha256', $room->bot_id.'|line|'.$room->external_customer_id),
            'draft' => self::TEXT,
            'drafted_at' => '2026-11-15 09:00:00',
            'draft_day' => '2026-11-15',
            'draft_model' => 'openai/gpt-6-luna',
            'check_answers' => $this->cleanCheck(),
            'owner_action' => 'sent',
            'owner_action_at' => '2026-11-16 09:55:00',
            'owner_telegram_user_id' => '555',
            'check_overridden' => false,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ], $overrides));
    }

    private function insertApproval(int $logId, array $overrides = []): int
    {
        return DB::table('follow_up_approvals')->insertGetId(array_merge([
            'lead_recovery_log_id' => $logId,
            'status' => 'approved',
            'revision' => 1,
            'nonce' => 'abcd1234abcd1234',
            'text' => self::TEXT,
            'check_answers' => $this->cleanCheck(),
            'check_overridden' => false,
            'expires_at' => '2026-11-16 12:00:00',
            'telegram_delivery_state' => 'sent',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ], $overrides));
    }

    /**
     * แถว automatic ที่ผ่าน A1 ครบทุกข้อ: mode automatic, อยู่ใน host list, อายุ 30 วัน,
     * G3 มั่นใจ 0.90 / declined 0.10, เช็คสะอาด, revision 1 ข้อความตรงร่าง, state new
     */
    private function seedAutomaticReady(array $logOverrides = [], array $approvalOverrides = []): array
    {
        $seed = $this->seedReady(array_merge([
            'owner_action' => 'automatic',
            'owner_action_at' => '2026-11-16 09:55:00',
            'owner_telegram_user_id' => null,
            'group_code' => 'G3',
            'group_confidence' => 0.9,
            'declined_score' => 0.1,
        ], $logOverrides), array_merge([
            'telegram_delivery_state' => 'new',
        ], $approvalOverrides));

        config(['customer_follow_up.automatic_bot_ids' => [$seed['bot']->id]]);
        DB::table('bot_settings')->where('bot_id', $seed['bot']->id)->update(['follow_up_mode' => 'automatic']);

        return $seed;
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

    // ------------------------------------------------------------------
    // HTTP fakes
    // ------------------------------------------------------------------

    /**
     * จำลอง LINE ครบสาม endpoint พร้อมนับ hit ทุก url
     * $quota: [quota => array|'500'|'timeout', consumption => array|'500'|'timeout']
     * $push: รายการคำตอบเรียงตามจังหวะ [body, status, headers] หรือ 'timeout' (โยน ConnectionException)
     */
    private function fakeLine(array $quota = [], array $push = []): void
    {
        $quota = array_merge([
            self::QUOTA_URL => ['type' => 'limited', 'value' => 200],
            self::CONSUMPTION_URL => ['totalUsage' => 10],
        ], $quota);

        $push = $push === [] ? [['{}', 200, ['x-line-request-id' => self::REQUEST_ID]]] : $push;
        $pushIndex = 0;
        $quotaHits = [];
        $this->hits = [];
        $this->pushes = [];

        // Http::fake สะสม stub ถาวรภายใน test เดียว (stub แรกชนะเสมอ) — เรียกได้ครั้งเดียวต่อ test
        Http::fake(function ($request) use ($quota, $push, &$pushIndex, &$quotaHits) {
            $url = $request->url();
            $this->hits[$url] = ($this->hits[$url] ?? 0) + 1;

            if ($url === self::QUOTA_URL || $url === self::CONSUMPTION_URL) {
                $answer = $quota[$url];

                if (is_array($answer) && array_is_list($answer)) {
                    $hit = $quotaHits[$url] ?? 0;
                    $quotaHits[$url] = $hit + 1;
                    $answer = $answer[min($hit, count($answer) - 1)];
                }

                if ($answer === 'timeout') {
                    throw new ConnectionException('cURL error 28');
                }

                if ($answer === '500') {
                    return Http::response('{"message":"Internal server error"}', 500);
                }

                return Http::response(json_encode($answer, JSON_UNESCAPED_UNICODE), 200);
            }

            if ($url === self::PUSH_URL) {
                $entry = $push[min($pushIndex, count($push) - 1)];
                $pushIndex++;
                $this->pushes[] = [$request->hasHeader('X-Line-Retry-Key') ? $request->header('X-Line-Retry-Key')[0] : '', $request->body()];

                if ($entry === 'timeout') {
                    throw new ConnectionException('cURL error 28');
                }

                return Http::response($entry[0], $entry[1], $entry[2] ?? []);
            }

            return Http::response('', 404);
        });
    }

    /** กับดัก request หลุด: อะไรยิงออกนอกแผน = ConnectionException */
    private function fakeNothing(): void
    {
        Http::fake();
        Http::preventStrayRequests();
    }

    private function send(array $seed, ?string $at = null): string
    {
        return app(FollowUpSendService::class)->send($seed['approvalId'], $at === null ? $this->now() : $this->at($at));
    }

    private function snapshot(array $seed): array
    {
        return [
            'log' => (array) DB::table('lead_recovery_logs')->where('id', $seed['logId'])->first(),
            'approval' => (array) DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->first(),
        ];
    }

    private function logRow(array $seed): object
    {
        return DB::table('lead_recovery_logs')->where('id', $seed['logId'])->first();
    }

    private function approvalRow(array $seed): object
    {
        return DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->first();
    }

    /** แถวประวัติของการส่งนี้ — ชี้ผ่าน message_id ของ log เท่านั้น (fixture มีข้อความบอทของตัวเองอยู่แล้ว) */
    private function followUpMessage(array $seed): ?object
    {
        $messageId = $this->logRow($seed)->message_id;

        return $messageId === null ? null : DB::table('messages')->where('id', (int) $messageId)->first();
    }

    private function assertPushCount(int $expected): void
    {
        $this->assertSame($expected, $this->hits[self::PUSH_URL] ?? 0, "จำนวน request push ต้องเท่ากับ {$expected}");
    }

    private function assertFreezeUntouched(array $seed): void
    {
        $log = $this->logRow($seed);
        $this->assertNull($log->first_push_at, 'first_push_at ต้องยังว่าง');
        $this->assertNull($log->message_sent, 'message_sent ต้องยังว่าง');
        $this->assertSame(0, (int) $log->attempt_number, 'attempt_number ต้องยังเป็น 0');
    }

    private function assertClaimCleared(array $seed): void
    {
        $log = $this->logRow($seed);
        $this->assertNull($log->claim_token, 'claim_token ต้องถูกเคลียร์');
        $this->assertNull($log->claimed_at, 'claimed_at ต้องถูกเคลียร์');
    }

    private function assertNoMessagesAdded(array $seed, int $expectedCount): void
    {
        $this->assertSame($expectedCount, (int) DB::table('messages')->where('conversation_id', $seed['room']->id)->count());
    }

    // ------------------------------------------------------------------
    // Baseline
    // ------------------------------------------------------------------

    #[Test]
    public function test_baseline_sends_exactly_one_push_with_exact_body_and_quota_reads(): void
    {
        $seed = $this->seedReady();
        $this->fakeLine();

        $this->assertSame('sent', $this->send($seed));

        $this->assertSame(1, $this->hits[self::QUOTA_URL] ?? 0, 'อ่านโควต้าครั้งเดียว');
        $this->assertSame(1, $this->hits[self::CONSUMPTION_URL] ?? 0, 'อ่านยอดใช้ครั้งเดียว');
        $this->assertPushCount(1);
        // อ่านโควต้าเสร็จก่อนยิง push เสมอ (hits เรียงตามเวลาที่ fake เห็น)
        $this->assertSame([self::QUOTA_URL, self::CONSUMPTION_URL, self::PUSH_URL], array_keys($this->hits));

        $this->assertCount(1, $this->pushes);
        $this->assertSame(['to' => self::RECIPIENT, 'messages' => [['type' => 'text', 'text' => self::TEXT]]], json_decode($this->pushes[0][1], true), 'body ต้องตรงทุกตัวอักษร ไทย+อิโมจิไม่เพี้ยน');

        $log = $this->logRow($seed);
        $this->assertSame('sent', $log->delivery_status);
        $this->assertSame(self::TEXT, $log->message_sent);
        $this->assertSame('2026-11-16 10:00:00', $log->first_push_at);
        $this->assertSame(1, (int) $log->attempt_number);
        $this->assertClaimCleared($seed);
    }

    // ------------------------------------------------------------------
    // Step A — อ่านอย่างเดียว: blocked ไม่เขียน ไม่ยิงอะไร
    // ------------------------------------------------------------------

    #[Test]
    public function test_step_a_blocks_without_writing_or_calling_line(): void
    {
        $cases = [
            'approval_pending' => function (array $seed): array {
                DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['status' => 'pending']);

                return $seed;
            },
            'owner_action_null' => function (array $seed): array {
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['owner_action' => null]);

                return $seed;
            },
            'owner_action_automatic_all_other_conditions_met' => function (array $seed): array {
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update([
                    'owner_action' => 'automatic',
                    'owner_action_at' => '2026-11-16 09:55:00',
                    'owner_telegram_user_id' => '555',
                ]);

                return $seed;
            },
            'owner_action_at_null' => function (array $seed): array {
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['owner_action_at' => null]);

                return $seed;
            },
            'owner_telegram_user_id_null' => function (array $seed): array {
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['owner_telegram_user_id' => null]);

                return $seed;
            },
            'owner_telegram_user_id_empty_string' => function (array $seed): array {
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['owner_telegram_user_id' => '']);

                return $seed;
            },
            'sent_with_revision_2' => function (array $seed): array {
                DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['revision' => 2]);

                return $seed;
            },
            'sent_with_text_one_char_off_the_draft' => function (array $seed): array {
                DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['text' => self::TEXT.'?']);

                return $seed;
            },
            'flagged_failed_without_override' => function (array $seed): array {
                DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['check_answers' => $this->flaggedCheck()]);

                return $seed;
            },
            'flagged_error_without_override' => function (array $seed): array {
                DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['check_answers' => $this->errorCheck()]);

                return $seed;
            },
            'edited_flagged_with_stale_confirmed_revision' => function (array $seed): array {
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['owner_action' => 'edited']);
                DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update([
                    'check_answers' => $this->flaggedCheck(),
                    'confirmed_revision' => 0, // revision - 1
                ]);

                return $seed;
            },
        ];

        foreach ($cases as $label => $mutate) {
            $seed = $this->seedReady();
            $seed = $mutate($seed);
            $before = $this->snapshot($seed);
            $this->fakeNothing();

            $result = $this->send($seed);

            $this->assertSame('blocked', $result, "เคส {$label} ต้อง blocked");
            Http::assertNothingSent();
            $this->assertSame($before, $this->snapshot($seed), "เคส {$label} ห้ามแตะคอลัมน์ใดของ log/approval");
        }
    }

    #[Test]
    public function test_step_a_blocks_when_approval_row_missing(): void
    {
        $this->seedReady();
        $this->fakeNothing();

        $this->assertSame('blocked', app(FollowUpSendService::class)->send(99999999, $this->now()));
        Http::assertNothingSent();
        $this->assertSame(1, DB::table('follow_up_approvals')->count());
    }

    #[Test]
    public function test_step_a_blocks_when_log_row_missing(): void
    {
        $seed = $this->seedReady();

        // สถานะ "บัตรอยู่ แถว log หาย" สร้างไม่ได้เมื่อ FK เปิดใช้ — ปิดฝั่งตารางชั่วคราว
        // (PostgreSQL: session_replication_role replica, sqlite: ข้ามเพราะ pragma ไม่ทำงานใน transaction)
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('set session_replication_role = replica');
        }

        try {
            if ($driver === 'pgsql') {
                DB::table('follow_up_approvals')->where('lead_recovery_log_id', $seed['logId'])->delete();
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->delete();
            } else {
                Schema::disableForeignKeyConstraints();
                DB::table('follow_up_approvals')->where('lead_recovery_log_id', $seed['logId'])->delete();
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->delete();
            }

            $approvalId = DB::table('follow_up_approvals')->insertGetId([
                'lead_recovery_log_id' => $seed['logId'],
                'status' => 'approved',
                'revision' => 1,
                'nonce' => 'abcd1234abcd1234',
                'text' => self::TEXT,
                'check_answers' => $this->cleanCheck(),
                'check_overridden' => false,
                'expires_at' => '2026-11-16 12:00:00',
                'telegram_delivery_state' => 'sent',
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
        } catch (QueryException) {
            Schema::enableForeignKeyConstraints();
            $this->markTestSkipped('บัตรไร้แถว log สร้างไม่ได้เมื่อ FK เปิด (sqlite pragma ใช้ไม่ได้ใน transaction) — สาขาป้องกันไว้และทดสอบจริงบน PostgreSQL');
        } finally {
            if ($driver === 'pgsql') {
                DB::statement('set session_replication_role = origin');
            } else {
                Schema::enableForeignKeyConstraints();
            }
        }

        $this->fakeNothing();

        $this->assertSame('blocked', app(FollowUpSendService::class)->send($approvalId, $this->now()));
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('lead_recovery_logs')->count());
    }

    #[Test]
    public function test_step_a_twins_flagged_overridden_and_edited_all_send(): void
    {
        // (ก) flagged + check_overridden = ผ่าน
        $seed = $this->seedReady([], ['check_answers' => $this->flaggedCheck(), 'check_overridden' => true]);
        $this->fakeLine();
        $this->assertSame('sent', $this->send($seed));
        $message = $this->followUpMessage($seed);
        $this->assertSame('openai/gpt-6-luna', $message->model_used, 'owner_action sent ติด model_used ตาม draft_model');

        // (ข) edited revision 2 clean → ส่งได้ model_used null
        $seed = $this->seedReady(
            ['owner_action' => 'edited'],
            ['revision' => 2, 'confirmed_revision' => 2, 'text' => 'แก้ไขข้อความตามลูกค้าแล้วครับ'],
        );
        $this->fakeLine();
        $this->assertSame('sent', $this->send($seed));
        $message = $this->followUpMessage($seed);
        $this->assertNull($message->model_used, 'owner_action edited ต้องไม่ติด model_used');
        $this->assertSame('แก้ไขข้อความตามลูกค้าแล้วครับ', $message->content);

        // (ค) edited flagged + confirmed_revision ตรง revision → ผ่าน
        $seed = $this->seedReady(
            ['owner_action' => 'edited'],
            ['check_answers' => $this->flaggedCheck(), 'confirmed_revision' => 1],
        );
        $this->fakeLine();
        $this->assertSame('sent', $this->send($seed));
    }

    // ------------------------------------------------------------------
    // Claim — เข้าสถานะ sending ได้เฉพาะ 3 ทาง
    // ------------------------------------------------------------------

    #[Test]
    public function test_claim_refuses_unexpected_states(): void
    {
        $cases = [
            'pending' => ['pending', 'pending'],
            'editing' => ['approved', 'editing'],
            'skipped' => ['approved', 'skipped'],
            'expired' => ['approved', 'expired'],
            'cancelled' => ['approved', 'cancelled'],
            'send_failed' => ['send_failed', 'cancelled'],
            'candidate' => ['candidate', 'approved'],
        ];

        foreach ($cases as $label => [$logStatus, $approvalStatus]) {
            $seed = $this->seedReady(
                ['delivery_status' => $logStatus],
                ['status' => $approvalStatus],
            );
            $before = $this->snapshot($seed);
            $this->fakeNothing();

            $result = $this->send($seed);

            $this->assertSame('blocked', $result, "สถานะ {$label} ต้อง claim ไม่ผ่าน");
            Http::assertNothingSent();
            $this->assertSame($before, $this->snapshot($seed), "สถานะ {$label} ห้ามเขียนอะไรเลย");
        }
    }

    #[Test]
    public function test_claim_time_boundaries(): void
    {
        // retry ที่ยังไม่ถึงคิว (+1 วินาที) → blocked
        $seed = $this->seedReady([
            'delivery_status' => 'retry',
            'retry_key' => $this->retryKey(),
            'first_push_at' => '2026-11-16 09:59:00',
            'message_sent' => self::TEXT,
            'attempt_number' => 1,
            'next_attempt_at' => '2026-11-16 10:00:01',
        ], ['status' => 'sending']);
        $this->fakeLine();
        $this->assertSame('blocked', $this->send($seed), 'ยังไม่ถึงคิว → ไม่ยิงอะไร');
        $this->assertPushCount(0);

        // retry ถึงคิวพอดี (= now) → เดินหน้าจน sent
        $seed = $this->seedReady([
            'delivery_status' => 'retry',
            'retry_key' => $this->retryKey(),
            'first_push_at' => '2026-11-16 09:59:00',
            'message_sent' => self::TEXT,
            'attempt_number' => 1,
            'next_attempt_at' => self::NOW,
        ], ['status' => 'sending']);
        $this->assertSame('sent', $this->send($seed), 'next_attempt_at = now พอดีต้องถึงคิว');
        $this->assertPushCount(1);

        // sending ที่ยังสด (updated_at 9 นาที 59 วินาทีก่อน now) → blocked
        $seed = $this->seedReady([
            'delivery_status' => 'sending',
            'claim_token' => (string) Str::uuid(),
            'claimed_at' => '2026-11-16 09:50:01',
            'updated_at' => '2026-11-16 09:50:01',
        ], ['status' => 'sending']);
        $this->assertSame('blocked', $this->send($seed), 'sending ที่แรง 9:59 ยังไม่ถือว่าค้าง');
        $this->assertPushCount(1);

        // sending ค้าง 10 นาทีพอดี → ชิงจาก worker ตายได้ (dead worker, first_push_at ยังว่าง = ยิงครั้งแรก)
        $seed = $this->seedReady([
            'delivery_status' => 'sending',
            'claim_token' => (string) Str::uuid(),
            'claimed_at' => '2026-11-16 09:50:00',
            'updated_at' => '2026-11-16 09:50:00',
        ], ['status' => 'sending']);
        $this->assertSame('sent', $this->send($seed), 'sending ค้าง 10:00 พอดีต้อง claim ได้');
        $this->assertSame(1, (int) $this->logRow($seed)->attempt_number, 'ครั้งแรกของ dead worker ที่ยังไม่เคยยิง');
        $this->assertPushCount(2);
    }

    #[Test]
    public function test_second_send_while_sending_blocked_and_after_sent_already_sent(): void
    {
        $seed = $this->seedReady();

        // ครั้งแรกส่งสำเร็จ
        $this->fakeLine();
        $this->assertSame('sent', $this->send($seed));

        // ครั้งที่สอง: already_sent, ไม่ยิงซ้ำ
        $this->fakeNothing();
        $this->assertSame('already_sent', $this->send($seed));
        Http::assertNothingSent();

        $this->assertSame(1, (int) DB::table('messages')->where('id', (int) $this->logRow($seed)->message_id)->count(), 'แถวประวัติต้องมีแถวเดียว');
        $this->assertSame(1, (int) DB::table('conversations')->where('id', $seed['room']->id)->value('recovery_attempts'), 'recovery_attempts ต้องนับครั้งเดียว');
    }

    // ------------------------------------------------------------------
    // Gate table — ฝั่งยังไม่ยิง
    // ------------------------------------------------------------------

    #[Test]
    public function test_not_pushed_gates_end_before_any_push(): void
    {
        $cases = [
            'expiry_at_exact_second' => [
                'mutate' => function (array $seed): array {
                    DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['expires_at' => self::NOW]);

                    return $seed;
                },
                'send_at' => null,
                'log_status' => 'expired',
                'approval_status' => 'expired',
                'reason' => 'expired_before_send',
                'result' => 'blocked',
            ],
            'customer_message_added_after_approval' => [
                'mutate' => function (array $seed): array {
                    $this->addMessage($seed['room']->id, 'user', '2026-11-16 09:30:00', 'ตามอีกครับ');

                    return $seed;
                },
                'send_at' => null,
                'log_status' => 'cancelled',
                'approval_status' => 'cancelled',
                'reason' => 'new_customer_message',
                'result' => 'blocked',
            ],
            'order_created_after_approval' => [
                'mutate' => function (array $seed): array {
                    DB::table('orders')->insertGetId([
                        'bot_id' => $seed['bot']->id,
                        'conversation_id' => $seed['room']->id,
                        'total_amount' => 100,
                        'status' => 'completed',
                        'created_at' => '2026-11-16 09:30:00',
                        'updated_at' => '2026-11-16 09:30:00',
                    ]);

                    return $seed;
                },
                'send_at' => null,
                'log_status' => 'cancelled',
                'approval_status' => 'cancelled',
                'reason' => 'order_created',
                'result' => 'blocked',
            ],
            'passed_slip_after_approval' => [
                'mutate' => function (array $seed): array {
                    DB::table('slip_verifications')->insertGetId([
                        'bot_id' => $seed['bot']->id,
                        'conversation_id' => $seed['room']->id,
                        'status' => 'passed',
                        'created_at' => '2026-11-16 09:30:00',
                        'updated_at' => '2026-11-16 09:30:00',
                    ]);

                    return $seed;
                },
                'send_at' => null,
                'log_status' => 'cancelled',
                'approval_status' => 'cancelled',
                'reason' => 'passed_slip',
                'result' => 'blocked',
            ],
            'outside_hours_at_22_00' => [
                // ขยายบัตรให้พ้นเวลา 22:00 ก่อน — ไม่งั้น gate 1 หมดอายุตัดก่อน gate 2 ชั่วโมงส่ง
                'mutate' => function (array $seed): array {
                    DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['expires_at' => '2026-11-17 00:00:00']);

                    return $seed;
                },
                'send_at' => '2026-11-16 22:00:00',
                'log_status' => 'approved',
                'approval_status' => 'approved',
                'reason' => 'outside_hours',
                'result' => 'blocked',
            ],
            'bot_switch_off' => [
                'mutate' => function (array $seed): array {
                    DB::table('bot_settings')->where('bot_id', $seed['bot']->id)->update(['follow_up_enabled' => false]);

                    return $seed;
                },
                'send_at' => null,
                'log_status' => 'approved',
                'approval_status' => 'approved',
                'reason' => 'bot_switched_off',
                'result' => 'blocked',
            ],
            'room_in_handover' => [
                'mutate' => function (array $seed): array {
                    $seed['room']->update(['is_handover' => true]);

                    return $seed;
                },
                'send_at' => null,
                'log_status' => 'approved',
                'approval_status' => 'approved',
                'reason' => 'handover_switched_on',
                'result' => 'blocked',
            ],
            'recipient_other_shape_c_prefix' => [
                'mutate' => function (array $seed): array {
                    $seed['room']->update(['external_customer_id' => 'C'.'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

                    return $seed;
                },
                'send_at' => null,
                'log_status' => 'send_failed',
                'approval_status' => 'cancelled',
                'reason' => 'recipient_invalid',
                'result' => 'failed',
            ],
            'recipient_uppercase_hex' => [
                'mutate' => function (array $seed): array {
                    $seed['room']->update(['external_customer_id' => 'UA1B2C3D4E5F60718293A4B5C6D7E8F90']);

                    return $seed;
                },
                'send_at' => null,
                'log_status' => 'send_failed',
                'approval_status' => 'cancelled',
                'reason' => 'recipient_invalid',
                'result' => 'failed',
            ],
            // D52: รูปร่างผิด = ห้องที่ยังผ่าน eligibility (ไม่ว่าง) แต่ไม่ตรง ^U[0-9a-f]{32}$ —
            // ถ้าตั้งว่าง recheck จะตัดด้วย room_not_active ก่อนถึง gate 3
            'recipient_bad_shape' => [
                'mutate' => function (array $seed): array {
                    $seed['room']->update(['external_customer_id' => 'C999-not-line-shape']);

                    return $seed;
                },
                'send_at' => null,
                'log_status' => 'send_failed',
                'approval_status' => 'cancelled',
                'reason' => 'recipient_invalid',
                'result' => 'failed',
            ],
            'text_401_code_points' => [
                // Step A ต้องเห็น approval.text === log.draft ก่อน จึงแก้ draft ให้ตรงกัน — gate 4 ตัดที่ความยาวเอง
                'mutate' => function (array $seed): array {
                    $long = str_repeat('ก', 401);
                    DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['draft' => $long]);
                    DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['text' => $long]);

                    return $seed;
                },
                'send_at' => null,
                'log_status' => 'send_failed',
                'approval_status' => 'cancelled',
                'reason' => 'unsafe_text',
                'result' => 'failed',
            ],
            'text_four_non_empty_lines' => [
                'mutate' => function (array $seed): array {
                    $lines = "หนึ่ง\nสอง\nสาม\nสี่";
                    DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['draft' => $lines]);
                    DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['text' => $lines]);

                    return $seed;
                },
                'send_at' => null,
                'log_status' => 'send_failed',
                'approval_status' => 'cancelled',
                'reason' => 'unsafe_text',
                'result' => 'failed',
            ],
            'text_with_invisible_control_character' => [
                'mutate' => function (array $seed): array {
                    // \u{07} (bell) อยู่ใน \p{Cc} — PG เก็บได้ (ต่างจาก \u{0} ที่ text column ของ PG ห้าม)
                    $dirty = "ยังสนใจอยู่ไหมครับ.\u{07}";
                    DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['draft' => $dirty]);
                    DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['text' => $dirty]);

                    return $seed;
                },
                'send_at' => null,
                'log_status' => 'send_failed',
                'approval_status' => 'cancelled',
                'reason' => 'unsafe_text',
                'result' => 'failed',
            ],
            'text_with_account_data_built_at_runtime' => [
                'mutate' => function (array $seed): array {
                    $email = implode('@', ['foo', 'mail.com']);
                    $password = implode('', ['p', '@', 'ssw0rd9']);
                    $dirty = $email.'|'.$password;
                    DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['draft' => $dirty]);
                    DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['text' => $dirty]);

                    return $seed;
                },
                'send_at' => null,
                'log_status' => 'send_failed',
                'approval_status' => 'cancelled',
                'reason' => 'unsafe_text',
                'result' => 'failed',
            ],
        ];

        foreach ($cases as $label => $case) {
            $seed = $this->seedReady();
            $seed = $case['mutate']($seed);
            $messagesBefore = (int) DB::table('messages')->where('conversation_id', $seed['room']->id)->count();
            $this->fakeNothing();

            $result = $this->send($seed, $case['send_at']);

            $this->assertSame($case['result'], $result, "เคส {$label} ต้องคืน {$case['result']}");
            Http::assertNothingSent();

            $log = $this->logRow($seed);
            $approval = $this->approvalRow($seed);
            $this->assertSame($case['log_status'], $log->delivery_status, "เคส {$label}: สถานะ log");
            $this->assertSame($case['approval_status'], $approval->status, "เคส {$label}: สถานะ approval");
            $this->assertSame($case['reason'], $log->reason_code, "เคส {$label}: reason_code");
            $this->assertClaimCleared($seed);
            $this->assertFreezeUntouched($seed);
            $this->assertNoMessagesAdded($seed, $messagesBefore);
        }
    }

    #[Test]
    public function test_not_pushed_quota_gate_returns_row_to_approved(): void
    {
        $variants = [
            'below_reserve' => [
                'config' => ['customer_follow_up.quota_reserve_messages' => 250],
                'quota' => [],
                'reason' => 'below_reserve',
            ],
            'quota_http_error' => [
                'config' => [],
                'quota' => [self::CONSUMPTION_URL => '500'],
                'reason' => 'quota_http_error',
            ],
            'reserve_not_configured' => [
                'config' => ['customer_follow_up.quota_reserve_messages' => null],
                'quota' => [],
                'reason' => 'reserve_not_configured',
            ],
        ];

        // Http::fake สะสม stub ภายใน test เดียว — ปิด quota ทางเดียว (500 ตลอด) พอคลุมทั้งสามเวอร์แชนต์
        $this->fakeLine(quota: [self::CONSUMPTION_URL => [['totalUsage' => 10], '500']]);

        foreach ($variants as $label => $variant) {
            $seed = $this->seedReady();
            foreach ($variant['config'] as $key => $value) {
                config([$key => $value]);
            }

            $result = $this->send($seed);

            $this->assertSame('blocked', $result, "เคส {$label} ต้อง blocked");
            $this->assertPushCount(0);

            $log = $this->logRow($seed);
            $this->assertSame('approved', $log->delivery_status, "เคส {$label}: log กลับไป approved");
            $this->assertSame('approved', $this->approvalRow($seed)->status, "เคส {$label}: approval กลับไป approved");
            $this->assertSame($variant['reason'], $log->reason_code, "เคส {$label}: reason ต้องตามโควต้า");
            $this->assertClaimCleared($seed);
            $this->assertFreezeUntouched($seed);
        }
    }

    #[Test]
    public function test_not_pushed_gate_6_last_look_blocks_before_freeze(): void
    {
        $seed = $this->seedReady();
        $messagesBefore = (int) DB::table('messages')->where('conversation_id', $seed['room']->id)->count();
        $this->fakeLine();

        // จำลองข้อความใหม่แลนด์ระหว่างทาง: ฉีดก่อน query max(id) ของ last look (จุดเดียวที่มี SQL รูปร่างนี้)
        DB::connection()->beforeExecuting(function ($query) use ($seed): void {
            if (str_contains($query, 'select max("id") as "aggregate" from "messages"')) {
                $this->addMessage($seed['room']->id, 'user', '2026-11-16 10:00:00', 'ตอบแล้วครับ');
            }
        });

        $result = $this->send($seed);

        DB::connection()->beforeExecuting(function (): void {});

        $this->assertSame('blocked', $result, 'last look เจอข้อความใหม่ต้องหยุดก่อนยิง');
        $this->assertPushCount(0);

        $log = $this->logRow($seed);
        $this->assertSame('cancelled', $log->delivery_status);
        $this->assertSame('cancelled', $this->approvalRow($seed)->status);
        $this->assertSame('message_arrived', $log->reason_code);
        $this->assertClaimCleared($seed);
        $this->assertFreezeUntouched($seed);
        // แถวที่เพิ่มมาคือของ hook (จำลอง webhook) ไม่ใช่ของ service — log ยังไม่มี message_id ชี้ประวัติ
        $this->assertNoMessagesAdded($seed, $messagesBefore + 1);
        $this->assertNull($this->logRow($seed)->message_id, 'ห้ามมีแถวประวัติถูกสร้าง');
    }

    #[Test]
    public function test_expiry_one_second_before_expires_still_sends(): void
    {
        $seed = $this->seedReady();
        DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['expires_at' => '2026-11-16 10:00:01']);
        $this->fakeLine();

        $this->assertSame('sent', $this->send($seed, '2026-11-16 10:00:00'), 'ก่อนหมดอายุ 1 วินาทีต้องยังยิงได้');
    }

    #[Test]
    public function test_inside_hours_one_second_before_close_sends(): void
    {
        $seed = $this->seedReady();
        DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['expires_at' => '2026-11-17 00:00:00']);
        $this->fakeLine();

        $this->assertSame('sent', $this->send($seed, '2026-11-16 21:59:59'), '21:59:59 ยังอยู่ในชั่วโมงส่ง');
    }

    // ------------------------------------------------------------------
    // Gate table — ฝั่งยิงไปแล้ว (acceptance ไม่รู้ผล)
    // ------------------------------------------------------------------

    private function seededPushed(array $overrides = []): array
    {
        $seed = $this->seedReady(array_merge([
            'delivery_status' => 'retry',
            'retry_key' => $this->retryKey(),
            'first_push_at' => self::NOW,
            'message_sent' => self::TEXT,
            'attempt_number' => 1,
            'next_attempt_at' => '2026-11-16 10:01:00',
            'reason_code' => 'line_retryable',
        ], $overrides), ['status' => 'sending']);

        return $seed;
    }

    #[Test]
    public function test_pushed_row_gates_end_send_failed_or_wait(): void
    {
        // (ก) หมดอายุ → send_failed + approval expired + acceptance_unknown_expired
        $seed = $this->seededPushed();
        DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['expires_at' => '2026-11-16 10:00:30']);
        $this->fakeNothing();

        $this->assertSame('failed', $this->send($seed, '2026-11-16 10:01:00'));
        Http::assertNothingSent();
        $log = $this->logRow($seed);
        $this->assertSame('send_failed', $log->delivery_status);
        $this->assertSame('expired', $this->approvalRow($seed)->status);
        $this->assertSame('acceptance_unknown_expired', $log->reason_code);
        $this->assertSame(1, (int) $log->attempt_number, 'attempt_number คงเดิม เพราะ gate ตัดก่อน freeze');
        $this->assertClaimCleared($seed);

        // (ข) ลูกค้าพิมพ์ใหม่ (gate 2 ผ่าน projection ก่อน แล้ว last look เห็น) → send_failed + cancelled + acceptance_unknown_gate_changed
        $seed = $this->seededPushed();
        $this->addMessage($seed['room']->id, 'user', '2026-11-16 10:00:30', 'ตอบแล้วครับ');
        $this->fakeNothing();

        $this->assertSame('failed', $this->send($seed, '2026-11-16 10:01:00'));
        Http::assertNothingSent();
        $log = $this->logRow($seed);
        $this->assertSame('send_failed', $log->delivery_status);
        $this->assertSame('cancelled', $this->approvalRow($seed)->status);
        $this->assertSame('acceptance_unknown_gate_changed', $log->reason_code);
        $this->assertSame(0, $this->hits[self::PUSH_URL] ?? 0);

        // (ค) นอกชั่วโมงส่ง → send_failed + cancelled + acceptance_unknown_gate_changed
        // (ขยายบัตรให้พ้น 22:00 ก่อน ไม่งั้น gate 1 หมดอายุตัดก่อน)
        $seed = $this->seededPushed(['next_attempt_at' => '2026-11-16 22:00:00']);
        DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['expires_at' => '2026-11-17 00:00:00']);
        $this->fakeNothing();

        $this->assertSame('failed', $this->send($seed, '2026-11-16 22:00:00'));
        Http::assertNothingSent();
        $log = $this->logRow($seed);
        $this->assertSame('send_failed', $log->delivery_status);
        $this->assertSame('cancelled', $this->approvalRow($seed)->status);
        $this->assertSame('acceptance_unknown_gate_changed', $log->reason_code);
    }

    #[Test]
    public function test_pushed_row_quota_gate_waits_then_sends(): void
    {
        $seed = $this->seededPushed();
        // ครั้งแรก quota ฝั่ง consumption 500 → รอ; ครั้งถัดไปว่าง → ส่ง (Http::fake สะสม stub — ใช้ลำดับคำตอบแทนการ re-fake)
        $this->fakeLine(quota: [self::CONSUMPTION_URL => ['500', ['totalUsage' => 10]]]);

        $this->assertSame('retry', $this->send($seed, '2026-11-16 10:01:00'), 'โควต้าฝั่งยิงแล้วต้องรอ ไม่ยิงซ้ำ');
        $this->assertPushCount(0);
        $log = $this->logRow($seed);
        $this->assertSame('retry', $log->delivery_status);
        $this->assertSame('2026-11-16 10:06:00', $log->next_attempt_at, 'รอบรอ 300 วินาทีจากตอนเช็ค');
        $this->assertSame('quota_http_error', $log->reason_code);
        $this->assertSame('sending', $this->approvalRow($seed)->status, 'approval ยังค้าง sending');
        $this->assertSame(self::NOW, $log->first_push_at, 'first_push_at คงของเดิม');

        // รอบถัดมาโควต้าว่าง → ส่งสำเร็จ
        $this->assertSame('sent', $this->send($seed, '2026-11-16 10:06:00'));
        $this->assertSame('sent', $this->logRow($seed)->delivery_status);
    }

    #[Test]
    public function test_pushed_row_gate_6_last_look_ends_acceptance_unknown(): void
    {
        $seed = $this->seededPushed();
        $this->fakeLine();

        // ฉีดข้อความใหม่ตรงจุด last look (หลัง recheck ผ่าน) — จำลอง webhook แลนด์ระหว่างทาง
        DB::connection()->beforeExecuting(function ($query) use ($seed): void {
            if (str_contains($query, 'select max("id") as "aggregate" from "messages"')) {
                $this->addMessage($seed['room']->id, 'user', '2026-11-16 10:01:00', 'ตอบแล้วครับ');
            }
        });

        $result = $this->send($seed, '2026-11-16 10:01:00');

        DB::connection()->beforeExecuting(function (): void {});

        $this->assertSame('failed', $result);
        $this->assertPushCount(0);
        $log = $this->logRow($seed);
        $this->assertSame('send_failed', $log->delivery_status);
        $this->assertSame('cancelled', $this->approvalRow($seed)->status);
        $this->assertSame('acceptance_unknown_gate_changed', $log->reason_code);
        $this->assertClaimCleared($seed);
    }

    #[Test]
    public function test_expiry_at_the_equal_second_counts_as_expired(): void
    {
        $seed = $this->seedReady();
        DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['expires_at' => self::NOW]);
        $this->fakeNothing();

        $this->assertSame('blocked', $this->send($seed), 'วินาทีเท่ากับ expires_at นับหมดอายุ (>=)');
        Http::assertNothingSent();
        $log = $this->logRow($seed);
        $this->assertSame('expired', $log->delivery_status);
        $this->assertSame('expired_before_send', $log->reason_code);
        $this->assertSame('expired', $this->approvalRow($seed)->status);
        $this->assertClaimCleared($seed);
    }

    #[Test]
    public function test_owner_reedit_confirmed_after_push_still_sends_frozen_text(): void
    {
        // edited + confirmed_revision = revision: ยิงแรก 503 → เจ้าของแก้ข้อความบนบัตรอีกรอบ →
        // retry ต้องยิง message_sent ที่แช่งไว้ ไม่อ่าน approval.text ใหม่ (D49)
        $seed = $this->seedReady(['owner_action' => 'edited'], ['confirmed_revision' => 1]);
        $this->fakeLine(push: [['{}', 503], ['{}', 200, ['x-line-request-id' => 'req-frozen']]]);

        $this->assertSame('retry', $this->send($seed));
        DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['text' => 'ข้อความแก้รอบสองที่ห้ามหลุดออกไป']);
        $this->assertSame('sent', $this->send($seed, '2026-11-16 10:01:00'));

        $this->assertSame(self::TEXT, json_decode($this->pushes[1][1], true)['messages'][0]['text'], 'รอบ retry ยิงข้อความที่แช่งไว้');
    }

    #[Test]
    public function test_acceptance_with_history_row_already_set_reuses_it(): void
    {
        // กันซ้ำแบบสุดท้าย: acceptance ที่ message_id มีอยู่แล้ว (sending ค้างสมมุติหลังเขียนประวัติ) ต้องใช้แถวเดิม
        $seed = $this->seedReady([
            'delivery_status' => 'sending',
            'first_push_at' => '2026-11-16 09:50:00',
            'message_sent' => self::TEXT,
            'attempt_number' => 1,
            'claimed_at' => '2026-11-16 09:50:00',
            'updated_at' => '2026-11-16 09:50:00',
        ], ['status' => 'sending']);
        $messagesBefore = (int) DB::table('messages')->count();
        // แถวเก่าที่เคยถูกเขียนไว้ — ต้องเป็น bot เพื่อไม่ให้ projector มองว่ามีลูกค้าทักใหม่
        $existingId = $this->addMessage($seed['room']->id, 'bot', '2026-11-15 08:59:30', 'ได้เตรียมส่งให้ครับ');
        DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update([
            'message_id' => $existingId,
            'observed_last_message_id' => $existingId,
        ]);
        // projection ต้องตามข้อความที่เพิ่งแทรก ไม่งั้น recheck ตัดด้วย projection เก่า
        FollowUpSalesProjector::refresh($seed['room'], $this->now());
        $this->fakeLine();

        $this->assertSame('sent', $this->send($seed));
        $this->assertSame($existingId, (int) $this->logRow($seed)->message_id, 'ใช้แถวประวัติเดิม ไม่แทรกใหม่');
        $this->assertSame($messagesBefore + 1, (int) DB::table('messages')->count(), 'จำนวนแถวประวัติคงเดิม');
    }

    // ------------------------------------------------------------------
    // D46 — งบร่างรายวันห้ามตัดตอนส่ง
    // ------------------------------------------------------------------

    #[Test]
    public function test_busy_day_with_full_draft_cap_still_sends(): void
    {
        $seed = $this->seedReady();
        for ($i = 0; $i < 10; $i++) {
            DB::table('lead_recovery_logs')->insertGetId([
                'conversation_id' => $seed['room']->id,
                'bot_id' => $seed['bot']->id,
                'attempt_number' => 0,
                'attempt_count' => 0,
                'message_mode' => 'ai',
                'delivery_status' => 'pending',
                'silence_user_message_id' => null,
                'observed_last_message_id' => null,
                'drafted_at' => '2026-11-16 09:00:00',
                'draft_day' => '2026-11-16',
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
        }
        $this->fakeLine();

        $this->assertSame('sent', $this->send($seed), 'สิบร่างวันนี้ห้ามตัดการส่ง (D46)');
    }

    // ------------------------------------------------------------------
    // สาย retry บนนาฬิกาเดินจริง (D50)
    // ------------------------------------------------------------------

    #[Test]
    public function test_retry_chain_travels_the_clock_with_same_key_and_body(): void
    {
        $seed = $this->seedReady();
        $this->fakeLine(push: [['{}', 503], 'timeout', ['{}', 200, ['x-line-request-id' => 'req-third']]]);

        $this->assertSame('retry', $this->send($seed), 'ครั้งแรก 503 → retry');
        $log = $this->logRow($seed);
        $this->assertSame('retry', $log->delivery_status);
        $this->assertSame(1, (int) $log->attempt_number);
        $this->assertSame('2026-11-16 10:01:00', $log->next_attempt_at, 'รอ 60 วินาที');
        $this->assertSame('line_retryable', $log->reason_code);
        $this->assertSame(self::NOW, $log->first_push_at);
        $this->assertSame(self::TEXT, $log->message_sent);
        $this->assertSame('sending', $this->approvalRow($seed)->status);

        // เรียกก่อนคิว 59 วินาที → blocked ไม่ยิงอะไร
        $this->assertSame('blocked', $this->send($seed, '2026-11-16 10:00:59'));
        $this->assertPushCount(1);

        // ถึงคิว +60 → timeout → retry รอ 300 วินาที
        $this->assertSame('retry', $this->send($seed, '2026-11-16 10:01:00'));
        $log = $this->logRow($seed);
        $this->assertSame(2, (int) $log->attempt_number);
        $this->assertSame('2026-11-16 10:06:00', $log->next_attempt_at, 'รอบสองรอ 300 วินาที');

        // ถึงคิว +360 → 200 → sent
        $this->assertSame('sent', $this->send($seed, '2026-11-16 10:06:00'));
        $this->assertPushCount(3);

        // สาม request คีย์เดิม เนื้อความเดิมทุกตัวอักษร
        $this->assertCount(3, $this->pushes);
        foreach ($this->pushes as $index => $push) {
            $n = $index + 1;
            $this->assertSame($this->pushes[0][0], $push[0], "request ที่ {$n} ต้องใช้ key เดิม");
            $this->assertSame(json_decode($this->pushes[0][1], true), json_decode($push[1], true), "request ที่ {$n} ต้องมี body เดิม");
        }

        $log = $this->logRow($seed);
        $this->assertSame('sent', $log->delivery_status);
        $this->assertSame(1, (int) DB::table('messages')->where('id', (int) $log->message_id)->count(), 'ประวัติแถวเดียว');
        $this->assertSame(self::NOW, $log->first_push_at, 'first_push_at คงเวลายิงแรก');
        $this->assertSame('req-third', $log->line_request_id);
    }

    #[Test]
    public function test_429_behaves_like_503(): void
    {
        $seed = $this->seedReady();
        $this->fakeLine(push: [['{}', 429]]);

        $this->assertSame('retry', $this->send($seed));
        $log = $this->logRow($seed);
        $this->assertSame('retry', $log->delivery_status);
        $this->assertSame('2026-11-16 10:01:00', $log->next_attempt_at);
        $this->assertSame('line_retryable', $log->reason_code);
    }

    #[Test]
    public function test_three_retryable_answers_end_failed_and_fourth_call_is_silent(): void
    {
        $seed = $this->seedReady();
        $this->fakeLine(push: [['{}', 503], ['{}', 503], ['{}', 503]]);

        $this->assertSame('retry', $this->send($seed, self::NOW));
        $this->assertSame('retry', $this->send($seed, '2026-11-16 10:01:00'));
        $this->assertSame('failed', $this->send($seed, '2026-11-16 10:06:00'), 'ยิงครบ 3 ครั้งแล้วคำตอบยัง retryable = จบ');

        $log = $this->logRow($seed);
        $this->assertSame('send_failed', $log->delivery_status);
        $this->assertSame('cancelled', $this->approvalRow($seed)->status);
        $this->assertSame('acceptance_unknown_attempts', $log->reason_code);
        $this->assertSame(3, (int) $log->attempt_number);

        // ครั้งที่สี่: send_failed → claim ปฏิเสธ ไม่ยิงอะไร
        $this->assertSame('blocked', $this->send($seed, '2026-11-16 10:11:00'));
        $this->assertPushCount(3);
    }

    // ------------------------------------------------------------------
    // D49 — payload แช่งตาย
    // ------------------------------------------------------------------

    #[Test]
    public function test_payload_is_frozen_after_first_push(): void
    {
        $seed = $this->seedReady();
        $this->fakeLine(push: [['{}', 503], ['{}', 200, ['x-line-request-id' => 'req-second']]]);
        $this->assertSame('retry', $this->send($seed));
        $firstBody = $this->pushes[0][1];
        $firstKey = $this->pushes[0][0];

        $this->assertSame('sent', $this->send($seed, '2026-11-16 10:01:00'));

        $this->assertSame($firstBody, $this->pushes[1][1], 'request ที่สองต้องยิงข้อความเดิม');
        $this->assertSame($firstKey, $this->pushes[1][0], 'request ที่สองต้องใช้ key เดิม');
        $log = $this->logRow($seed);
        $this->assertSame(self::TEXT, $log->message_sent, 'message_sent คงข้อความยิงแรก');
        $message = $this->followUpMessage($seed);
        $this->assertSame(self::TEXT, $message->content, 'ประวัติต้องเก็บข้อความยิงแรก');
    }

    #[Test]
    public function test_owner_edit_on_the_card_after_first_push_cannot_leak_into_a_retry(): void
    {
        // D49 + D54: หลังยิงแรก approval.text เพี้ยนจาก draft = บัตรไม่ตรงที่เจ้าของอนุมัติ —
        // เช็ค owner รันหลัง claim เป็น gate แรก แล้วจบ send_failed ไม่ปล่อยแถวค้าง retry กินสล็อตโควต้าตลอด
        $seed = $this->seededPushed();
        DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['text' => 'ข้อความที่แก้หลังยิงแรก']);
        $this->fakeNothing();

        $this->assertSame('failed', $this->send($seed, '2026-11-16 10:01:00'));
        Http::assertNothingSent();
        $log = $this->logRow($seed);
        $this->assertSame('send_failed', $log->delivery_status, 'แถวที่ยิงแล้วต้องจบ send_failed ไม่ค้าง retry');
        $this->assertSame('acceptance_unknown_gate_changed', $log->reason_code);
        $this->assertSame('cancelled', $this->approvalRow($seed)->status);
        $this->assertClaimCleared($seed);
        $this->assertSame(self::NOW, $log->first_push_at, 'คอลัมน์ freeze คงของเดิม (gate ตัดก่อน freeze)');
        $this->assertSame(self::TEXT, $log->message_sent);
        $this->assertSame(1, (int) $log->attempt_number);
        $this->assertNull($log->message_id, 'ไม่มีแถวประวัติ');
    }

    // ------------------------------------------------------------------
    // รอบแก้ review — D53/D54/D55
    // ------------------------------------------------------------------

    /** รูปร่าง check_answers ที่อ่านไม่ได้ = นับเป็นมีข้อห้าม (D53) */
    public static function unreadableCheckShapes(): array
    {
        return [
            'sql_null' => [null],
            'json_null' => ['null'],
            'json_empty_list' => ['[]'],
            'json_empty_object' => ['{}'],
            'failed_is_string' => [json_encode(['scores' => ['safe_to_send' => 0.9], 'failed' => 'no_pressure', 'error' => false, 'version' => 1])],
            'failed_and_error_missing' => [json_encode(['scores' => ['safe_to_send' => 0.9], 'version' => 1])],
            'error_is_string_false' => [json_encode(['scores' => ['safe_to_send' => 0.9], 'failed' => [], 'error' => 'false', 'version' => 1])],
        ];
    }

    #[Test]
    #[DataProvider('unreadableCheckShapes')]
    public function test_unreadable_check_record_blocks_like_a_flag(?string $shape): void
    {
        // D53: อ่านบันทึกเช็คไม่ได้ = ถือว่ามีข้อห้าม — owner sent ไม่ยืนยัน → blocked ไม่เขียน ไม่ยิง
        $seed = $this->seedReady([], ['check_answers' => $shape]);
        $before = $this->snapshot($seed);
        $this->fakeNothing();

        $this->assertSame('blocked', $this->send($seed), 'รูปร่าง '.var_export($shape, true).' ต้องนับเป็นมีข้อห้าม');
        Http::assertNothingSent();
        $this->assertSame($before, $this->snapshot($seed), 'บันทึกอ่านไม่ได้ห้ามเขียนคอลัมน์ใด');
    }

    #[Test]
    #[DataProvider('unreadableCheckShapes')]
    public function test_unreadable_check_record_blocks_edited_without_confirmed_revision(?string $shape): void
    {
        // D53: กฎเดียวกันบน owner_action edited — ต้องมี confirmed_revision ตรง revision จึงผ่าน
        $seed = $this->seedReady(['owner_action' => 'edited'], ['check_answers' => $shape]);
        $before = $this->snapshot($seed);
        $this->fakeNothing();

        $this->assertSame('blocked', $this->send($seed));
        Http::assertNothingSent();
        $this->assertSame($before, $this->snapshot($seed));
    }

    #[Test]
    #[DataProvider('unreadableCheckShapes')]
    public function test_unreadable_check_record_with_confirmation_sends(?string $shape): void
    {
        // D53: รูปร่างเดิมแต่เจ้าของยืนยันแล้ว → ยังส่งได้ (sent = check_overridden, edited = confirmed_revision)
        $this->fakeLine();

        $seed = $this->seedReady([], ['check_answers' => $shape, 'check_overridden' => true]);
        $this->assertSame('sent', $this->send($seed));

        $seed = $this->seedReady(['owner_action' => 'edited'], ['check_answers' => $shape, 'confirmed_revision' => 1]);
        $this->assertSame('sent', $this->send($seed));

        $this->assertPushCount(2);
    }

    #[Test]
    public function test_clean_check_record_sends_without_any_confirmation(): void
    {
        // D53: รูปร่างจริงที่สะอาด (failed = [] และ error = false พอดี) ส่งได้โดยไม่ต้องยืนยัน
        $seed = $this->seedReady();
        $this->fakeLine();

        $this->assertSame('sent', $this->send($seed));
        $this->assertPushCount(1);
    }

    // ------------------------------------------------------------------
    // รอบแก้ review — B4d (6a: owner เช็คซ้ำใน claim, 6b/6c: failed ต้องเป็น JSON list)
    // ------------------------------------------------------------------

    /**
     * 6b/6c — failed ต้องเป็น JSON list ว่าง `[]` เท่านั้น; `{}` ไม่ใช่ list (B4d)
     * และ error ต้องเป็น boolean false เป๊ะ (missing/null/0/"" ล้วนนับเป็นมีข้อห้าม)
     */
    public static function notJsonListCheckShapes(): array
    {
        return [
            'failed_is_json_object' => ['{"failed":{},"error":false}'],
            'failed_list_error_missing' => [
                json_encode(['scores' => ['safe_to_send' => 0.9], 'failed' => [], 'version' => 1]),
            ],
            'failed_list_error_null' => [
                json_encode(['scores' => ['safe_to_send' => 0.9], 'failed' => [], 'error' => null, 'version' => 1]),
            ],
            'failed_list_error_zero' => [
                json_encode(['scores' => ['safe_to_send' => 0.9], 'failed' => [], 'error' => 0, 'version' => 1]),
            ],
            'failed_list_error_empty_string' => [
                json_encode(['scores' => ['safe_to_send' => 0.9], 'failed' => [], 'error' => '', 'version' => 1]),
            ],
        ];
    }

    #[Test]
    #[DataProvider('notJsonListCheckShapes')]
    public function test_check_answers_failed_must_be_a_json_list_blocks_like_a_flag(string $shape): void
    {
        // B4d 6b/6c: owner sent ไม่ยืนยัน → blocked ไม่เขียน ไม่ยิง
        $seed = $this->seedReady([], ['check_answers' => $shape]);
        $before = $this->snapshot($seed);
        $this->fakeNothing();

        $this->assertSame('blocked', $this->send($seed), 'รูปร่าง failed ที่ไม่ใช่ JSON list [] ต้องนับเป็นมีข้อห้าม');
        Http::assertNothingSent();
        $this->assertSame($before, $this->snapshot($seed), 'ห้ามเขียนคอลัมน์ใด');
    }

    #[Test]
    #[DataProvider('notJsonListCheckShapes')]
    public function test_check_answers_failed_must_be_a_json_list_blocks_edited_without_confirmation(string $shape): void
    {
        // B4d 6b/6c: กฎเดียวกันบน owner_action edited โดยไม่มี confirmed_revision ตรง
        $seed = $this->seedReady(['owner_action' => 'edited'], ['check_answers' => $shape]);
        $before = $this->snapshot($seed);
        $this->fakeNothing();

        $this->assertSame('blocked', $this->send($seed));
        Http::assertNothingSent();
        $this->assertSame($before, $this->snapshot($seed));
    }

    #[Test]
    public function test_owner_checks_rerun_inside_the_claim_block_an_edited_flagged_card(): void
    {
        // B4d 6a: คนอื่นแก้บัตร "ระหว่าง Step A กับ claim" (probe ของ reviewer) —
        // owner เช็คต้องวิ่งซ้ำบนแถวที่ล็อกแล้วใน claim ไม่อย่างนั้นข้อความที่ถูกแก้+มีข้อห้ามหลุดยิงออกไป
        $seed = $this->seedReady();
        $before = $this->snapshot($seed);
        $this->fakeNothing();
        $armed = true;
        $logSelects = 0;
        DB::connection()->beforeExecuting(function ($query) use (&$armed, &$logSelects, $seed): void {
            if ($armed && str_contains($query, 'lead_recovery_logs') && str_starts_with(strtolower(trim($query)), 'select')) {
                $logSelects++;
                if ($logSelects === 2) { // #1 = Step A, #2 = select ของ claim (ก่อน claim อ่าน/เขียน)
                    $armed = false;
                    // การแก้พอดีคาบ: บัตรเป็น revision 2 + check_answers มีข้อห้าม + เจ้าของแก้เอง
                    DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update([
                        'revision' => 2,
                        'check_answers' => $this->flaggedCheck(),
                        'confirmed_revision' => null,
                    ]);
                    DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update([
                        'owner_action' => 'edited',
                        'owner_action_at' => '2026-11-16 09:55:30',
                    ]);
                }
            }
        });

        $result = $this->send($seed);

        DB::connection()->beforeExecuting(function (): void {});
        $this->assertSame('blocked', $result, 'claim ต้องปฏิเสธบัตรที่ถูกแก้เป็นมีข้อห้ามกลางคัน');
        Http::assertNothingSent();
        $this->assertPushCount(0);

        // แถวทั้งคู่ต้องเป็นแบบที่ "การแก้กลางคัน" ทิ้งไว้เป๊ะ — ไม่มี claim token ไม่มีคอลัมน์อื่นเปลี่ยน
        $expected = [
            'log' => array_merge($before['log'], ['owner_action' => 'edited', 'owner_action_at' => '2026-11-16 09:55:30']),
            'approval' => array_merge($before['approval'], ['revision' => 2, 'check_answers' => $this->flaggedCheck(), 'confirmed_revision' => null]),
        ];
        $this->assertSame($expected, $this->snapshot($seed));
        $this->assertNull($this->logRow($seed)->claim_token, 'claim ถูกปฏิเสธ = ไม่มี claim token');
        $this->assertSame('approved', $this->logRow($seed)->delivery_status, 'log คง approved (claim ไม่เขียน)');
    }

    #[Test]
    public function test_owner_checks_rerun_inside_the_claim_pass_a_card_that_stays_valid(): void
    {
        // twin ของ 6a: การเขียนกลางคันที่ไม่ทำให้ owner เช็คพัง → claim เดินหน้าจน sent ตามปกติ
        $seed = $this->seedReady();
        $this->fakeLine();
        $armed = true;
        $logSelects = 0;
        DB::connection()->beforeExecuting(function ($query) use (&$armed, &$logSelects, $seed): void {
            if ($armed && str_contains($query, 'lead_recovery_logs') && str_starts_with(strtolower(trim($query)), 'select')) {
                $logSelects++;
                if ($logSelects === 2) {
                    $armed = false;
                    DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update([
                        'telegram_delivery_state' => 'delivered',
                    ]);
                    DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update([
                        'owner_action_at' => '2026-11-16 09:55:30',
                    ]);
                }
            }
        });

        $result = $this->send($seed);

        DB::connection()->beforeExecuting(function (): void {});
        $this->assertSame('sent', $result, 'บัตรที่ยังผ่าน owner เช็คต้องส่งได้');
        $this->assertPushCount(1);
    }

    #[Test]
    public function test_claim_refusal_writes_nothing_when_the_approval_changes_mid_claim(): void
    {
        // D55 (reviewer item 1): คนอื่นแก้บัตรพอดีระหว่าง claim → claim ต้อง all-or-nothing —
        // ห้ามเหลือ log sending + claim_token (แถวจะติดค้างตลอดเพราะ claim (a) ต้องการ approved)
        $seed = $this->seedReady();
        $before = $this->snapshot($seed);
        $this->fakeNothing();
        $armed = true;
        DB::connection()->beforeExecuting(function ($query) use (&$armed, $seed): void {
            if ($armed && str_starts_with(strtolower($query), 'update "follow_up_approvals"')) {
                $armed = false;
                // เจ้าของยกเลิกบัตรคาบระหว่าง SELECT กับ UPDATE ของ claim (PG read-committed)
                DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['status' => 'cancelled']);
            }
        });

        $result = $this->send($seed);

        DB::connection()->beforeExecuting(function (): void {});
        $this->assertSame('blocked', $result, 'claim ที่ approval เปลี่ยนกลางทางต้องถูกปฏิเสธ');
        Http::assertNothingSent();
        $this->assertPushCount(0);
        $this->assertSame($before, $this->snapshot($seed), 'claim ถูกปฏิเสธ = log คง approved ไม่มี claim_token ไม่มีคอลัมน์ใดเปลี่ยน');
    }

    #[Test]
    public function test_end_write_refused_by_approval_change_leaves_the_log_exactly_as_before(): void
    {
        // D55: end write (log + approval) ต้อง transaction เดียว — บัตรเปลี่ยนกลางทาง = rollback ทั้งคู่
        // log ต้องเป็นแบบเดียวกับก่อน end write เป๊ะ (sending + claim token ที่เพิ่งได้มา)
        $seed = $this->seededPushed();
        DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['expires_at' => '2026-11-16 10:00:30']);
        $this->fakeNothing();
        $approvalsUpdates = 0;
        DB::connection()->beforeExecuting(function ($query) use (&$approvalsUpdates, $seed): void {
            if (str_starts_with(strtolower($query), 'update "follow_up_approvals"')) {
                $approvalsUpdates++;
                if ($approvalsUpdates === 2) {
                    // ครั้งแรกคือ claim ของ call นี้ (ต้องผ่าน) — ครั้งที่สองคือ approval CAS ของ end write
                    DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['status' => 'cancelled']);
                }
            }
        });

        $result = $this->send($seed, '2026-11-16 10:01:00');

        DB::connection()->beforeExecuting(function (): void {});
        $this->assertSame('blocked', $result, 'end write ที่ approval เปลี่ยนกลางทาง = blocked');
        $this->assertPushCount(0);
        $log = $this->logRow($seed);
        $this->assertSame('sending', $log->delivery_status, 'log ต้องคง sending ก่อน end write');
        $this->assertNotNull($log->claim_token, 'claim token ที่ claim ให้มาต้องคงอยู่');
        $this->assertNotNull($log->claimed_at);
        $this->assertSame('line_retryable', $log->reason_code, 'คอลัมน์อื่นต้องคงค่าก่อน end write ทั้งหมด');
        $this->assertSame(1, (int) $log->attempt_number);
        $this->assertSame(self::NOW, $log->first_push_at);
        $this->assertSame('sending', $this->approvalRow($seed)->status, 'rollback ต้องคืนบัตรกลับ sending');
    }

    #[Test]
    public function test_pushed_row_owner_evidence_changes_end_acceptance_unknown(): void
    {
        // D54: แถวที่ยิงแล้วเช็ค owner หลัง claim (gate แรก) — พังที่ไหนก็จบ send_failed/cancelled
        // เหมือนกันหมด ไม่ปล่อยแถวค้าง retry แบบไม่เขียนอะไรเลย
        $cases = [
            'owner_action_null' => function (array $seed): array {
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['owner_action' => null]);

                return $seed;
            },
            'owner_action_automatic' => function (array $seed): array {
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update([
                    'owner_action' => 'automatic',
                    'owner_action_at' => '2026-11-16 10:00:30',
                    'owner_telegram_user_id' => '555',
                ]);

                return $seed;
            },
            'owner_telegram_user_id_null' => function (array $seed): array {
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['owner_telegram_user_id' => null]);

                return $seed;
            },
            'flag_confirmation_removed' => function (array $seed): array {
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['check_overridden' => false]);
                DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['check_answers' => $this->flaggedCheck()]);

                return $seed;
            },
        ];

        foreach ($cases as $label => $mutate) {
            $seed = $this->seededPushed();
            $seed = $mutate($seed);
            $this->fakeNothing();

            $result = $this->send($seed, '2026-11-16 10:01:00');

            $log = $this->logRow($seed);
            $this->assertSame('failed', $result, "เคส {$label} ต้องจบ failed");
            Http::assertNothingSent();
            $this->assertSame('send_failed', $log->delivery_status, "เคส {$label}: log send_failed");
            $this->assertSame('acceptance_unknown_gate_changed', $log->reason_code, "เคส {$label}: reason");
            $this->assertSame('cancelled', $this->approvalRow($seed)->status, "เคส {$label}: approval cancelled");
            $this->assertClaimCleared($seed);
            $this->assertSame(self::NOW, $log->first_push_at, "เคส {$label}: freeze คงเดิม");
            $this->assertSame(self::TEXT, $log->message_sent);
            $this->assertSame(1, (int) $log->attempt_number);
            $this->assertNull($log->message_id, "เคส {$label}: ไม่มีแถวประวัติ");
        }
    }

    #[Test]
    public function test_pushed_row_owner_gate_ending_frees_the_quota_slot_the_next_day(): void
    {
        // D54: แถวที่จบด้วย owner gate ต้องไม่ค้าง retry — วันถัดมาโควต้าต้องไม่นับแถวนี้อีก
        $this->fakeLine();
        $seed = $this->seededPushed();
        DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['text' => 'ข้อความที่แก้หลังยิงแรก']);

        $this->assertSame('failed', $this->send($seed, '2026-11-16 10:01:00'));
        $this->assertPushCount(0);

        $check = app(FollowUpQuotaService::class)->check($seed['bot'], $this->at('2026-11-17 10:00:00'));
        $this->assertSame(0, $check['local_pending'], 'แถวจบ send_failed ต้องปล่อยสล็อตโควต้าวันถัดมา (D54)');
    }

    // ------------------------------------------------------------------
    // B3a — กติกาฝั่ง automatic: ปลอม = fail closed, กติกาเปลี่ยนก่อนยิง = การ์ด
    // ------------------------------------------------------------------

    #[Test]
    public function test_forged_automatic_with_human_id_bad_revision_bad_checks_or_missing_timestamp_is_blocked(): void
    {
        // แถว automatic ที่โครงสร้างผิดจากที่ pipeline เขียนได้ — fail closed:
        // บล็อกแบบไม่เขียน ไม่ยิง ไม่มีการ์ด (ห้าม "ซ่อม" แถวที่อาจถูกปลอม)
        $cases = [
            'human_telegram_id' => function (array $seed): array {
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['owner_telegram_user_id' => '555']);

                return $seed;
            },
            'missing_decision_timestamp' => function (array $seed): array {
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['owner_action_at' => null]);

                return $seed;
            },
            'bad_revision' => function (array $seed): array {
                DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['revision' => 2]);

                return $seed;
            },
            'text_off_the_draft' => function (array $seed): array {
                DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['text' => self::TEXT.'?']);

                return $seed;
            },
            'flagged_check' => function (array $seed): array {
                DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->update(['check_answers' => $this->flaggedCheck()]);

                return $seed;
            },
        ];

        foreach ($cases as $label => $mutate) {
            $seed = $this->seedAutomaticReady();
            $seed = $mutate($seed);
            $before = $this->snapshot($seed);
            $telegram = Mockery::mock(FollowUpTelegramService::class);
            $telegram->shouldReceive('publish')->never();
            app()->instance(FollowUpTelegramService::class, $telegram);
            $this->fakeNothing();

            $result = $this->send($seed);

            $this->assertSame('blocked', $result, "เคส {$label} ต้อง blocked");
            Http::assertNothingSent();
            $this->assertSame($before, $this->snapshot($seed), "เคส {$label} ห้ามแตะคอลัมน์ใด");
        }
    }

    #[Test]
    public function test_send_rechecks_each_a1_condition(): void
    {
        $this->fakeLine();

        // baseline: ทุกเงื่อนไข A1 ผ่านตอนตัดสินและตอนยิง → ยิงได้ครั้งเดียว
        $seed = $this->seedAutomaticReady();
        $this->assertSame('sent', $this->send($seed));
        $this->assertPushCount(1);

        // เปลี่ยนทีละเงื่อนไข "ระหว่างร่างกับยิง" (baseline + หนึ่งสิ่งที่เปลี่ยน) —
        // กติกาอัตโนมัติพังก่อนยิง → คืนเป็นการ์ดปกติ ยิงไม่ได้เลย
        $cases = [
            'mode_back_to_approve' => function (array $seed): string {
                DB::table('bot_settings')->where('bot_id', $seed['bot']->id)->update(['follow_up_mode' => 'approve']);

                return 'auto_mode_approve';
            },
            'switch_off' => function (array $seed): string {
                DB::table('bot_settings')->where('bot_id', $seed['bot']->id)->update(['follow_up_enabled' => false]);

                return 'auto_switch_off';
            },
            'host_removed' => function (array $seed): string {
                config(['customer_follow_up.automatic_bot_ids' => []]);

                return 'auto_not_allowed';
            },
            'younger_than_14_days' => function (array $seed): string {
                DB::table('bot_settings')->where('bot_id', $seed['bot']->id)->update(['follow_up_started_at' => '2026-11-10 10:00:00']);

                return 'auto_not_allowed';
            },
            'group_g1' => function (array $seed): string {
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['group_code' => 'G1']);

                return 'auto_group';
            },
            'confidence_below' => function (array $seed): string {
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['group_confidence' => 0.7]);

                return 'auto_confidence';
            },
            'declined_at_threshold' => function (array $seed): string {
                DB::table('lead_recovery_logs')->where('id', $seed['logId'])->update(['declined_score' => 0.5]);

                return 'auto_declined';
            },
        ];

        foreach ($cases as $label => $mutate) {
            $seed = $this->seedAutomaticReady();
            $reason = $mutate($seed);
            $telegram = Mockery::mock(FollowUpTelegramService::class);
            $telegram->shouldReceive('publish')->once()->with($seed['approvalId'], Mockery::any())->andReturn('sent');
            app()->instance(FollowUpTelegramService::class, $telegram);

            $result = $this->send($seed);

            $this->assertSame('blocked', $result, "เคส {$label} ต้องไม่ยิง");
            $log = $this->logRow($seed);
            $this->assertSame('pending', $log->delivery_status, "เคส {$label}: คืนเป็นคู่ pending");
            $this->assertSame($reason, $log->reason_code, "เคส {$label}: reason code ของกฎ");
            $this->assertNull($log->owner_action, "เคส {$label}: ล้าง owner_action");
            $this->assertNull($log->owner_action_at, "เคส {$label}: ล้าง owner_action_at");
            $this->assertNull($log->owner_telegram_user_id, "เคส {$label}: ล้าง telegram id");
            $this->assertNull($log->claim_token, "เคส {$label}: ล้าง claim");
            $this->assertNull($log->claimed_at, "เคส {$label}: ล้าง claimed_at");
            $this->assertNull($log->retry_key, "เคส {$label}: ล้าง retry key");
            $this->assertNull($log->first_push_at, "เคส {$label}: ยังไม่เคยยิง");
            $this->assertSame('pending', $this->approvalRow($seed)->status, "เคส {$label}: บัตรกลับ pending");
            $this->assertSame(self::TEXT, $log->draft, "เคส {$label}: ข้อความคงเดิม");
            $this->assertSame('2026-11-16 12:00:00', (string) $this->approvalRow($seed)->expires_at, "เคส {$label}: หมดอายุคงเดิม");
            $this->assertPushCount(1, "เคส {$label} ห้ามเพิ่ม push");
        }
    }

    #[Test]
    public function test_mode_switch_before_first_push_reverts_to_normal_card(): void
    {
        $seed = $this->seedAutomaticReady();
        // เจ้าของสลับโหมดกลับ approve ระหว่าง "ร่างพร้อม" กับ "ยิง" — kill switch ต้องได้การ์ดปกติ
        DB::table('bot_settings')->where('bot_id', $seed['bot']->id)->update(['follow_up_mode' => 'approve']);
        $telegram = Mockery::mock(FollowUpTelegramService::class);
        $telegram->shouldReceive('publish')->once()->with($seed['approvalId'], Mockery::any())->andReturn('sent');
        app()->instance(FollowUpTelegramService::class, $telegram);
        $this->fakeNothing();

        $result = $this->send($seed);

        $this->assertSame('blocked', $result);
        Http::assertNothingSent();
        $log = $this->logRow($seed);
        $this->assertSame('pending', $log->delivery_status);
        $this->assertSame('auto_mode_approve', $log->reason_code);
        $this->assertNull($log->owner_action);
        $this->assertNull($log->owner_action_at);
        $this->assertNull($log->owner_telegram_user_id);
        $this->assertNull($log->claim_token);
        $this->assertNull($log->claimed_at);
        $this->assertNull($log->retry_key);
        $this->assertNull($log->first_push_at);
        $this->assertSame(self::TEXT, $log->draft);
        $approval = $this->approvalRow($seed);
        $this->assertSame('pending', $approval->status);
        $this->assertSame(1, $approval->revision);
        $this->assertSame(self::TEXT, $approval->text);
        $this->assertSame('2026-11-16 12:00:00', (string) $approval->expires_at);
        $this->assertSame('new', $approval->telegram_delivery_state);
    }

    #[Test]
    public function test_switch_off_before_first_push_reverts_to_normal_card(): void
    {
        $seed = $this->seedAutomaticReady();
        DB::table('bot_settings')->where('bot_id', $seed['bot']->id)->update(['follow_up_enabled' => false]);
        $telegram = Mockery::mock(FollowUpTelegramService::class);
        $telegram->shouldReceive('publish')->once()->with($seed['approvalId'], Mockery::any())->andReturn('sent');
        app()->instance(FollowUpTelegramService::class, $telegram);
        $this->fakeNothing();

        $result = $this->send($seed);

        $this->assertSame('blocked', $result);
        Http::assertNothingSent();
        $log = $this->logRow($seed);
        $this->assertSame('pending', $log->delivery_status);
        $this->assertSame('auto_switch_off', $log->reason_code);
        $this->assertNull($log->owner_action);
        $this->assertSame('pending', $this->approvalRow($seed)->status);
        $this->assertNull($log->first_push_at);
    }

    #[Test]
    public function test_double_dispatch_one_push(): void
    {
        $seed = $this->seedAutomaticReady();
        $this->fakeLine();

        $this->assertSame('sent', $this->send($seed));
        $this->assertSame('already_sent', $this->send($seed));

        $this->assertPushCount(1);
        $this->assertSame('sent', $this->logRow($seed)->delivery_status);
        $this->assertSame(1, (int) DB::table('messages')->where('id', (int) $this->logRow($seed)->message_id)->count());
    }

    #[Test]
    public function test_crash_after_freeze_never_reopens_card(): void
    {
        // แถว automatic ที่ freeze แล้ว (อาจกำลังยิง / ยิงไปแล้ว ไม่รู้ผล) ประตูเปลี่ยน —
        // จบ acceptance_unknown เท่านั้น ห้ามกลายเป็นการ์ดที่กดได้ (A11/A16) และห้ามยิงซ้ำ
        $seed = $this->seedAutomaticReady([
            'delivery_status' => 'retry',
            'retry_key' => $this->retryKey(),
            'first_push_at' => self::NOW,
            'message_sent' => self::TEXT,
            'attempt_number' => 1,
            'next_attempt_at' => '2026-11-16 10:01:00',
            'reason_code' => 'line_retryable',
        ], ['status' => 'sending']);
        DB::table('bot_settings')->where('bot_id', $seed['bot']->id)->update(['follow_up_mode' => 'approve']);
        $telegram = Mockery::mock(FollowUpTelegramService::class);
        $telegram->shouldReceive('publish')->never();
        app()->instance(FollowUpTelegramService::class, $telegram);
        $this->fakeNothing();

        $result = $this->send($seed, '2026-11-16 10:01:00');

        $this->assertSame('failed', $result);
        Http::assertNothingSent();
        $log = $this->logRow($seed);
        $this->assertSame('send_failed', $log->delivery_status);
        $this->assertSame('acceptance_unknown_gate_changed', $log->reason_code);
        $this->assertSame('cancelled', $this->approvalRow($seed)->status);
        $this->assertSame(self::NOW, $log->first_push_at);
        $this->assertSame(self::TEXT, $log->message_sent);
        $this->assertSame(1, (int) $log->attempt_number);
        $this->assertNull($log->message_id);
        $this->assertClaimCleared($seed);
    }

    #[Test]
    public function test_retry_reuses_frozen_key_payload_and_existing_limits(): void
    {
        $seed = $this->seedAutomaticReady();
        $this->fakeLine(push: [['{}', 503], 'timeout', ['{}', 200, ['x-line-request-id' => 'req-auto-3']]]);

        $this->assertSame('retry', $this->send($seed));
        $log = $this->logRow($seed);
        $this->assertSame('retry', $log->delivery_status);
        $this->assertSame(1, (int) $log->attempt_number);
        $this->assertSame('2026-11-16 10:01:00', (string) $log->next_attempt_at);
        $this->assertSame(self::NOW, $log->first_push_at);
        $this->assertSame(self::TEXT, $log->message_sent);
        $storedKey = (string) $log->retry_key;

        $this->assertSame('retry', $this->send($seed, '2026-11-16 10:01:00'));
        $this->assertSame(2, (int) $this->logRow($seed)->attempt_number);

        $this->assertSame('sent', $this->send($seed, '2026-11-16 10:06:00'));
        $this->assertPushCount(3);

        $this->assertCount(3, $this->pushes);
        foreach ($this->pushes as $index => $push) {
            $n = $index + 1;
            $this->assertSame($this->pushes[0][0], $push[0], "request {$n} ต้องใช้ key เดิม");
            $this->assertSame(json_decode($this->pushes[0][1], true), json_decode($push[1], true), "request {$n} ต้องมี body เดิม");
        }
        $this->assertSame($storedKey, (string) $this->logRow($seed)->retry_key);
        $this->assertSame(self::NOW, $this->logRow($seed)->first_push_at, 'first_push_at คงเวลายิงแรก');
    }

    #[Test]
    public function test_sender_reads_a1_after_last_look_and_before_freeze(): void
    {
        // A14: กฎ A1 ต้องถูกอ่านซ้ำจากฐานข้อมูล "หลัง" last look และ "ก่อน" freeze UPDATE
        // — พิสูจน์ด้วยลำดับคิวรี่จริง: มี select bot_settings อยู่ระหว่างสองคำสั่งนั้น
        $seed = $this->seedAutomaticReady();
        $this->fakeLine();
        $order = [];
        DB::connection()->beforeExecuting(function ($query) use (&$order): void {
            $sql = strtolower(trim((string) $query));
            if (str_contains($sql, 'select max("id") as "aggregate" from "messages"')) {
                $order[] = 'last_look';
            } elseif (str_contains($sql, 'from "bot_settings"')) {
                $order[] = 'settings';
            } elseif (str_starts_with($sql, 'update "lead_recovery_logs"') && str_contains($sql, 'first_push_at')) {
                $order[] = 'freeze';
            }
        });

        $this->assertSame('sent', $this->send($seed));

        DB::connection()->beforeExecuting(function (): void {});

        $freeze = null;
        foreach ($order as $index => $step) {
            if ($step === 'freeze') {
                $freeze = $index;
                break;
            }
        }
        $lastLook = null;
        foreach ($order as $index => $step) {
            if ($step === 'last_look' && $freeze !== null && $index < $freeze) {
                $lastLook = $index;
            }
        }
        $this->assertNotNull($freeze, 'freeze update ต้องวิ่ง: '.implode(',', $order));
        $this->assertNotNull($lastLook, 'last look query ต้องวิ่งก่อน freeze: '.implode(',', $order));
        $between = array_slice($order, $lastLook + 1, $freeze - $lastLook - 1);
        $this->assertContains('settings', $between, 'A1 re-read (bot_settings) ต้องอยู่ระหว่าง last look กับ freeze: '.implode(',', $order));
    }

    #[Test]
    public function test_gate_change_during_send_flow_stops_the_push_before_freeze(): void
    {
        // A14 แบบมีเงื่อนไขเปลี่ยนจริง "ระหว่างสายยิง": Step A ผ่านหมด แล้ว kill switch
        // โดนปิดพอดีหลัง last look ยิง — ต้องอ่านเจอและคืนการ์ด ห้ามยิงออก LINE
        $seed = $this->seedAutomaticReady();
        $this->fakeLine();
        $telegram = Mockery::mock(FollowUpTelegramService::class);
        $telegram->shouldReceive('publish')->once()->with($seed['approvalId'], Mockery::any())->andReturn('sent');
        app()->instance(FollowUpTelegramService::class, $telegram);
        $flipped = false;
        DB::connection()->beforeExecuting(function ($query) use (&$flipped, $seed): void {
            $sql = strtolower(trim((string) $query));
            if (! $flipped && str_contains($sql, 'select max("id") as "aggregate" from "messages"')) {
                $flipped = true;
                DB::table('bot_settings')->where('bot_id', $seed['bot']->id)->update(['follow_up_enabled' => false]);
            }
        });

        $result = $this->send($seed);

        DB::connection()->beforeExecuting(function (): void {});

        $this->assertSame('blocked', $result);
        // โควต้า GET อ่านก่อน freeze ตามปกติ (step 5) — สิ่งที่ห้ามเกิดคือ push
        $this->assertPushCount(0, 'กติกาพังหลัง last look ต้องไม่ยิงออก LINE');
        $log = $this->logRow($seed);
        $this->assertSame('pending', $log->delivery_status, 'ต้องคืนเป็นคู่ pending');
        $this->assertSame('auto_switch_off', $log->reason_code);
        $this->assertNull($log->owner_action);
        $this->assertNull($log->claim_token);
        $this->assertNull($log->first_push_at, 'ห้าม freeze เมื่อกติกาพังก่อนยิง');
        $this->assertSame('pending', $this->approvalRow($seed)->status);
    }

    // ------------------------------------------------------------------
    // Rejected — จบทันที ไม่มีประวัติ ครั้งถัดไปเงียบ
    // ------------------------------------------------------------------

    #[Test]
    public function test_rejected_statuses_fail_without_history_and_stay_quiet(): void
    {
        // Http::fake สะสม stub — ลำดับคำตอบตามลูปพอดี ห้าม re-fake กลาง test
        $statuses = [[400, 'line_rejected_400'], [401, 'line_rejected_401'], [403, 'line_rejected_403'], [404, 'line_rejected_404'], [409, 'line_rejected_409'], [302, 'line_rejected_302']];
        $this->fakeLine(push: array_map(fn (array $case): array => ['{}', $case[0]], $statuses));
        $pushed = 0;

        foreach ($statuses as [$status, $reason]) {
            $seed = $this->seedReady();

            $this->assertSame('failed', $this->send($seed), "สถานะ {$status} ต้อง failed");

            $log = $this->logRow($seed);
            $pushed++;
            $this->assertSame('send_failed', $log->delivery_status, "สถานะ {$status}: log send_failed");
            $this->assertSame('cancelled', $this->approvalRow($seed)->status);
            $this->assertSame($reason, $log->reason_code);
            $this->assertSame(self::NOW, $log->first_push_at, "สถานะ {$status}: freeze ติดก่อน POST ตาม Step D");
            $this->assertNull($log->message_id, "สถานะ {$status}: ไม่มีแถวประวัติ");
            $this->assertSame(0, (int) DB::table('conversations')->where('id', $seed['room']->id)->value('recovery_attempts'));
            $this->assertPushCount($pushed);

            // ครั้งถัดไป: send_failed → claim ปฏิเสธ เงียบสนิท
            $this->assertSame('blocked', $this->send($seed));
            $this->assertPushCount($pushed);
        }
    }

    #[Test]
    public function test_bot_without_token_fails_with_no_request(): void
    {
        $seed = $this->seedReady();
        $seed['bot']->update(['channel_access_token' => '']);
        // quota gate ต้องผ่านก่อน (การขาด token ตัดสินที่ pushFollowUp เอง) — stub quota ที่ขอบเซอร์วิส
        $quota = Mockery::mock(FollowUpQuotaService::class);
        $quota->shouldReceive('check')->andReturn(['allowed' => true, 'reason' => null]);
        app()->instance(FollowUpQuotaService::class, $quota);
        $this->fakeNothing();

        $this->assertSame('failed', $this->send($seed));
        $this->assertPushCount(0, 'ไม่มี token = ไม่ยิงอะไรออกไป');
        Http::assertNothingSent();
        $log = $this->logRow($seed);
        $this->assertSame('send_failed', $log->delivery_status);
        $this->assertSame('line_rejected_no_request', $log->reason_code);
        $this->assertNull($log->message_id, 'ไม่มีแถวประวัติ');
    }

    // ------------------------------------------------------------------
    // Accept แล้ว crash — คีย์เดิมรีไลน์ ประวัติซ้ำไม่ได้
    // ------------------------------------------------------------------

    #[Test]
    public function test_accept_then_crash_recovers_with_same_key_and_single_history_row(): void
    {
        $seed = $this->seedReady();
        $this->fakeLine(push: [['{}', 200, ['x-line-request-id' => 'req-crash-1']], ['{}', 409, ['x-line-accepted-request-id' => 'req-crash-2']]]);

        // กลไก: hook โยน exception ก่อน INSERT messages ตัวแรกของ acceptance transaction (รอบเดียว)
        $armed = true;
        DB::connection()->beforeExecuting(function ($query) use (&$armed): void {
            if ($armed && str_contains(strtolower($query), 'insert into "messages"')) {
                $armed = false;
                throw new RuntimeException('boom-acceptance-crash-7f3a9c2');
            }
        });

        $result = $this->send($seed);

        $this->assertSame('retry', $result, 'accept แล้ว DB ล้ม ต้องรอรอบหน้าด้วยคีย์เดิม');
        $log = $this->logRow($seed);
        $this->assertContains($log->delivery_status, ['retry', 'sending'], 'log ต้องเป็น retry หรือ sending');
        $this->assertNull($log->message_id, 'ยังไม่มีแถวประวัติ');
        $this->assertSame(self::NOW, $log->first_push_at);
        $this->assertSame(self::TEXT, $log->message_sent);
        $firstKey = $this->pushes[0][0];
        $this->assertSame($firstKey, $log->retry_key, 'คีย์คงอยู่หลัง crash');
        $this->assertSame('2026-11-16 10:01:00', $log->next_attempt_at);

        // รอบสอง: LINE ตอบ 409 พร้อม header ยอมรับ → เขียนประวัติจากคีย์เดิม
        $this->assertSame('sent', $this->send($seed, '2026-11-16 10:01:00'));

        $log = $this->logRow($seed);
        $this->assertSame('sent', $log->delivery_status);
        $this->assertSame('req-crash-2', $log->line_request_id, 'line_request_id ต้องจาก header 409');
        $this->assertSame(1, (int) DB::table('messages')->where('id', (int) $log->message_id)->count(), 'ประวัติแถวเดียว');
        $this->assertCount(2, $this->pushes);
        $this->assertSame($firstKey, $this->pushes[1][0], 'สอง request ต้องใช้คีย์เดียวกัน');
        $this->assertSame($this->pushes[0][1], $this->pushes[1][1], 'สอง request ต้องมี body เดียวกัน');
    }

    #[Test]
    public function test_accept_then_crash_with_409_without_header_ends_failed(): void
    {
        $seed = $this->seedReady();
        $this->fakeLine(push: [['{}', 200, ['x-line-request-id' => 'req-crash-1']], ['{}', 409]]);

        $armed = true;
        DB::connection()->beforeExecuting(function ($query) use (&$armed): void {
            if ($armed && str_contains(strtolower($query), 'insert into "messages"')) {
                $armed = false;
                throw new RuntimeException('boom-acceptance-crash-7f3a9c2');
            }
        });

        $this->assertSame('retry', $this->send($seed));

        $this->assertSame('failed', $this->send($seed, '2026-11-16 10:01:00'), '409 ไร้ header ยอมรับ = จบแบบไม่รู้ผล');

        $log = $this->logRow($seed);
        $this->assertSame('send_failed', $log->delivery_status);
        $this->assertSame('line_rejected_409', $log->reason_code);
        $this->assertNull($log->message_id, 'ยังไม่มีประวัติ');
    }

    // ------------------------------------------------------------------
    // Dead worker — sending ค้างพร้อม first_push_at
    // ------------------------------------------------------------------

    #[Test]
    public function test_dead_worker_resumes_with_stored_key_and_text_or_caps_out(): void
    {
        // Http::fake สะสม stub — fakeLine ครั้งเดียวบนสุด quota โอเคทั้ง test
        $this->fakeLine();

        $storedKey = $this->retryKey();
        $shared = [
            'delivery_status' => 'sending',
            'retry_key' => $storedKey,
            'first_push_at' => '2026-11-16 09:50:00',
            'message_sent' => self::TEXT,
            'attempt_number' => 1,
            'claimed_at' => '2026-11-16 09:50:00',
            'updated_at' => '2026-11-16 09:50:00',
        ];

        // (ก) attempt 1 → ยิงซ้ำด้วยคีย์/ข้อความที่เก็บไว้ แล้วสำเร็จ
        $seed = $this->seedReady($shared, ['status' => 'sending']);
        $this->assertSame('sent', $this->send($seed), 'worker ตายทิ้ง attempt 1 ต้องรีไลน์ได้');
        $this->assertSame($storedKey, $this->pushes[0][0], 'ใช้คีย์ที่เก็บไว้');
        $this->assertSame(2, (int) $this->logRow($seed)->attempt_number, 'attempt นับต่อจากเดิม');
        $this->assertPushCount(1);

        // (ข) attempt 3 ครบแล้ว → ไม่ยิง จบ acceptance_unknown_attempts (cap ตัดหลัง gate ครบ)
        $seed = $this->seedReady(array_merge($shared, ['retry_key' => $this->retryKey(), 'attempt_number' => 3]), ['status' => 'sending']);
        $this->assertSame('failed', $this->send($seed));
        $this->assertPushCount(1);
        $log = $this->logRow($seed);
        $this->assertSame('send_failed', $log->delivery_status);
        $this->assertSame('cancelled', $this->approvalRow($seed)->status);
        $this->assertSame('acceptance_unknown_attempts', $log->reason_code);
        $this->assertSame(3, (int) $log->attempt_number, 'ห้ามเพิ่ม attempt เมื่อไม่ยิง');

        // (ค) sending ค้าง 10 นาที แต่ยังไม่เคยยิง → ยิงครั้งแรก
        $seed = $this->seedReady([
            'delivery_status' => 'sending',
            'claimed_at' => '2026-11-16 09:50:00',
            'updated_at' => '2026-11-16 09:50:00',
        ], ['status' => 'sending']);
        $this->assertSame('sent', $this->send($seed));
        $this->assertSame(1, (int) $this->logRow($seed)->attempt_number, 'attempt แรกของการยิงจริง');
        $this->assertSame(self::NOW, $this->logRow($seed)->first_push_at);
        $this->assertPushCount(2);
    }

    // ------------------------------------------------------------------
    // แถวประวัติ ตัวชี้ห้อง และ event
    // ------------------------------------------------------------------

    #[Test]
    public function test_history_row_columns_conversation_pointers_and_events(): void
    {
        Event::fake([MessageSent::class, ConversationUpdated::class]);

        $seed = $this->seedReady();
        $countBefore = (int) DB::table('conversations')->where('id', $seed['room']->id)->value('message_count');
        $this->fakeLine();

        $this->assertSame('sent', $this->send($seed));

        $log = $this->logRow($seed);
        $message = $this->followUpMessage($seed);

        // ทุกคอลัมน์ของ Task item 4
        $this->assertSame($seed['room']->id, (int) $message->conversation_id);
        $this->assertSame('bot', $message->sender);
        $this->assertSame('text', $message->type);
        $this->assertSame(self::TEXT, $message->content, 'content ตรง message_sent ทุกตัวอักษร');
        $this->assertSame('openai/gpt-6-luna', $message->model_used, 'owner_action sent → model_used = draft_model');
        $this->assertNull($message->prompt_tokens);
        $this->assertNull($message->completion_tokens);
        $this->assertNull($message->cost);
        $this->assertSame(json_encode(['follow_up' => true, 'follow_up_log_id' => $seed['logId']]), $message->metadata);
        $this->assertSame('2026-11-16 10:00:00', $message->created_at);
        $this->assertSame('2026-11-16 10:00:00', $message->updated_at);
        $this->assertSame((int) $message->id, (int) $log->message_id);
        $this->assertSame('2026-11-16 10:00:00', $log->sent_at);
        $this->assertSame(self::REQUEST_ID, $log->line_request_id);
        $this->assertNull($log->reason_code);
        $this->assertNull($log->next_attempt_at);
        $this->assertClaimCleared($seed);
        $this->assertSame('sent', $this->approvalRow($seed)->status);

        // ตัวชี้ห้อง
        $conversation = DB::table('conversations')->where('id', $seed['room']->id)->first();
        $this->assertSame($countBefore + 1, (int) $conversation->message_count);
        $this->assertSame(1, (int) $conversation->recovery_attempts);
        $this->assertSame('2026-11-16 10:00:00', $conversation->last_recovery_at);
        $this->assertSame((int) $message->id, (int) $conversation->last_message_id);
        $this->assertSame('2026-11-16 10:00:00', $conversation->last_message_at);

        // tag ฝั่ง resource (B4b)
        $model = Message::query()->findOrFail((int) $message->id);
        $this->assertTrue((new MessageResource($model))->resolve()['is_follow_up'], 'MessageResource ต้องติด is_follow_up true');

        // event หนึ่งครั้งต่อชนิด และยิงหลังแถวมีอยู่จริง
        Event::assertDispatched(MessageSent::class, 1);
        Event::assertDispatched(ConversationUpdated::class, 1);
        Event::assertDispatched(MessageSent::class, function (MessageSent $event): bool {
            return DB::table('messages')->where('id', $event->message->id)->exists();
        });
    }

    #[Test]
    public function test_concurrent_higher_id_message_keeps_last_message_id_untouched(): void
    {
        $seed = $this->seedReady();
        $this->fakeLine();

        // จำลองข้อความแลนด์ระหว่าง push กับ acceptance: ฉีดตรง query max(id) ครั้งที่สอง (ครั้งแรกคือ last look ต้องผ่าน)
        $seen = 0;
        DB::connection()->beforeExecuting(function ($query) use ($seed, &$seen): void {
            if (str_contains($query, 'select max("id") as "aggregate" from "messages"')) {
                $seen++;
                if ($seen === 2) {
                    $this->addMessage($seed['room']->id, 'user', '2026-11-16 10:00:00', 'ตอบแล้วครับ');
                }
            }
        });

        $this->assertSame('sent', $this->send($seed));

        $conversation = DB::table('conversations')->where('id', $seed['room']->id)->first();
        $botMessage = $this->followUpMessage($seed);
        $this->assertNotSame((int) $botMessage->id, (int) $conversation->last_message_id, 'last_message_id ห้ามโดนเขียนทับเมื่อมีข้อความใหม่ id สูงกว่า');
        $this->assertNull($conversation->last_message_id, 'last_message_id ต้องคงค่า null เดิม');
        $this->assertSame('2026-11-16 10:00:00', $conversation->last_message_at, 'แถวที่ฉีดมี created_at ไม่ใหม่กว่า now → last_message_at ยังตั้งเป็น now');
    }

    #[Test]
    public function test_later_stored_last_message_at_is_not_overwritten(): void
    {
        $seed = $this->seedReady();
        DB::table('conversations')->where('id', $seed['room']->id)->update(['last_message_at' => '2026-11-16 11:00:00']);
        $this->fakeLine();

        $this->assertSame('sent', $this->send($seed));

        $conversation = DB::table('conversations')->where('id', $seed['room']->id)->first();
        $this->assertSame('2026-11-16 11:00:00', $conversation->last_message_at, 'ค่าที่เก็บใหม่กว่า now ต้องคงอยู่');
        $this->assertSame('2026-11-16 10:00:00', $conversation->last_recovery_at);
    }

    #[Test]
    public function test_broadcast_throw_keeps_result_sent(): void
    {
        $seed = $this->seedReady();
        $this->fakeLine();

        $service = Mockery::mock(FollowUpSendService::class, [app(LINEService::class), app(FollowUpQuotaService::class), app(FollowUpTelegramService::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->shouldReceive('broadcastAccepted')
            ->once()
            ->andThrow(new RuntimeException('boom-broadcast-7f3a9c2'))
            ->getMock();
        app()->instance(FollowUpSendService::class, $service);

        $this->assertSame('sent', $this->send($seed), 'broadcast พังห้ามพาผลลัพธ์หาย');
        $this->assertSame('sent', $this->logRow($seed)->delivery_status);
        $this->assertNotNull($this->followUpMessage($seed), 'แถวประวัติต้องยังอยู่ครบ');
    }

    // ------------------------------------------------------------------
    // โควต้านับแถวที่ยิงจริงเท่านั้น
    // ------------------------------------------------------------------

    #[Test]
    public function test_quota_local_pending_counts_only_rows_that_pushed(): void
    {
        // Http::fake สะสม stub — fakeLine ครั้งเดียวพอ: (ข)(ค) จบที่ gate 2/3 ก่อนถึง quota และไม่มี push
        $this->fakeLine();

        // (ก) sent → local_pending 1
        $seed = $this->seedReady();
        $this->assertSame('sent', $this->send($seed));
        $check = app(FollowUpQuotaService::class)->check($seed['bot'], $this->now()->addMinute());
        $this->assertSame(1, $check['local_pending'], 'แถว sent ของวันนี้ต้องยังนับ pending ของ LINE');

        // (ข) send_failed หลังยิงแล้ว → local_pending 1
        $seed = $this->seededPushed();
        $this->addMessage($seed['room']->id, 'user', '2026-11-16 10:00:30', 'ตอบแล้วครับ');
        $this->assertSame('failed', $this->send($seed, '2026-11-16 10:01:00'));
        $check = app(FollowUpQuotaService::class)->check($seed['bot'], $this->at('2026-11-16 10:01:00'));
        $this->assertSame(1, $check['local_pending'], 'แถวยิงไปแล้วแม้รู้ผล send_failed ก็นับ');

        // (ค) send_failed ที่ไม่เคยยิง (recipient รูปร่างผิด) → local_pending 0
        $seed = $this->seedReady();
        $seed['room']->update(['external_customer_id' => 'C999-not-line-shape']);
        $this->assertSame('failed', $this->send($seed));
        $check = app(FollowUpQuotaService::class)->check($seed['bot'], $this->now());
        $this->assertSame(0, $check['local_pending'], 'แถวที่จบก่อนยิงห้ามนับ');
        $this->assertPushCount(1);
    }

    // ------------------------------------------------------------------
    // Logs — ไม่หลือด canary / คีย์ / exception และบรรทัดเดียวต่อ call
    // ------------------------------------------------------------------

    #[Test]
    public function test_logs_never_leak_canaries_retry_key_or_exception_messages(): void
    {
        $canary = '7f3a9c2';
        $canaryRecipient = 'U'.str_pad($canary, 32, 'a0');
        $this->assertMatchesRegularExpression('/^U[0-9a-f]{32}$/', $canaryRecipient);

        $seed = $this->seedReady([
            'draft' => 'ทดสอบตามลูกค้า '.$canary.' ยังสนใจอยู่ไหมครับ',
        ], ['text' => 'ทดสอบตามลูกค้า '.$canary.' ยังสนใจอยู่ไหมครับ']);
        DB::table('conversations')->where('id', $seed['room']->id)->update(['external_customer_id' => $canaryRecipient]);
        FollowUpSalesProjector::refresh($seed['room'], $this->now());
        $seed['bot']->update(['channel_access_token' => implode('', ['fake', $canary, 'Line', 'Token'])]);
        $this->fakeLine();

        $this->assertSame('sent', $this->send($seed));

        $retryKey = $this->pushes[0][0];

        foreach ($this->logLines as $line) {
            $rendered = json_encode(array_merge([$line->message], $line->context), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString($canary, $rendered, 'ห้ามมี canary หลุดลง log');
            $this->assertStringNotContainsString($retryKey, $rendered, 'ห้ามมี retry key หลุดลง log');
            $this->assertStringNotContainsString('boom', $rendered, 'ห้ามมีข้อความ exception หลุดลง log');
        }

        $sendLines = array_filter($this->logLines, fn (MessageLogged $line) => $line->message === 'Customer follow-up send');
        $this->assertCount(1, $sendLines, 'หนึ่ง call ต้อง log บรรทัดเดียว');
        $context = array_values($sendLines)[0]->context;
        $this->assertSame(['approval_id', 'log_id', 'bot_id', 'result', 'reason'], array_keys($context));
        $this->assertSame($seed['approvalId'], $context['approval_id']);
        $this->assertSame($seed['logId'], $context['log_id']);
        $this->assertSame('sent', $context['result']);
    }
}
