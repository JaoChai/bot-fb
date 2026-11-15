<?php

namespace Tests\Feature\FollowUp;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\User;
use App\Services\FollowUp\FollowUpSendService;
use App\Services\FollowUp\FollowUpTelegramService;
use App\Services\Payment\TelegramAlertBotService;
use App\Support\FollowUpRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด B6a: ส่งบัตรร่างข้อความตามลูกค้าเข้า Telegram เป็นใบแรก
 * - การขนส่ง TelegramAlertBotService::sendFollowUpCard (ตาราง outcome ครบทุกแถว + port ปิดหนึ่งกรณี)
 * - ผู้เผยแพร่ FollowUpTelegramService::publish (ตารางสถานะ ตารางผลลัพธ์ ข้อความ golden กฎรายฟิลด์)
 * ไม่มีการเรียกจริงออกนอกเครื่อง ยกเว้น socket ไป port ปิดในเครื่องหนึ่งครั้งตามการ์ด
 */
class TelegramCardTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 10:00:00';

    /** token ปลอมสำหรับทดสอบ ประกอบจากสองชิ้นตอนรันไทม์ ไม่เขียนติดกันในไฟล์ */
    private const TOKEN_HEAD = 'fakeFollowUpCardToken';

    private const TOKEN_TAIL = '991';

    private const CHAT_ID = 'canary-chat-991';

    private const HTML_CANARY = 'htmlcanary-991';

    private const BODY_CANARY = 'bodycanary-991';

    private const NONCE = 'abcd1234abcd1234';

    private const CLEAN_CHECK = '{"scores":{"safe_to_send":0.9},"failed":[],"error":false,"version":1}';

    private const DRAFT_CLEAN = "สวัสดีครับพี่ เมื่อวานที่คุยเรื่องบัญชีโฆษณาไว้\nมีจุดไหนให้ช่วยดูเพิ่มไหมครับ";

    private const GOLDEN_CLEAN_TEXT = <<<'TEXT'
🔔 <b>ตามลูกค้าที่เงียบไป</b> · #123
บอท: Line - Adsvance
ลูกค้า: สมชาย &lt;ร้าน A&amp;B&gt;
กลุ่ม: สนใจแต่ยังลังเล · Luna มั่นใจ 86%
ลูกค้าพิมพ์ล่าสุด: ขอคิดดูก่อนนะครับ
บอทตอบล่าสุด: ได้เลยครับพี่ สนใจตัวไหนทักมาได้เลยครับ

<b>ร่างข้อความ</b>
สวัสดีครับพี่ เมื่อวานที่คุยเรื่องบัญชีโฆษณาไว้
มีจุดไหนให้ช่วยดูเพิ่มไหมครับ

ตรวจข้อความ: ✅ ผ่าน
หมดอายุ: 16/11 22:12 (12 ชั่วโมง)
เปิดห้อง: https://admin.example.test/chat?botId=26&amp;conversationId=1317
TEXT;

    private const GOLDEN_FLAGGED_TEXT = <<<'TEXT'
🔔 <b>ตามลูกค้าที่เงียบไป</b> · #123
บอท: Line - Adsvance
ลูกค้า: สมชาย &lt;ร้าน A&amp;B&gt;
กลุ่ม: ค้างที่ขั้นจ่ายเงิน
ลูกค้าพิมพ์ล่าสุด: ขอคิดดูก่อนนะครับ
บอทตอบล่าสุด: ได้เลยครับพี่ สนใจตัวไหนทักมาได้เลยครับ

<b>ร่างข้อความ</b>
สวัสดีครับพี่ เมื่อวานที่คุยเรื่องบัญชีโฆษณาไว้
มีจุดไหนให้ช่วยดูเพิ่มไหมครับ

ตรวจข้อความ: ⚠️ เตือน: สัญญาผลหรือเวลา · พูดถึงราคาหรือสต็อก
เจ้านายยังกดส่งได้ แต่ข้อความนี้มีคำเตือน
หมดอายุ: 16/11 22:12 (12 ชั่วโมง)
เปิดห้อง: https://admin.example.test/chat?botId=26&amp;conversationId=1317
TEXT;

    /** @var list<MessageLogged> */
    private array $logEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logEvents = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logEvents[] = $event;
        });
    }

    // ---------------------------------------------------------------- helpers

    private function fakeToken(): string
    {
        return self::TOKEN_HEAD.self::TOKEN_TAIL;
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW, 'Asia/Bangkok');
    }

    private function resetLogCaptures(): void
    {
        $this->logEvents = [];
    }

    private function assertNoSecretsInLog(): void
    {
        foreach ($this->logEvents as $event) {
            $text = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString($this->fakeToken(), $text, 'token ห้ามลง log');
            self::assertStringNotContainsString(self::CHAT_ID, $text, 'chat id ห้ามลง log');
            self::assertStringNotContainsString(self::HTML_CANARY, $text, 'ข้อความห้ามลง log');
            self::assertStringNotContainsString(self::BODY_CANARY, $text, 'response body ห้ามลง log');
        }
    }

    private function assertNoBotMarkerInLog(): void
    {
        foreach ($this->logEvents as $event) {
            $text = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString('/bot', $text);
            self::assertStringNotContainsString($this->fakeToken(), $text);
        }
    }

    private function assertOutcome(array $expected, array $result): void
    {
        self::assertSame($expected['outcome'], $result['outcome']);
        self::assertSame($expected['message_id'] ?? null, $result['message_id']);
        self::assertSame($expected['status'] ?? null, $result['status']);
        self::assertSame($expected['retry_after'] ?? null, $result['retry_after']);
    }

    private function okStub(): \Closure
    {
        return fn () => Http::response(json_encode(['ok' => true, 'result' => ['message_id' => 77]]), 200);
    }

    /**
     * รัน sendFollowUpCard หนึ่งครั้งใต้ fake ที่จับทุก request
     * คืน [ผลลัพธ์, รายการ request]
     */
    private function transportCall(\Closure $stub): array
    {
        $requests = [];
        $this->resetLogCaptures();
        Http::fake(function ($request) use (&$requests, $stub) {
            $requests[] = $request;

            return $stub($request);
        });

        $result = (new TelegramAlertBotService)->sendFollowUpCard(
            $this->fakeToken(),
            self::CHAT_ID,
            'การ์ดทดสอบ '.self::HTML_CANARY,
            [['text' => 'ส่ง', 'callback_data' => 'fs|1|1|'.self::NONCE]],
        );

        $this->assertNoSecretsInLog();

        return [$result, $requests];
    }

    private function assertSingleSendMessageRequest(object $request): void
    {
        self::assertSame('https://api.telegram.org/bot'.$this->fakeToken().'/sendMessage', (string) $request->url());
        self::assertSame(self::CHAT_ID, $request['chat_id']);
        self::assertSame('การ์ดทดสอบ '.self::HTML_CANARY, $request['text']);
        self::assertSame('HTML', $request['parse_mode']);
        self::assertSame(
            ['inline_keyboard' => [['text' => 'ส่ง', 'callback_data' => 'fs|1|1|'.self::NONCE]]],
            json_decode($request['reply_markup'], true),
        );
        self::assertSame(['is_disabled' => true], json_decode($request['link_preview_options'], true));
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * seed โลกพร้อมส่ง: บอท pilot + plugin telegram เปิด + log pending + approval pending/new
     * ค่าหลักบ้านสร้างตามที่ FollowUpPipeline สร้างจริง (status pending, revision 1, state new)
     */
    private function seedPending(array $options = []): array
    {
        $get = fn (string $key, mixed $default = null) => array_key_exists($key, $options) ? $options[$key] : $default;

        $user = User::factory()->owner()->create();
        $botAttributes = [
            'user_id' => $user->id,
            'name' => $get('bot_name', 'บอททดสอบการ์ด'),
            'primary_chat_model' => 'openai/gpt-6-luna',
            'channel_access_token' => 'line-token-'.self::TOKEN_TAIL,
        ];
        if ($get('bot_id', null) !== null) {
            $botAttributes['id'] = $get('bot_id');
        }
        $bot = Bot::factory()->active()->line()->create($botAttributes);
        config(['customer_follow_up.bot_ids' => [$bot->id]]);
        config(['customer_follow_up.admin_web_url' => $get('admin_web_url', 'https://admin.example.test')]);
        config(['services.telegram_alert.secret' => 'webhook-secret-test']);

        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);

        $pluginConfig = [
            'access_token' => 'tg-plugin-token-'.self::TOKEN_TAIL,
            'chat_id' => '999',
        ];
        if (! $get('allowlist_missing', false)) {
            $pluginConfig['authorized_user_ids'] = ['555'];
        }
        $plugin = FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งเตือน',
            'enabled' => $get('plugin_enabled', true),
            'trigger_condition' => 'always',
            'config' => $pluginConfig,
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

        $profile = $get('profile_null', false)
            ? null
            : CustomerProfile::factory()->create([
                'channel_type' => 'line',
                'display_name' => $get('display_name', 'สมชาย ใจดี'),
            ]);
        $roomAttributes = [
            'bot_id' => $bot->id,
            'customer_profile_id' => $profile?->id,
            'external_customer_id' => 'U'.self::TOKEN_TAIL.'canary',
        ];
        if ($get('conversation_id', null) !== null) {
            $roomAttributes['id'] = $get('conversation_id');
        }
        $room = Conversation::factory()->line()->create($roomAttributes);

        $anchorId = $this->addMessage($room->id, 'user', '2026-11-15 08:59:00', 'ประเมินราคาให้หน่อยครับ');
        $this->addMessage($room->id, 'bot', '2026-11-15 09:00:00', 'ได้ครับ กำลังเตรียมใบเสนอราคาให้ครับ');

        $context = $get('context_redacted', ['C: ขอคิดดูก่อนนะครับ', 'B: ได้เลยครับพี่ สนใจตัวไหนทักมาได้เลยครับ']);

        $logForcedId = $get('log_id', null);
        $logPayload = array_merge([
            'conversation_id' => $room->id,
            'bot_id' => $bot->id,
            'attempt_number' => 0,
            'attempt_count' => 0,
            'message_mode' => 'ai',
            'delivery_status' => $get('log_delivery_status', 'pending'),
            'silence_user_message_id' => $anchorId,
            'observed_last_message_id' => $anchorId,
            'customer_key' => hash('sha256', $bot->id.'|line|'.$room->external_customer_id),
            'payment_step' => false,
            'transfer_summary_sent' => false,
            'group_code' => $get('group_code', 'G3'),
            'group_confidence' => $get('group_confidence', 0.86),
            'context_redacted' => is_string($context) ? $context : json_encode($context, JSON_UNESCAPED_UNICODE),
            'check_answers' => $get('log_check_answers', $get('check_answers', self::CLEAN_CHECK)),
            'check_overridden' => false,
            'draft' => $get('draft', self::DRAFT_CLEAN),
            'drafted_at' => '2026-11-15 09:30:00',
            'draft_day' => '2026-11-15',
            'draft_model' => 'openai/gpt-6-luna',
            'created_at' => '2026-11-15 09:35:00',
            'updated_at' => '2026-11-15 09:35:00',
        ]);
        unset($logPayload['id']);
        if ($logForcedId !== null) {
            DB::table('lead_recovery_logs')->insert($logPayload + ['id' => $logForcedId]);
            $logId = (int) $logForcedId;
        } else {
            $logId = DB::table('lead_recovery_logs')->insertGetId($logPayload);
        }

        $approvalPayload = array_merge([
            'lead_recovery_log_id' => $logId,
            'status' => $get('approval_status', 'pending'),
            'revision' => $get('revision', 1),
            'nonce' => self::NONCE,
            'text' => $get('draft', self::DRAFT_CLEAN),
            'check_answers' => $get('check_answers', self::CLEAN_CHECK),
            'check_overridden' => false,
            'expires_at' => $get('expires_at', '2026-11-16 12:00:00'),
            'telegram_delivery_state' => $get('telegram_delivery_state', 'new'),
            'telegram_plugin_id' => $get('telegram_plugin_id', null),
            'telegram_chat_id' => $get('telegram_chat_id', null),
            'telegram_message_id' => $get('telegram_message_id', null),
            'created_at' => self::NOW,
            'updated_at' => $get('approval_updated_at', self::NOW),
        ]);
        $approvalForcedId = $get('approval_id', null);
        if ($approvalForcedId !== null) {
            DB::table('follow_up_approvals')->insert($approvalPayload + ['id' => $approvalForcedId]);
            $approvalId = (int) $approvalForcedId;
        } else {
            $approvalId = DB::table('follow_up_approvals')->insertGetId($approvalPayload);
        }

        $this->bumpPgSequences();

        return [
            'user' => $user,
            'bot' => $bot->refresh(),
            'flow' => $flow,
            'plugin' => $plugin,
            'room' => $room,
            'log_id' => (int) $logId,
            'approval_id' => (int) $approvalId,
        ];
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

    /** seed ฉบับ golden: id ตายตัวตามไฟล์คำ (บอท 26, ห้อง 1317, log 123) */
    private function seedGolden(string $groupCode, ?float $confidence, string $checkAnswers): array
    {
        return $this->seedPending([
            'bot_id' => 26,
            'conversation_id' => 1317,
            'log_id' => 123,
            'bot_name' => 'Line - Adsvance',
            'display_name' => 'สมชาย <ร้าน A&B>',
            'group_code' => $groupCode,
            'group_confidence' => $confidence,
            'check_answers' => $checkAnswers,
            'expires_at' => '2026-11-16 22:12:00',
        ]);
    }

    private function bumpPgSequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['bots', 'conversations', 'customer_profiles', 'lead_recovery_logs', 'follow_up_approvals'] as $table) {
            $max = DB::table($table)->max('id');
            // id ทดสอบขนาด 9223372036854775807 ห้าม setval ตาม — sequence ไม่ย้อนกลับตาม
            // transaction พอทับแล้ว nextval ทุกแถวถัดไปตายทั้งชุด ให้เว้นไว้
            if ($max === null || $max >= 9223372036854775807) {
                continue;
            }
            $sequence = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS seq", [$table])->seq;
            if ($sequence !== null) {
                DB::statement('SELECT setval(?, ?)', [$sequence, $max]);
            }
        }
    }

    private function approvalRow(int $approvalId): array
    {
        return (array) DB::table('follow_up_approvals')->where('id', $approvalId)->first();
    }

    private function logRow(int $logId): array
    {
        return (array) DB::table('lead_recovery_logs')->where('id', $logId)->first();
    }

    private function messageCount(int $conversationId): int
    {
        return DB::table('messages')->where('conversation_id', $conversationId)->count();
    }

    /** เทียบทั้งแถว before/after โดยอนุญาตให้เปลี่ยนเฉพาะคอลัมน์ที่ตั้งชื่อ */
    private function assertRowChangedOnly(array $before, array $after, array $changedKeys): void
    {
        foreach ($before as $key => $value) {
            if (in_array($key, $changedKeys, true)) {
                continue;
            }
            self::assertSame($value, $after[$key], 'คอลัมน์ '.$key.' ต้องไม่เปลี่ยน');
        }
    }

    private function publish(int $approvalId): string
    {
        return app(FollowUpTelegramService::class)->publish($approvalId, $this->now());
    }

    /** publish หนึ่งครั้งใต้ fake คืน [ผลลัพธ์, จำนวน request] */
    private function publishCounting(int $approvalId, \Closure $stub): array
    {
        [$result, $requests] = $this->publishCapturing($approvalId, $stub);

        return [$result, count($requests)];
    }

    private function publishCapturing(int $approvalId, \Closure $stub): array
    {
        $requests = [];
        $this->resetLogCaptures();
        Http::fake(function ($request) use (&$requests, $stub) {
            $requests[] = $request;

            return $stub($request);
        });

        $result = $this->publish($approvalId);
        $this->assertNoSecretsInLog();

        return [$result, $requests];
    }

    // ------------------------------------------------- 1) ตารางการขนส่ง (transport)

    public static function transportCases(): array
    {
        return [
            'ok accepted 77' => ['ok_accepted'],
            'message_id as string 77' => ['mid_string'],
            'message_id as 0' => ['mid_zero'],
            'result true' => ['result_true'],
            'result missing' => ['result_missing'],
            'result empty list' => ['result_empty'],
            '200 ok false' => ['ok_false'],
            '200 html body' => ['html_200'],
            '400 ok false json' => ['rejected_400'],
            '401 ok false json' => ['rejected_401'],
            '403 ok false json' => ['rejected_403'],
            '429 retry_after 5' => ['rejected_429_ra5'],
            '429 retry_after string 5' => ['rejected_429_ra_string'],
            '429 without retry_after' => ['rejected_429_no_ra'],
            '400 html body' => ['html_400'],
            '302 with location' => ['redirect_302'],
            '500' => ['server_500'],
            '502' => ['server_502'],
            'thrown connection timeout' => ['throw_timeout'],
        ];
    }

    #[Test]
    #[DataProvider('transportCases')]
    public function transport_row(string $case): void
    {
        $bodyCanary = 'description '.self::BODY_CANARY;

        $stub = match ($case) {
            'ok_accepted' => fn () => Http::response(json_encode(['ok' => true, 'result' => ['message_id' => 77]]), 200),
            'mid_string' => fn () => Http::response(json_encode(['ok' => true, 'result' => ['message_id' => '77']]), 200),
            'mid_zero' => fn () => Http::response(json_encode(['ok' => true, 'result' => ['message_id' => 0]]), 200),
            'result_true' => fn () => Http::response(json_encode(['ok' => true, 'result' => true]), 200),
            'result_missing' => fn () => Http::response(json_encode(['ok' => true]), 200),
            'result_empty' => fn () => Http::response(json_encode(['ok' => true, 'result' => []]), 200),
            'ok_false' => fn () => Http::response(json_encode(['ok' => false]), 200),
            'html_200' => fn () => Http::response('<html>'.self::BODY_CANARY.'</html>', 200),
            'rejected_400' => fn () => Http::response(json_encode(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found '.$bodyCanary]), 400),
            'rejected_401' => fn () => Http::response(json_encode(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized '.$bodyCanary]), 401),
            'rejected_403' => fn () => Http::response(json_encode(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden '.$bodyCanary]), 403),
            'rejected_429_ra5' => fn () => Http::response(json_encode(['ok' => false, 'error_code' => 429, 'description' => $bodyCanary, 'parameters' => ['retry_after' => 5]]), 429),
            'rejected_429_ra_string' => fn () => Http::response(json_encode(['ok' => false, 'error_code' => 429, 'description' => $bodyCanary, 'parameters' => ['retry_after' => '5']]), 429),
            'rejected_429_no_ra' => fn () => Http::response(json_encode(['ok' => false, 'error_code' => 429, 'description' => $bodyCanary]), 429),
            'html_400' => fn () => Http::response('<html>'.self::BODY_CANARY.'</html>', 400),
            'redirect_302' => fn () => Http::response('', 302, ['Location' => 'https://t.me/redirect-target-'.self::BODY_CANARY]),
            'server_500' => fn () => Http::response('<html>'.self::BODY_CANARY.'</html>', 500),
            'server_502' => fn () => Http::response('<html>'.self::BODY_CANARY.'</html>', 502),
            'throw_timeout' => fn () => throw new ConnectionException('cURL error 28 timeout '.$this->fakeToken()),
        };

        [$result, $requests] = $this->transportCall($stub);

        [$outcome, $messageId, $status, $retryAfter] = match ($case) {
            'ok_accepted' => ['accepted', 77, 200, null],
            'mid_string', 'mid_zero', 'result_true', 'result_missing', 'result_empty' => ['unknown', null, 200, null],
            'ok_false', 'html_200' => ['unknown', null, 200, null],
            'rejected_400' => ['rejected', null, 400, null],
            'rejected_401' => ['rejected', null, 401, null],
            'rejected_403' => ['rejected', null, 403, null],
            'rejected_429_ra5' => ['rejected', null, 429, 5],
            'rejected_429_ra_string' => ['rejected', null, 429, null],
            'rejected_429_no_ra' => ['rejected', null, 429, null],
            'html_400' => ['unknown', null, 400, null],
            'redirect_302' => ['unknown', null, 302, null],
            'server_500' => ['unknown', null, 500, null],
            'server_502' => ['unknown', null, 502, null],
            'throw_timeout' => ['unknown', null, null, null],
        };

        $this->assertOutcome([
            'outcome' => $outcome,
            'message_id' => $messageId,
            'status' => $status,
            'retry_after' => $retryAfter,
        ], $result);

        self::assertCount(1, $requests, $case.' ต้องยิงครั้งเดียว');
        $this->assertSingleSendMessageRequest($requests[0]);
    }

    public static function emptyArgumentCases(): array
    {
        return [
            'empty token' => ['token'],
            'empty chat id' => ['chat'],
            'empty text' => ['text'],
            'empty keyboard' => ['keyboard'],
        ];
    }

    #[Test]
    #[DataProvider('emptyArgumentCases')]
    public function transport_empty_argument_makes_no_request(string $which): void
    {
        $requests = [];
        $this->resetLogCaptures();
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return $this->okStub()($request);
        });

        $result = (new TelegramAlertBotService)->sendFollowUpCard(
            match ($which) {
                'token' => '', default => $this->fakeToken()
            },
            match ($which) {
                'chat' => '', default => self::CHAT_ID
            },
            match ($which) {
                'text' => '', default => 'การ์ดทดสอบ'
            },
            match ($which) {
                'keyboard' => [], default => [['text' => 'ส่ง', 'callback_data' => 'fs|1|1|'.self::NONCE]]
            },
        );

        $this->assertOutcome([
            'outcome' => 'rejected',
            'message_id' => null,
            'status' => null,
            'retry_after' => null,
        ], $result);
        self::assertCount(0, $requests);
        $this->assertNoSecretsInLog();
    }

    #[Test]
    public function transport_real_socket_to_closed_port_is_unknown_without_leaking(): void
    {
        $port = 55000 + (getmypid() % 900);
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1.0);
        if (is_resource($sock)) {
            fclose($sock);
            self::markTestSkipped('port '.$port.' ไม่ปิด ข้ามกรณีนี้');
        }

        $base = 'http://127.0.0.1:'.$port.'/bot';
        $service = new class($base) extends TelegramAlertBotService
        {
            public function __construct(private readonly string $forcedBase) {}

            protected function followUpBase(): string
            {
                return $this->forcedBase;
            }
        };

        $this->resetLogCaptures();

        $result = $service->sendFollowUpCard(
            $this->fakeToken(),
            self::CHAT_ID,
            'การ์ดทดสอบ '.self::HTML_CANARY,
            [['text' => 'ส่ง', 'callback_data' => 'fs|1|1|'.self::NONCE]],
        );

        $this->assertOutcome([
            'outcome' => 'unknown',
            'message_id' => null,
            'status' => null,
            'retry_after' => null,
        ], $result);
        $this->assertNoBotMarkerInLog();
        $this->assertNoSecretsInLog();
    }

    // ------------------------------------------------- 2) ตารางสถานะของ publish

    public static function stateTableCases(): array
    {
        return [
            'state sent -> already_sent' => [['telegram_delivery_state' => 'sent'], 'already_sent', 0, []],
            'state failed -> failed' => [['telegram_delivery_state' => 'failed'], 'failed', 0, []],
            'state unknown -> unknown' => [['telegram_delivery_state' => 'unknown'], 'unknown', 0, []],
            'sending at 119s -> busy' => [['telegram_delivery_state' => 'sending', 'approval_updated_at' => '2026-11-16 09:58:01'], 'busy', 0, []],
            'sending at 120s -> unknown' => [['telegram_delivery_state' => 'sending', 'approval_updated_at' => '2026-11-16 09:58:00'], 'unknown', 0, ['telegram_delivery_state', 'updated_at']],
            'status editing -> blocked' => [['approval_status' => 'editing'], 'blocked', 0, []],
            'status approved -> blocked' => [['approval_status' => 'approved'], 'blocked', 0, []],
            'status sending -> blocked' => [['approval_status' => 'sending'], 'blocked', 0, []],
            'status sent -> blocked' => [['approval_status' => 'sent'], 'blocked', 0, []],
            'status skipped -> blocked' => [['approval_status' => 'skipped'], 'blocked', 0, []],
            'status expired -> blocked' => [['approval_status' => 'expired'], 'blocked', 0, []],
            'status cancelled -> blocked' => [['approval_status' => 'cancelled'], 'blocked', 0, []],
            'log approved -> blocked' => [['log_delivery_status' => 'approved'], 'blocked', 0, []],
            'log cancelled -> blocked' => [['log_delivery_status' => 'cancelled'], 'blocked', 0, []],
            'expires at now -> blocked' => [['expires_at' => self::NOW], 'blocked', 0, []],
        ];
    }

    #[Test]
    #[DataProvider('stateTableCases')]
    public function publish_state_row(array $overrides, string $expectedResult, int $expectedRequests, array $changedKeys): void
    {
        $seed = $this->seedPending($overrides);
        $approvalBefore = $this->approvalRow($seed['approval_id']);
        $logBefore = $this->logRow($seed['log_id']);
        $messagesBefore = $this->messageCount($seed['room']->id);

        [$result, $requests] = $this->publishCounting($seed['approval_id'], $this->okStub());

        self::assertSame($expectedResult, $result);
        self::assertSame($expectedRequests, $requests);

        $approvalAfter = $this->approvalRow($seed['approval_id']);
        $this->assertRowChangedOnly($approvalBefore, $approvalAfter, $changedKeys);
        if ($changedKeys !== []) {
            self::assertSame('unknown', $approvalAfter['telegram_delivery_state']);
            self::assertSame(self::NOW, $approvalAfter['updated_at']);
        }

        $logAfter = $this->logRow($seed['log_id']);
        $this->assertRowChangedOnly($logBefore, $logAfter, []);
        self::assertSame($messagesBefore, $this->messageCount($seed['room']->id));
    }

    #[Test]
    public function publish_expires_one_second_after_now_is_published(): void
    {
        $seed = $this->seedPending(['expires_at' => '2026-11-16 10:00:01']);

        [$result, $requests] = $this->publishCounting($seed['approval_id'], $this->okStub());

        self::assertSame('sent', $result);
        self::assertSame(1, $requests);
        self::assertSame('sent', $this->approvalRow($seed['approval_id'])['telegram_delivery_state']);
    }

    #[Test]
    public function publish_blocked_when_plugin_disabled(): void
    {
        $seed = $this->seedPending(['plugin_enabled' => false]);
        $before = $this->approvalRow($seed['approval_id']);

        [$result, $requests] = $this->publishCounting($seed['approval_id'], $this->okStub());

        self::assertSame('blocked', $result);
        self::assertSame(0, $requests);
        $this->assertRowChangedOnly($before, $this->approvalRow($seed['approval_id']), []);
    }

    #[Test]
    public function publish_blocked_when_allowlist_missing(): void
    {
        $seed = $this->seedPending(['allowlist_missing' => true]);
        $before = $this->approvalRow($seed['approval_id']);

        [$result, $requests] = $this->publishCounting($seed['approval_id'], $this->okStub());

        self::assertSame('blocked', $result);
        self::assertSame(0, $requests);
        $this->assertRowChangedOnly($before, $this->approvalRow($seed['approval_id']), []);
    }

    #[Test]
    public function publish_blocked_when_admin_web_url_null(): void
    {
        $seed = $this->seedPending(['admin_web_url' => null]);
        $before = $this->approvalRow($seed['approval_id']);

        config(['customer_follow_up.admin_web_url' => null]);
        [$result, $requests] = $this->publishCounting($seed['approval_id'], $this->okStub());

        self::assertSame('blocked', $result);
        self::assertSame(0, $requests);
        $this->assertRowChangedOnly($before, $this->approvalRow($seed['approval_id']), []);
    }

    #[Test]
    public function publish_missing_approval_is_blocked(): void
    {
        $this->resetLogCaptures();
        Http::fake(fn () => Http::response('{}', 200));

        self::assertSame('blocked', $this->publish(424242));
        $this->assertNoSecretsInLog();
    }

    // ------------------------------------------------- 3) ผลลัพธ์ของการส่ง

    #[Test]
    public function publish_rejected_marks_failed_and_never_repeats(): void
    {
        $seed = $this->seedPending();
        $stub = fn () => Http::response(json_encode(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found '.self::BODY_CANARY]), 400);

        [$result, $requests] = $this->publishCounting($seed['approval_id'], $stub);
        self::assertSame('failed', $result);
        self::assertSame(1, $requests);

        $row = $this->approvalRow($seed['approval_id']);
        self::assertSame('failed', $row['telegram_delivery_state']);
        self::assertNull($row['telegram_message_id']);
        self::assertSame('pending', $row['status']);

        $again = $this->publishCounting($seed['approval_id'], $stub);
        self::assertSame('failed', $again[0]);
        self::assertSame(0, $again[1]);
    }

    #[Test]
    public function publish_unknown_timeout_marks_unknown_and_never_repeats(): void
    {
        $seed = $this->seedPending();
        $stub = fn () => throw new ConnectionException('cURL error 28 timeout '.$this->fakeToken());

        [$result, $requests] = $this->publishCounting($seed['approval_id'], $stub);
        self::assertSame('unknown', $result);
        self::assertSame(1, $requests);

        $row = $this->approvalRow($seed['approval_id']);
        self::assertSame('unknown', $row['telegram_delivery_state']);
        self::assertNull($row['telegram_message_id']);
        self::assertSame('pending', $row['status']);

        $again = $this->publishCounting($seed['approval_id'], $stub);
        self::assertSame('unknown', $again[0]);
        self::assertSame(0, $again[1]);
    }

    #[Test]
    public function publish_is_called_twice_but_requests_once(): void
    {
        $seed = $this->seedPending();
        [$first, $second, $third, $requests] = $this->publishThreeTimes($seed['approval_id']);

        self::assertSame('sent', $first);
        self::assertSame('already_sent', $second);
        self::assertSame('already_sent', $third);
        self::assertSame(1, $requests);
    }

    private function publishThreeTimes(int $approvalId): array
    {
        $requests = 0;
        $this->resetLogCaptures();
        Http::fake(function ($request) use (&$requests) {
            $requests++;

            return $this->okStub()($request);
        });

        $results = [$this->publish($approvalId), $this->publish($approvalId), $this->publish($approvalId)];
        $this->assertNoSecretsInLog();
        $results[] = $requests;

        return $results;
    }

    #[Test]
    public function publish_lost_claim_returns_blocked_and_keeps_other_writers_values(): void
    {
        $seed = $this->seedPending();
        $this->resetLogCaptures();
        Http::fake(function ($request) use ($seed) {
            // นักเขียนคนอื่นยึดสถานะระหว่าง claim กับ final write
            DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update([
                'telegram_delivery_state' => 'sent',
                'telegram_message_id' => 888,
                'telegram_chat_id' => 'other-chat',
                'telegram_plugin_id' => $seed['plugin']->id,
                'updated_at' => '2026-11-16 09:00:00',
            ]);

            return $this->okStub()($request);
        });

        $result = $this->publish($seed['approval_id']);

        self::assertSame('blocked', $result);
        $row = $this->approvalRow($seed['approval_id']);
        self::assertSame('sent', $row['telegram_delivery_state']);
        self::assertSame(888, (int) $row['telegram_message_id']);
        self::assertSame('other-chat', $row['telegram_chat_id']);
        self::assertSame('2026-11-16 09:00:00', $row['updated_at']);
        $this->assertNoSecretsInLog();
    }

    #[Test]
    public function publish_claim_refused_by_racing_writer_is_busy_and_sends_nothing(): void
    {
        $seed = $this->seedPending();
        $this->resetLogCaptures();
        $interfered = false;
        DB::listen(function ($query) use ($seed, &$interfered): void {
            if ($interfered || ! str_contains(strtolower($query->sql), 'follow_up_approvals')) {
                return;
            }
            $interfered = true;
            // นักเขียนคนอื่นยึดแถวระหว่างที่ publisher อ่านแถวกับยิง claim UPDATE
            DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update([
                'telegram_delivery_state' => 'sending',
                'telegram_chat_id' => 'racer-chat',
                'telegram_plugin_id' => $seed['plugin']->id,
                'updated_at' => '2026-11-16 09:59:30',
            ]);
        });
        [$result, $requests] = $this->publishCapturing($seed['approval_id'], $this->okStub());

        self::assertSame('busy', $result);
        self::assertCount(0, $requests);
        $row = $this->approvalRow($seed['approval_id']);
        self::assertSame('sending', $row['telegram_delivery_state']);
        self::assertSame('racer-chat', $row['telegram_chat_id']);
        self::assertSame('2026-11-16 09:59:30', $row['updated_at']);
        $this->assertNoSecretsInLog();
    }

    // ------------------------------------------------- 4) happy path + คอลัมน์ที่เขียนได้

    #[Test]
    public function publish_happy_path_writes_only_telegram_columns(): void
    {
        $seed = $this->seedPending();
        $approvalBefore = $this->approvalRow($seed['approval_id']);
        $logBefore = $this->logRow($seed['log_id']);
        $messagesBefore = $this->messageCount($seed['room']->id);

        [$result, $requests] = $this->publishCapturing($seed['approval_id'], $this->okStub());

        self::assertSame('sent', $result);
        self::assertCount(1, $requests);

        $row = $this->approvalRow($seed['approval_id']);
        self::assertSame('sent', $row['telegram_delivery_state']);
        self::assertSame(77, (int) $row['telegram_message_id']);
        self::assertSame((int) $seed['plugin']->id, (int) $row['telegram_plugin_id']);
        self::assertSame('999', $row['telegram_chat_id']);
        self::assertSame(self::NOW, $row['updated_at']);

        $this->assertRowChangedOnly($approvalBefore, $row, [
            'telegram_delivery_state',
            'telegram_message_id',
            'telegram_plugin_id',
            'telegram_chat_id',
            'updated_at',
        ]);
        $this->assertRowChangedOnly($logBefore, $this->logRow($seed['log_id']), []);
        self::assertSame($messagesBefore, $this->messageCount($seed['room']->id));

        self::assertSame('https://api.telegram.org/bot'.'tg-plugin-token-'.self::TOKEN_TAIL.'/sendMessage', (string) $requests[0]->url());
        self::assertSame('999', $requests[0]['chat_id']);
        self::assertSame('HTML', $requests[0]['parse_mode']);
        self::assertSame(['is_disabled' => true], json_decode($requests[0]['link_preview_options'], true));
        self::assertStringContainsString('ลูกค้าพิมพ์ล่าสุด: ขอคิดดูก่อนนะครับ'."\n", $requests[0]['text']);
    }

    // ------------------------------------------------- 5) ข้อความ golden

    #[Test]
    public function golden_clean_text_is_sent_verbatim(): void
    {
        $seed = $this->seedGolden('G3', 0.86, self::CLEAN_CHECK);
        [$result, $requests] = $this->publishCapturing($seed['approval_id'], $this->okStub());

        self::assertSame('sent', $result);
        self::assertCount(1, $requests);
        self::assertSame(self::GOLDEN_CLEAN_TEXT, $requests[0]['text']);
    }

    #[Test]
    public function golden_flagged_text_is_sent_verbatim(): void
    {
        $flaggedCheck = '{"scores":{"promise":0.9,"price_stock":0.7},"failed":["promise","price_stock"],"error":false,"version":1}';
        $seed = $this->seedGolden('G2', null, $flaggedCheck);
        [$result, $requests] = $this->publishCapturing($seed['approval_id'], $this->okStub());

        self::assertSame('sent', $result);
        self::assertCount(1, $requests);
        self::assertSame(self::GOLDEN_FLAGGED_TEXT, $requests[0]['text']);
    }

    // ------------------------------------------------- 6) ปุ่มกด

    #[Test]
    public function keyboard_has_three_buttons_in_file_order(): void
    {
        $seed = $this->seedPending();
        [, $requests] = $this->publishCapturing($seed['approval_id'], $this->okStub());

        $keyboard = json_decode($requests[0]['reply_markup'], true);
        self::assertSame(
            [
                [
                    ['text' => 'ส่ง', 'callback_data' => 'fs|'.$seed['approval_id'].'|1|'.self::NONCE],
                    ['text' => 'ข้าม', 'callback_data' => 'fk|'.$seed['approval_id'].'|1|'.self::NONCE],
                    ['text' => 'แก้ข้อความ', 'callback_data' => 'fe|'.$seed['approval_id'].'|1|'.self::NONCE],
                ],
            ],
            $keyboard['inline_keyboard'],
        );
    }

    #[Test]
    public function keyboard_callback_data_stays_within_64_bytes_with_max_ids(): void
    {
        $seed = $this->seedPending(['approval_id' => 9223372036854775807, 'revision' => 2147483647]);
        [, $requests] = $this->publishCapturing($seed['approval_id'], $this->okStub());

        $row = json_decode($requests[0]['reply_markup'], true)['inline_keyboard'][0];
        self::assertCount(3, $row);
        foreach ($row as $button) {
            self::assertLessThanOrEqual(64, strlen($button['callback_data']), $button['callback_data']);
        }
        self::assertSame('fs|9223372036854775807|2147483647|'.self::NONCE, $row[0]['callback_data']);
        self::assertSame('fk|9223372036854775807|2147483647|'.self::NONCE, $row[1]['callback_data']);
        self::assertSame('fe|9223372036854775807|2147483647|'.self::NONCE, $row[2]['callback_data']);
    }

    // ------------------------------------------------- 7) การ escape

    #[Test]
    public function hostile_values_cannot_add_markup(): void
    {
        $hostile = '<b>x</b> & "q" <a href="https://evil.test">l</a>';
        $seed = $this->seedPending([
            'bot_name' => $hostile,
            'display_name' => $hostile,
            'context_redacted' => ['C: '.$hostile, 'B: '.$hostile],
            'draft' => $hostile,
        ]);

        [, $requests] = $this->publishCapturing($seed['approval_id'], $this->okStub());
        $text = $requests[0]['text'];

        $withoutWordingTags = str_replace(['<b>ตามลูกค้าที่เงียบไป</b>', '<b>ร่างข้อความ</b>'], '', $text);
        self::assertStringNotContainsString('<', $withoutWordingTags);

        $decoded = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        self::assertStringContainsString($hostile, $decoded);
        // ชื่อลูกค้าผ่าน redactor ก่อน (D73) — URL โดนแทนก่อน escape จึงไม่อ่านคืนแบบดิบได้
        $redactedName = trim((string) preg_replace('/\s+/u', ' ', FollowUpRedactor::redact($hostile)));
        self::assertStringContainsString($redactedName, $decoded);
    }

    // ------------------------------------------------- 8) กฎรายฟิลด์

    public static function customerNameCases(): array
    {
        return [
            'profile missing' => ['__null__', 'ลูกค้า #{conversation}'],
            'empty name' => ['', 'ลูกค้า #{conversation}'],
            'spaces only' => ['   ', 'ลูกค้า #{conversation}'],
            '41 code points' => [str_repeat('ก', 41), 'ลูกค้า: '.str_repeat('ก', 40)],
            'name with url' => ['ดูที่ https://evil.test/token', 'ลูกค้า: ดูที่ [redacted:url]'],
        ];
    }

    #[Test]
    #[DataProvider('customerNameCases')]
    public function customer_name_rules(string $displayName, string $expectedLine): void
    {
        $options = $displayName === '__null__'
            ? ['profile_null' => true]
            : ['display_name' => $displayName];
        $seed = $this->seedPending($options);

        [, $requests] = $this->publishCapturing($seed['approval_id'], $this->okStub());

        $expected = str_replace('{conversation}', (string) $seed['room']->id, $expectedLine);
        self::assertStringContainsString($expected."\n", $requests[0]['text']);
    }

    public static function groupCases(): array
    {
        return [
            'G2 with 86' => ['G2', 0.86, 'กลุ่ม: ค้างที่ขั้นจ่ายเงิน · Luna มั่นใจ 86%'],
            'G3 no confidence' => ['G3', null, 'กลุ่ม: สนใจแต่ยังลังเล'],
            'null code with 86' => [null, 0.86, 'กลุ่ม: ไม่ระบุ · Luna มั่นใจ 86%'],
            'confidence 0.2900' => ['G3', 0.29, 'กลุ่ม: สนใจแต่ยังลังเล · Luna มั่นใจ 29%'],
            'confidence 0.7999' => ['G3', 0.7999, 'กลุ่ม: สนใจแต่ยังลังเล · Luna มั่นใจ 79%'],
            'confidence 0.8000' => ['G3', 0.8, 'กลุ่ม: สนใจแต่ยังลังเล · Luna มั่นใจ 80%'],
            'confidence 1.0000' => ['G3', 1.0, 'กลุ่ม: สนใจแต่ยังลังเล · Luna มั่นใจ 100%'],
        ];
    }

    #[Test]
    #[DataProvider('groupCases')]
    public function group_label_and_confidence_rules(?string $groupCode, ?float $confidence, string $expectedLine): void
    {
        $seed = $this->seedPending(['group_code' => $groupCode, 'group_confidence' => $confidence]);

        [, $requests] = $this->publishCapturing($seed['approval_id'], $this->okStub());

        self::assertStringContainsString($expectedLine."\n", $requests[0]['text']);
    }

    public static function previewCases(): array
    {
        return [
            'no C line -> dash' => [['B: ทักมาได้เลยครับ'], 'ลูกค้าพิมพ์ล่าสุด: -'],
            'no B line -> dash' => [['C: ขอคิดดูก่อนนะครับ'], 'บอทตอบล่าสุด: -'],
            'non-text image' => [['C: [non-text:image]'], 'ลูกค้าพิมพ์ล่าสุด: (ไม่ใช่ข้อความ)'],
            'non-text sticker' => [['B: [non-text:sticker]'], 'บอทตอบล่าสุด: (ไม่ใช่ข้อความ)'],
            'exactly 120 -> no cut mark' => [['C: '.str_repeat('a', 120)], 'ลูกค้าพิมพ์ล่าสุด: '.str_repeat('a', 120)],
            '121 -> cut at 120 with mark' => [['C: '.str_repeat('a', 121)], 'ลูกค้าพิมพ์ล่าสุด: '.str_repeat('a', 120).'…'],
            'newline collapsed' => [["C: บรรทัดแรก\nบรรทัดสอง"], 'ลูกค้าพิมพ์ล่าสุด: บรรทัดแรก บรรทัดสอง'],
            'context null -> dash' => [null, 'ลูกค้าพิมพ์ล่าสุด: -'],
            'context string x -> dash' => ['"x"', 'ลูกค้าพิมพ์ล่าสุด: -'],
            'context object -> dash' => ['{}', 'ลูกค้าพิมพ์ล่าสุด: -'],
            'context list with number -> dash' => ['[5]', 'ลูกค้าพิมพ์ล่าสุด: -'],
        ];
    }

    #[Test]
    #[DataProvider('previewCases')]
    public function preview_rules($context, string $expectedLine): void
    {
        $seed = $this->seedPending(['context_redacted' => $context]);

        [, $requests] = $this->publishCapturing($seed['approval_id'], $this->okStub());

        self::assertStringContainsString($expectedLine."\n", $requests[0]['text']);
    }

    #[Test]
    public function last_matching_context_element_wins(): void
    {
        $seed = $this->seedPending(['context_redacted' => ['C: รอบแรก', 'B: ตอบรอบแรก', 'C: รอบหลัง', 'B: ตอบรอบหลัง']]);

        [, $requests] = $this->publishCapturing($seed['approval_id'], $this->okStub());

        self::assertStringContainsString('ลูกค้าพิมพ์ล่าสุด: รอบหลัง'."\n", $requests[0]['text']);
        self::assertStringContainsString('บอทตอบล่าสุด: ตอบรอบหลัง'."\n", $requests[0]['text']);
    }

    #[Test]
    public function expiry_line_uses_stored_digits(): void
    {
        $seed = $this->seedPending(['expires_at' => '2026-12-05 08:07:00']);

        [, $requests] = $this->publishCapturing($seed['approval_id'], $this->okStub());

        self::assertStringContainsString('หมดอายุ: 05/12 08:07 (12 ชั่วโมง)'."\n", $requests[0]['text']);
    }

    #[Test]
    public function draft_of_400_code_points_on_three_lines_is_sent_whole(): void
    {
        $draft = str_repeat('ก', 134)."\n".str_repeat('ข', 132)."\n".str_repeat('ค', 132);
        self::assertSame(400, mb_strlen($draft, 'UTF-8'));
        $seed = $this->seedPending(['draft' => $draft]);

        [, $requests] = $this->publishCapturing($seed['approval_id'], $this->okStub());

        self::assertStringContainsString("\n".str_repeat('ก', 134)."\n".str_repeat('ข', 132)."\n".str_repeat('ค', 132)."\n", $requests[0]['text']);
        self::assertLessThanOrEqual(4096, mb_strlen($requests[0]['text'], 'UTF-8'));
    }

    // ------------------------------------------------- 9) บรรทัดตรวจข้อความตรงกับฝั่งส่ง

    public static function checkShapes(): array
    {
        return [
            'sql null' => [null, '⚠️ ตรวจไม่ได้', true],
            'json null' => ['null', '⚠️ ตรวจไม่ได้', true],
            'empty list' => ['[]', '⚠️ ตรวจไม่ได้', true],
            'empty object' => ['{}', '⚠️ ตรวจไม่ได้', true],
            'failed as string' => ['{"failed":"oops","error":false}', '⚠️ ตรวจไม่ได้', true],
            'failed missing' => ['{"error":false}', '⚠️ ตรวจไม่ได้', true],
            'error null' => ['{"failed":[],"error":null}', '⚠️ ตรวจไม่ได้', true],
            'error 0' => ['{"failed":[],"error":0}', '⚠️ ตรวจไม่ได้', true],
            'error empty string' => ['{"failed":[],"error":""}', '⚠️ ตรวจไม่ได้', true],
            'error string false' => ['{"failed":[],"error":"false"}', '⚠️ ตรวจไม่ได้', true],
            'error true' => ['{"failed":[],"error":true}', '⚠️ ตรวจไม่ได้', true],
            'failed object' => ['{"failed":{},"error":false}', '⚠️ ตรวจไม่ได้', true],
            'clean' => [self::CLEAN_CHECK, '✅ ผ่าน', false],
            'one known key' => ['{"failed":["pressure"],"error":false}', '⚠️ เตือน: เร่งหรือกดดันลูกค้า', true],
            'two known keys file order' => ['{"failed":["price_stock","off_topic"],"error":false}', '⚠️ เตือน: พูดถึงราคาหรือสต็อก · ไม่ตรงเรื่องที่คุยค้างไว้', true],
            'one unknown key' => ['{"failed":["mystery"],"error":false}', '⚠️ ตรวจไม่ได้', true],
            'known plus unknown' => ['{"failed":["support_free","zzz"],"error":false}', '⚠️ เตือน: บอกว่า Support ฟรี', true],
        ];
    }

    #[Test]
    #[DataProvider('checkShapes')]
    public function check_line_agrees_with_sender(?string $checkAnswers, string $expectedResult, bool $expectedFlag): void
    {
        self::assertSame($expectedFlag, FollowUpSendService::checkFlagged($checkAnswers ?? ''));

        $seed = $this->seedPending(['check_answers' => $checkAnswers]);
        [, $requests] = $this->publishCapturing($seed['approval_id'], $this->okStub());

        self::assertStringContainsString('ตรวจข้อความ: '.$expectedResult."\n", $requests[0]['text']);
        if ($expectedFlag) {
            self::assertStringContainsString('เจ้านายยังกดส่งได้ แต่ข้อความนี้มีคำเตือน', $requests[0]['text']);
        } else {
            self::assertStringNotContainsString('เจ้านายยังกดส่งได้ แต่ข้อความนี้มีคำเตือน', $requests[0]['text']);
        }
    }
}
