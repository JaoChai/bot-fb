<?php

namespace Tests\Feature\FollowUp;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\User;
use App\Services\FollowUp\FollowUpApprovalService;
use App\Services\FollowUp\FollowUpSendService;
use App\Services\FollowUp\FollowUpTelegramService;
use App\Services\Payment\ManualPaymentConfirmService;
use App\Services\Payment\TelegramAlertBotService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด B6b: ปุ่มส่ง/ข้ามบนการ์ด Telegram ตามลูกค้า
 *
 * ครอบคลุม:
 * - การขนส่งใหม่ editFollowUpCard / answerFollowUpCallback (ตาราง outcome + port ปิด)
 * - การ route ใน TelegramAlertCallbackController (fs|fk|fe|fc ก่อน parser เดิม, โค้ดเดิมไม่แตะ service ใหม่)
 * - ตารางอัตลักษณ์ (identity) และการ adopt message id (D76)
 * - การเขียน Send/Skip หนึ่งธุรกรรม + gate (D78/D79)
 * - FollowUpTelegramService::refreshCard (บรรทัดสถานะทุกสถานะปลายทาง + golden)
 *
 * ไม่มีการเรียกจริงออกนอกเครื่อง ยกเว้น socket ไป port ปิดในเครื่องหนึ่งครั้งต่อ method ตามการ์ด
 */
class TelegramApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 10:00:00';

    private const TOKEN_HEAD = 'fakeApprovalToken';

    private const TOKEN_TAIL = '77';

    private const CHAT_ID = 'canary-chat-77';

    private const CB_ID = 'cb-canary-77';

    private const HTML_CANARY = 'htmlcanary-77';

    private const BODY_CANARY = 'bodycanary-77';

    private const PLUGIN_CHAT = '888';

    private const PERSON_ID = 555;

    private const UPDATE_ID = 999001;

    private const NONCE = 'abcd1234abcd1234';

    private const RECIPIENT = 'Ub6b000112233445566778899aabbccdd';

    private const CLEAN_CHECK = '{"scores":{"safe_to_send":0.9},"failed":[],"error":false,"version":1}';

    private const FLAGGED_CHECK = '{"scores":{"safe_to_send":0.9},"failed":["pressure"],"error":false,"version":1}';

    private const DRAFT = "สวัสดีครับพี่ เมื่อวานที่คุยเรื่องบัญชีโฆษณาไว้\nมีจุดไหนให้ช่วยดูเพิ่มไหมครับ";

    private const EXPIRES = '2026-11-16 22:12:00';

    /** golden texts จาก b6b-strings.json (sha256 9e1f610e…) — คัดลอกมาแบบคำต่อคำ */
    private const GOLDEN_AFTER_SEND = <<<'TEXT'
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

⏳ กดส่งแล้ว กำลังส่งให้ลูกค้า
TEXT;

    private const GOLDEN_AFTER_SKIP = <<<'TEXT'
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

⏭ ข้ามแล้ว ไม่ได้ส่ง
TEXT;

    private const GOLDEN_CANCELLED = <<<'TEXT'
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

🚫 ยกเลิก ไม่ได้ส่ง (ลูกค้าพิมพ์เข้ามาแล้ว)
TEXT;

    private const LINE_QUOTA_URL = 'https://api.line.me/v2/bot/message/quota';

    private const LINE_CONSUMPTION_URL = 'https://api.line.me/v2/bot/message/quota/consumption';

    private const LINE_PUSH_URL = 'https://api.line.me/v2/bot/message/push';

    private const LINE_REQUEST_ID = 'req-canary-77';

    /** @var list<MessageLogged> */
    private array $logEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logEvents = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logEvents[] = $event;
        });
        Queue::fake();
        Bus::fake();
        $this->freeze(self::NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function fakeToken(): string
    {
        return self::TOKEN_HEAD.self::TOKEN_TAIL;
    }

    private ?string $worldToken = null;

    private function pluginToken(): string
    {
        return 'tg-plugin-token-'.self::TOKEN_TAIL;
    }

    private function lineToken(): string
    {
        return 'line-token-'.self::TOKEN_TAIL;
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW, 'Asia/Bangkok');
    }

    private function freeze(string $when): void
    {
        Carbon::setTestNow(CarbonImmutable::parse($when, 'Asia/Bangkok'));
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
            self::assertStringNotContainsString($this->pluginToken(), $text, 'token ห้ามลง log');
            if ($this->worldToken !== null) {
                self::assertStringNotContainsString($this->worldToken, $text, 'token ห้ามลง log');
            }
            self::assertStringNotContainsString(self::CHAT_ID, $text, 'chat id ห้ามลง log');
            self::assertStringNotContainsString(self::PLUGIN_CHAT, $text, 'chat id ห้ามลง log');
            self::assertStringNotContainsString(self::CB_ID, $text, 'callback id ห้ามลง log');
            self::assertStringNotContainsString(self::HTML_CANARY, $text, 'ข้อความห้ามลง log');
            self::assertStringNotContainsString(self::BODY_CANARY, $text, 'response body ห้ามลง log');
            self::assertStringNotContainsString(self::NONCE, $text, 'nonce ห้ามลง log');
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

    private function cbData(string $code, int $approvalId, int $revision = 1, string $nonce = self::NONCE): string
    {
        return $code.'|'.$approvalId.'|'.$revision.'|'.$nonce;
    }

    private function baseCallback(array $overrides = [], ?string $data = null): array
    {
        return array_merge([
            'id' => self::CB_ID,
            'from' => ['id' => self::PERSON_ID, 'first_name' => 'เจ้านาย'],
            'message' => ['message_id' => 77, 'chat' => ['id' => 888]],
            'data' => $data,
        ], $overrides);
    }

    /**
     * ยิง callback ผ่าน route จริงใต้ Http::fake ที่จับทุก request
     * คืน [TestResponse, รายการ request]
     */
    private function tap(array $callback, ?string $pluginToken = null, ?string $secret = 'webhook-secret-test', ?\Closure $stub = null, ?int $updateId = self::UPDATE_ID): array
    {
        $requests = [];
        $this->resetLogCaptures();
        Http::fake(function ($request) use (&$requests, $stub) {
            $requests[] = $request;

            return $stub === null
                ? Http::response(json_encode(['ok' => true, 'result' => true]), 200)
                : $stub($request);
        });

        $body = ['callback_query' => $callback];
        if ($updateId !== null) {
            $body['update_id'] = $updateId;
        }

        $headers = [];
        if ($secret !== null) {
            $headers['X-Telegram-Bot-Api-Secret-Token'] = $secret;
        }

        $response = $this->withHeaders($headers)
            ->postJson('/api/webhook/telegram-alert/'.($pluginToken ?? $this->worldToken ?? $this->pluginToken()), $body);

        return [$response, $requests];
    }

    private function methodOf(object $request): string
    {
        $path = (string) $request->url();

        return substr($path, (int) strrpos($path, '/') + 1);
    }

    private function assertOnlyTelegramHost(array $requests): void
    {
        foreach ($requests as $request) {
            self::assertSame('api.telegram.org', (string) parse_url((string) $request->url(), PHP_URL_HOST), 'ห้ามยิงออกนอก Telegram');
        }
    }

    private function assertSingleAnswer(array $requests, string $text, bool $alert): void
    {
        $answers = array_values(array_filter($requests, fn ($r) => $this->methodOf($r) === 'answerCallbackQuery'));
        self::assertCount(1, $answers, 'ต้องตอบ callback ครั้งเดียว');
        self::assertSame(self::CB_ID, $answers[0]['callback_query_id']);
        self::assertSame($text, $answers[0]['text']);
        self::assertSame($alert, $answers[0]['show_alert'], 'show_alert ต้องเป็น JSON boolean ที่ถูกต้อง');
        self::assertLessThanOrEqual(200, mb_strlen($text, 'UTF-8'), 'คำตอบต้องไม่เกิน 200 ตัวอักษร');
    }

    private function assertSingleEdit(array $requests, ?string $expectedText = null): void
    {
        $edits = array_values(array_filter($requests, fn ($r) => $this->methodOf($r) === 'editMessageText'));
        self::assertCount(1, $edits, 'ต้องแก้การ์ดครั้งเดียว');
        self::assertSame('HTML', $edits[0]['parse_mode']);
        self::assertSame(json_encode(['inline_keyboard' => []]), $edits[0]['reply_markup'], 'ปุ่มต้องถูกลบ');
        self::assertSame(json_encode(['is_disabled' => true]), $edits[0]['link_preview_options']);
        if ($expectedText !== null) {
            self::assertSame($expectedText, $edits[0]['text']);
        }
    }

    private function approvalRow(int $id): ?object
    {
        return DB::table('follow_up_approvals')->where('id', $id)->first();
    }

    private function logRow(int $id): ?object
    {
        return DB::table('lead_recovery_logs')->where('id', $id)->first();
    }

    /** เทียบทั้งแถว before/after โดยอนุญาตให้เปลี่ยนเฉพาะคอลัมน์ที่ตั้งชื่อ */
    private function assertRowChangedOnly(object $before, object $after, array $changedKeys): void
    {
        foreach ((array) $before as $key => $value) {
            if (in_array($key, $changedKeys, true)) {
                continue;
            }
            self::assertSame($value, $after->{$key}, 'คอลัมน์ '.$key.' ต้องไม่เปลี่ยน');
        }
    }

    private function noRowChanged(object $beforeApproval, object $afterApproval, object $beforeLog, object $afterLog): void
    {
        $this->assertRowChangedOnly($beforeApproval, $afterApproval, []);
        $this->assertRowChangedOnly($beforeLog, $afterLog, []);
    }

    private function bumpPgSequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['bots', 'conversations', 'customer_profiles', 'lead_recovery_logs', 'follow_up_approvals'] as $table) {
            $max = DB::table($table)->max('id');
            if ($max === null || $max >= 9223372036854775807) {
                continue;
            }
            $sequence = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS seq", [$table])->seq;
            if ($sequence !== null) {
                DB::statement('SELECT setval(?, ?)', [$sequence, $max]);
            }
        }
    }

    /**
     * โลกทดสอบเดียวสำหรับทุกกรณี — ผ่าน gate ที่เวลา 10:00 (ข้อความลูกค้าเป็นข้อความสุดท้าย)
     * id คงที่แบบ golden: bot 26, ห้อง 1317, log 123
     *
     * @return array<string, mixed>
     */
    protected function seedWorld(array $options = []): array
    {
        $get = fn (string $key, mixed $default = null) => array_key_exists($key, $options) ? $options[$key] : $default;

        $user = User::factory()->owner()->create();
        $golden = (bool) $get('golden_ids', false);
        $botAttributes = [
            'user_id' => $user->id,
            'name' => $get('bot_name', 'Line - Adsvance'),
            'primary_chat_model' => 'openai/gpt-6-luna',
            'channel_access_token' => $this->lineToken(),
        ];
        if ($golden) {
            $botAttributes['id'] = 26;
        }
        $bot = Bot::factory()->active()->line()->create($botAttributes);
        config(['customer_follow_up.bot_ids' => $get('bot_ids', [$bot->id])]);
        config(['customer_follow_up.automatic_bot_ids' => []]);
        config(['customer_follow_up.admin_web_url' => 'https://admin.example.test']);
        config(['services.telegram_alert.secret' => 'webhook-secret-test']);
        config(['customer_follow_up.quota_reserve_messages' => 100]);

        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);

        $pluginConfig = [
            // token ต่อโลกทดสอบ — กัน route เจอ plugin ของ seed ก่อนหน้าเมื่อเทสต์หลายโลก
            'access_token' => $this->worldToken = $this->pluginToken().'-b'.$bot->id,
            'chat_id' => self::PLUGIN_CHAT,
        ];
        if (! $get('allowlist_missing', false)) {
            $pluginConfig['authorized_user_ids'] = $get('allowlist', [self::PERSON_ID]);
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
            'follow_up_enabled' => $get('follow_up_enabled', true),
            'follow_up_mode' => 'approve',
            'follow_up_started_at' => '2026-10-17 10:00:00',
            'follow_up_activation_message_id' => 0,
            'follow_up_activated_at' => '2026-10-17 10:00:00',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        $profile = CustomerProfile::factory()->create([
            'channel_type' => 'line',
            'display_name' => $get('display_name', 'สมชาย <ร้าน A&B>'),
        ]);
        $roomAttributes = [
            'bot_id' => $bot->id,
            'customer_profile_id' => $profile->id,
            'external_customer_id' => self::RECIPIENT,
            'is_handover' => $get('is_handover', false),
            'status' => $get('room_status', 'active'),
        ];
        if ($golden) {
            $roomAttributes['id'] = 1317;
        }
        $room = Conversation::factory()->line()->create($roomAttributes);

        // ข้อความลูกค้า (anchor) ต้องเป็นข้อความสุดท้าย เพื่อให้ recheck ผ่าน
        // เผื่อเงียบ 24 ชม. ทั้งที่ 08:00:00 (saleCut = ข้อความสุดท้ายต้อง <= 15 พ.ย. 08:00)
        $this->addMessage($room->id, 'user', '2026-11-15 07:55:00', 'สนใจ Nolimit Personal ครับ');
        $this->addMessage($room->id, 'bot', '2026-11-15 07:56:00', 'ต้องการกี่ตัวครับ');
        $this->addMessage($room->id, 'user', '2026-11-15 07:57:00', 'เอา 2 ตัวครับ');
        $this->addMessage($room->id, 'bot', '2026-11-15 07:58:00', 'ได้ครับ กำลังเตรียมสินค้าให้ครับ');
        $anchorId = $this->addMessage($room->id, 'user', '2026-11-15 07:59:00', 'ประเมินราคาให้หน่อยครับ');

        $context = $get('context_redacted', ['C: ขอคิดดูก่อนนะครับ', 'B: ได้เลยครับพี่ สนใจตัวไหนทักมาได้เลยครับ']);

        $check = $get('check_answers', self::CLEAN_CHECK);
        $draft = $get('draft', self::DRAFT);
        $logPayload = [
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
            'group_code' => 'G3',
            'group_confidence' => 0.86,
            'context_redacted' => is_string($context) ? $context : json_encode($context, JSON_UNESCAPED_UNICODE),
            'check_answers' => $check,
            'check_overridden' => false,
            'draft' => $draft,
            'drafted_at' => '2026-11-15 09:30:00',
            'draft_day' => '2026-11-15',
            'draft_model' => 'openai/gpt-6-luna',
            'created_at' => '2026-11-15 09:35:00',
            'updated_at' => '2026-11-15 09:35:00',
        ];
        if ($golden) {
            $logPayload['id'] = 123;
            DB::table('lead_recovery_logs')->insert(array_merge($logPayload, $get('log_overrides', [])));
            $logId = 123;
        } else {
            $logId = (int) DB::table('lead_recovery_logs')->insertGetId(array_merge($logPayload, $get('log_overrides', [])));
        }

        $approvalPayload = [
            'lead_recovery_log_id' => $logId,
            'status' => $get('approval_status', 'pending'),
            'revision' => $get('revision', 1),
            'nonce' => $get('nonce', self::NONCE),
            'text' => $get('approval_text', $draft),
            'check_answers' => $check,
            'check_overridden' => false,
            'expires_at' => $get('expires_at', self::EXPIRES),
            'telegram_plugin_id' => $plugin->id,
            'telegram_chat_id' => self::PLUGIN_CHAT,
            'telegram_message_id' => $get('telegram_message_id', 77),
            'telegram_delivery_state' => $get('telegram_delivery_state', 'sent'),
            'last_update_id' => null,
            'created_at' => self::NOW,
            'updated_at' => $get('approval_updated_at', self::NOW),
        ];
        if ($golden || $get('approval_id') !== null) {
            $approvalPayload['id'] = $golden ? 777 : $get('approval_id');
            DB::table('follow_up_approvals')->insert(array_merge($approvalPayload, $get('approval_overrides', [])));
            $approvalId = (int) $approvalPayload['id'];
        } else {
            $approvalId = (int) DB::table('follow_up_approvals')->insertGetId(array_merge($approvalPayload, $get('approval_overrides', [])));
        }

        $this->bumpPgSequences();

        return [
            'user' => $user,
            'bot' => $bot->refresh(),
            'flow' => $flow,
            'plugin' => $plugin->refresh(),
            'room' => $room->refresh(),
            'log_id' => $logId,
            'approval_id' => $approvalId,
            'anchor_id' => $anchorId,
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

    /** ตรวจข้อความบัตรก่อนบรรทัดสถานะ = ข้อความที่ publish ยิงสำหรับแถวเดียวกัน */
    private function publishTextForSameRows(int $approvalId): string
    {
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response(json_encode(['ok' => true, 'result' => ['message_id' => 77]]), 200);
        });

        app(FollowUpTelegramService::class)->publish($approvalId, $this->now());

        $edits = array_values(array_filter($requests, fn ($r) => $this->methodOf($r) === 'sendMessage'));
        self::assertCount(1, $edits, 'publish ต้องยิง sendMessage ครั้งเดียว');

        return (string) $edits[0]['text'];
    }

    // ------------------------------------------------- 1) การขนส่ง (transport)

    /** @return array<string, array{0: string}> */
    public static function transportCases(): array
    {
        return [
            '200 ok true result object' => ['ok_result_object'],
            '200 ok true result true' => ['ok_result_true'],
            '200 ok false' => ['ok_false'],
            '200 html body' => ['html_200'],
            '400 ok false json' => ['rejected_400'],
            '403 ok false json' => ['rejected_403'],
            '429 ok false json' => ['rejected_429'],
            '400 html body' => ['html_400'],
            '302 with location' => ['redirect_302'],
            '500' => ['server_500'],
            'thrown connection timeout' => ['throw_timeout'],
        ];
    }

    #[Test]
    #[DataProvider('transportCases')]
    public function edit_card_transport_row(string $case): void
    {
        $stub = $this->transportStub($case);

        [$result, $requests] = $this->transportCall(
            fn () => app(TelegramAlertBotService::class)->editFollowUpCard(
                $this->fakeToken(),
                self::CHAT_ID,
                77,
                'การ์ดทดสอบ '.self::HTML_CANARY,
                [['text' => 'ส่ง', 'callback_data' => 'fs|1|1|'.self::NONCE]],
            ),
            $stub,
        );

        [$outcome, $status] = match ($case) {
            'ok_result_object', 'ok_result_true' => ['accepted', 200],
            'ok_false', 'html_200' => ['unknown', 200],
            'rejected_400' => ['rejected', 400],
            'rejected_403' => ['rejected', 403],
            'rejected_429' => ['rejected', 429],
            'html_400' => ['unknown', 400],
            'redirect_302' => ['unknown', 302],
            'server_500' => ['unknown', 500],
            'throw_timeout' => ['unknown', null],
        };

        self::assertSame(['outcome' => $outcome, 'status' => $status], $result, 'ผลลัพธ์ต้องมีแค่ outcome + status');
        self::assertCount(1, $requests, $case.' ต้องยิงครั้งเดียว — ห้าม retry');

        $request = $requests[0];
        self::assertSame('https://api.telegram.org/bot'.$this->fakeToken().'/editMessageText', (string) $request->url());
        self::assertSame(self::CHAT_ID, $request['chat_id']);
        self::assertSame(77, (int) $request['message_id']);
        self::assertSame('การ์ดทดสอบ '.self::HTML_CANARY, $request['text']);
        self::assertSame('HTML', $request['parse_mode']);
        self::assertSame(['inline_keyboard' => [['text' => 'ส่ง', 'callback_data' => 'fs|1|1|'.self::NONCE]]], json_decode($request['reply_markup'], true));
        self::assertSame(['is_disabled' => true], json_decode($request['link_preview_options'], true));
        $this->assertNoSecretsInLog();
    }

    #[Test]
    #[DataProvider('transportCases')]
    public function answer_callback_transport_row(string $case): void
    {
        $stub = $this->transportStub($case);

        [$result, $requests] = $this->transportCall(
            fn () => app(TelegramAlertBotService::class)->answerFollowUpCallback(
                $this->fakeToken(),
                self::CB_ID,
                'คำตอบ '.self::HTML_CANARY,
                true,
            ),
            $stub,
        );

        [$outcome, $status] = match ($case) {
            'ok_result_object', 'ok_result_true' => ['accepted', 200],
            'ok_false', 'html_200' => ['unknown', 200],
            'rejected_400' => ['rejected', 400],
            'rejected_403' => ['rejected', 403],
            'rejected_429' => ['rejected', 429],
            'html_400' => ['unknown', 400],
            'redirect_302' => ['unknown', 302],
            'server_500' => ['unknown', 500],
            'throw_timeout' => ['unknown', null],
        };

        self::assertSame(['outcome' => $outcome, 'status' => $status], $result);
        self::assertCount(1, $requests, $case.' ต้องยิงครั้งเดียว — ห้าม retry');

        $request = $requests[0];
        self::assertSame('https://api.telegram.org/bot'.$this->fakeToken().'/answerCallbackQuery', (string) $request->url());
        self::assertSame(self::CB_ID, $request['callback_query_id']);
        self::assertSame('คำตอบ '.self::HTML_CANARY, $request['text']);
        self::assertSame(true, $request['show_alert'], 'show_alert ต้องเป็น JSON boolean true');
        $this->assertNoSecretsInLog();
    }

    #[Test]
    public function answer_callback_show_alert_false_is_json_boolean(): void
    {
        [$result, $requests] = $this->transportCall(
            fn () => app(TelegramAlertBotService::class)->answerFollowUpCallback(
                $this->fakeToken(),
                self::CB_ID,
                'toast',
                false,
            ),
            fn () => Http::response(json_encode(['ok' => true, 'result' => true]), 200),
        );

        self::assertSame(['outcome' => 'accepted', 'status' => 200], $result);
        self::assertCount(1, $requests);
        self::assertSame(false, $requests[0]['show_alert'], 'show_alert ต้องเป็น JSON boolean false');
    }

    private function transportStub(string $case): \Closure
    {
        return match ($case) {
            'ok_result_object' => fn () => Http::response(json_encode(['ok' => true, 'result' => ['message_id' => 5]]), 200),
            'ok_result_true' => fn () => Http::response(json_encode(['ok' => true, 'result' => true]), 200),
            'ok_false' => fn () => Http::response(json_encode(['ok' => false]), 200),
            'html_200' => fn () => Http::response('<html>'.self::BODY_CANARY.'</html>', 200),
            'rejected_400' => fn () => Http::response(json_encode(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found '.self::BODY_CANARY]), 400),
            'rejected_403' => fn () => Http::response(json_encode(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden '.self::BODY_CANARY]), 403),
            'rejected_429' => fn () => Http::response(json_encode(['ok' => false, 'error_code' => 429, 'description' => self::BODY_CANARY]), 429),
            'html_400' => fn () => Http::response('<html>'.self::BODY_CANARY.'</html>', 400),
            'redirect_302' => fn () => Http::response('', 302, ['Location' => 'https://t.me/redirect-target-'.self::BODY_CANARY]),
            'server_500' => fn () => Http::response('<html>'.self::BODY_CANARY.'</html>', 500),
            'throw_timeout' => fn () => throw new ConnectionException('cURL error 28 timeout '.$this->fakeToken()),
        };
    }

    private function transportCall(\Closure $call, \Closure $stub): array
    {
        $requests = [];
        $this->resetLogCaptures();
        Http::fake(function ($request) use (&$requests, $stub) {
            $requests[] = $request;

            return $stub($request);
        });

        $result = $call();
        $this->assertNoSecretsInLog();

        return [$result, $requests];
    }

    /** @return array<string, array{0: string}> */
    public static function emptyArgumentCases(): array
    {
        return [
            'empty token' => ['token'],
            'empty chat id' => ['chat'],
            'empty text' => ['text'],
            'zero message id' => ['message_id'],
        ];
    }

    #[Test]
    #[DataProvider('emptyArgumentCases')]
    public function edit_card_empty_argument_makes_no_request(string $which): void
    {
        $arguments = [
            'token' => ['', self::CHAT_ID, 77, 'ข้อความ'],
            'chat' => [$this->fakeToken(), '', 77, 'ข้อความ'],
            'text' => [$this->fakeToken(), self::CHAT_ID, 77, ''],
            'message_id' => [$this->fakeToken(), self::CHAT_ID, 0, 'ข้อความ'],
        ];
        [$token, $chatId, $messageId, $text] = $arguments[$which];

        $requests = [];
        $this->resetLogCaptures();
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response('{}', 200);
        });

        $result = app(TelegramAlertBotService::class)->editFollowUpCard($token, $chatId, $messageId, $text, []);

        self::assertSame(['outcome' => 'rejected', 'status' => null], $result);
        self::assertCount(0, $requests, 'ข้อมูลว่างต้องไม่ยิงออก');
        $this->assertNoSecretsInLog();
    }

    /** @return array<string, array{0: string}> */
    public static function emptyAnswerArgumentCases(): array
    {
        return [
            'empty token' => ['token'],
            'empty callback id' => ['callback_id'],
            'empty text' => ['text'],
        ];
    }

    #[Test]
    #[DataProvider('emptyAnswerArgumentCases')]
    public function answer_callback_empty_argument_makes_no_request(string $which): void
    {
        $arguments = [
            'token' => ['', self::CB_ID, 'ข้อความ'],
            'callback_id' => [$this->fakeToken(), '', 'ข้อความ'],
            'text' => [$this->fakeToken(), self::CB_ID, ''],
        ];
        [$token, $callbackId, $text] = $arguments[$which];

        $requests = [];
        $this->resetLogCaptures();
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response('{}', 200);
        });

        $result = app(TelegramAlertBotService::class)->answerFollowUpCallback($token, $callbackId, $text, false);

        self::assertSame(['outcome' => 'rejected', 'status' => null], $result);
        self::assertCount(0, $requests, 'ข้อมูลว่างต้องไม่ยิงออก');
        $this->assertNoSecretsInLog();
    }

    #[Test]
    public function edit_card_real_socket_to_closed_port_is_unknown_without_leaking(): void
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

        $result = $service->editFollowUpCard($this->fakeToken(), self::CHAT_ID, 77, 'การ์ด '.self::HTML_CANARY, []);

        self::assertSame(['outcome' => 'unknown', 'status' => null], $result);
        $this->assertNoBotMarkerInLog();
        $this->assertNoSecretsInLog();
    }

    #[Test]
    public function answer_callback_real_socket_to_closed_port_is_unknown_without_leaking(): void
    {
        $port = 55900 + (getmypid() % 900);
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

        $result = $service->answerFollowUpCallback($this->fakeToken(), self::CB_ID, 'คำตอบ '.self::HTML_CANARY, false);

        self::assertSame(['outcome' => 'unknown', 'status' => null], $result);
        $this->assertNoBotMarkerInLog();
        $this->assertNoSecretsInLog();
    }

    // ------------------------------------------------- 2) การ route ใน controller

    #[Test]
    public function routing_bad_secret_and_missing_plugin_do_nothing(): void
    {
        $seed = $this->seedWorld();
        $this->mock(FollowUpApprovalService::class, fn ($m) => $m->shouldNotReceive('callback'));

        [$response, $requests] = $this->tap(
            $this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])),
            pluginToken: $this->pluginToken(),
            secret: 'WRONG-SECRET',
        );
        $response->assertStatus(401);
        self::assertCount(0, $requests);

        [$response, $requests] = $this->tap(
            $this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])),
            pluginToken: $this->fakeToken(),
        );
        $response->assertStatus(404);
        self::assertCount(0, $requests);
    }

    /** @return array<string, array{0: string}> */
    public static function malformedDataCases(): array
    {
        return [
            '3 parts fs|1|2 (old parser shape)' => ['fs|1|2'],
            'fs| alone' => ['fs|'],
            'fs|x|1|nonce' => ['fs|x|1|'.self::NONCE],
            'fs|0|1|nonce' => ['fs|0|1|'.self::NONCE],
            'fs|1|0|nonce' => ['fs|1|0|'.self::NONCE],
            '15 hex nonce' => ['fs|777|1|abcd1234abcd123'],
            '17 hex nonce' => ['fs|777|1|abcd1234abcd12345'],
            'uppercase hex nonce' => ['fs|777|1|ABCD1234ABCD1234'],
            '5 parts' => ['fs|777|1|'.self::NONCE.'|x'],
            'unknown code zz' => ['zz|777|1|'.self::NONCE],
            '20-digit approval id' => ['fs|99999999999999999999|1|'.self::NONCE],
            'id one above PHP_INT_MAX' => ['fs|9223372036854775808|1|'.self::NONCE],
            'revision one above int32' => ['fs|777|2147483648|'.self::NONCE],
            'no data' => [null],
        ];
    }

    #[Test]
    #[DataProvider('malformedDataCases')]
    public function routing_malformed_data_is_ignored_with_no_request(?string $data): void
    {
        $seed = $this->seedWorld();
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        [$response, $requests] = $this->tap($this->baseCallback(data: $data));

        $response->assertOk()->assertExactJson(['ok' => true]);
        self::assertCount(0, $requests, 'ข้อมูลเสียต้องถูกละเว้นเงียบๆ ไม่ตอบ callback และไม่แตะ parser เดิม');
        $this->noRowChanged($beforeApproval, $this->approvalRow($seed['approval_id']), $beforeLog, $this->logRow($seed['log_id']));
    }

    #[Test]
    public function routing_missing_callback_id_is_ignored(): void
    {
        $seed = $this->seedWorld();

        [$response, $requests] = $this->tap($this->baseCallback(['id' => ''], data: $this->cbData('fs', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        self::assertCount(0, $requests);
    }

    #[Test]
    public function parse_data_rejects_trailing_newline_and_empty_callback_id(): void
    {
        // ผ่าน method เอง — route จริงมี TrimStrings ตัดช่องว่างหัวท้ายก่อนถึง controller
        // กฎยังต้องแน่นที่ parseData: /D ทำให้ \n ต่อท้ายไม่ผ่าน
        $svc = app(FollowUpApprovalService::class);
        $m = new \ReflectionMethod(FollowUpApprovalService::class, 'parseData');
        $m->setAccessible(true);

        self::assertNull($m->invoke($svc, ['id' => self::CB_ID, 'data' => $this->cbData('fs', 777)."\n"]));
        self::assertNull($m->invoke($svc, ['id' => self::CB_ID, 'data' => $this->cbData('fs', 777)." \n"]));
        self::assertNull($m->invoke($svc, ['id' => '', 'data' => $this->cbData('fs', 777)]));
        self::assertSame(['fs', 777, 1, self::NONCE], $m->invoke($svc, ['id' => self::CB_ID, 'data' => $this->cbData('fs', 777)]));
    }

    /** @return array<string, array{0: string}> */
    public static function oldCodeCases(): array
    {
        return [
            'pa' => ['pa'],
            'pc' => ['pc'],
            'po' => ['po'],
            'dv' => ['dv'],
            'dx' => ['dx'],
            'dz' => ['dz'],
        ];
    }

    #[Test]
    #[DataProvider('oldCodeCases')]
    public function old_codes_never_reach_the_new_service(string $code): void
    {
        $seed = $this->seedWorld();
        $this->mock(FollowUpApprovalService::class, fn ($m) => $m->shouldNotReceive('callback'));
        $this->mock(ManualPaymentConfirmService::class, fn ($m) => $m->shouldReceive('confirm')->andReturn(['message' => null, 'order_created' => false]));

        $data = match ($code) {
            'pa' => 'pa|1317|x',
            'pc' => 'pc|1317|590',
            'po' => 'po|1317|100',
            'dv' => 'dv|1317|1',
            'dx' => 'dx|1317|1',
            'dz' => 'dz|1317|1',
        };

        [$response, $requests] = $this->tap($this->baseCallback(data: $data));

        $response->assertOk();
        $this->assertOnlyTelegramHost($requests);
    }

    #[Test]
    public function new_codes_reach_the_new_service_and_answer_not_ready(): void
    {
        $seed = $this->seedWorld();
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fe', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'ปุ่มนี้ยังไม่เปิดใช้', true);
        self::assertCount(1, $requests, 'fe ต้องตอบแล้วจบ ไม่มี request อื่น');
        $this->noRowChanged($beforeApproval, $this->approvalRow($seed['approval_id']), $beforeLog, $this->logRow($seed['log_id']));

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fc', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'ปุ่มนี้ยังไม่เปิดใช้', true);
        self::assertCount(1, $requests);
    }

    // ------------------------------------------------- 3) ตารางอัตลักษณ์ (identity)

    /** @return array<string, array<int, mixed>> */
    public static function identityCases(): array
    {
        return [
            'another chat id' => [['message' => ['message_id' => 77, 'chat' => ['id' => 777]]], null, 'ignored', 0, []],
            'message missing' => [['message' => null], null, 'ignored', 0, []],
            'allowlist missing' => [[], 'allowlist_missing', 'no_permission', 1, ['no_permission']],
            'allowlist empty list' => [[], 'allowlist_empty', 'no_permission', 1, ['no_permission']],
            'allowlist map' => [[], 'allowlist_map', 'no_permission', 1, ['no_permission']],
            'allowlist string' => [[], 'allowlist_string', 'no_permission', 1, ['no_permission']],
            'allowlist blank entry' => [[], 'allowlist_blank', 'no_permission', 1, ['no_permission']],
            'person not in list' => [['from' => ['id' => 666, 'first_name' => 'คนแปลกหน้า']], null, 'no_permission', 1, ['no_permission']],
            'from.id as string' => [['from' => ['id' => '555']], null, 'no_permission', 1, ['no_permission']],
            'from.id as 0' => [['from' => ['id' => 0]], null, 'no_permission', 1, ['no_permission']],
            'from.id missing' => [['from' => ['first_name' => 'ใครสักคน']], null, 'no_permission', 1, ['no_permission']],
            'unknown approval id' => [[], 'unknown_approval_id', 'not_found', 1, ['not_found']],
            'approval of another plugin' => [[], 'other_plugin_id', 'not_found', 1, ['not_found']],
            'stored chat differs' => [[], 'other_chat_id', 'not_found', 1, ['not_found']],
            'log bot differs from plugin bot' => [[], 'other_bot_log', 'not_found', 1, ['not_found']],
            'wrong revision' => [[], 'wrong_revision', 'stale_card', 1, ['stale_card']],
            'wrong nonce' => [[], 'wrong_nonce', 'stale_card', 1, ['stale_card']],
            'message_id as string' => [['message' => ['message_id' => '77', 'chat' => ['id' => 888]]], null, 'not_found', 1, ['not_found']],
            'different message_id' => [['message' => ['message_id' => 78, 'chat' => ['id' => 888]]], null, 'not_found', 1, ['not_found']],
            'state new' => [[], 'state_new', 'not_found', 1, ['not_found']],
            'state failed' => [[], 'state_failed', 'not_found', 1, ['not_found']],
        ];
    }

    #[Test]
    #[DataProvider('identityCases')]
    public function identity_table(array $callbackOverrides, ?string $seedOption, string $expectedResult, int $expectedRequests, array $expectedAnswer): void
    {
        $options = match ($seedOption) {
            'allowlist_missing' => ['allowlist_missing' => true],
            'allowlist_empty' => ['allowlist' => []],
            'allowlist_map' => ['allowlist' => [7 => 7]],
            'allowlist_string' => ['allowlist' => '555'],
            'allowlist_blank' => ['allowlist' => [' ']],
            'other_plugin' => ['other_plugin_id' => true],
            'other_chat' => ['other_chat_id' => true],
            'other_bot_log' => ['other_bot_log' => true],
            'wrong_revision' => ['revision' => 2],
            'wrong_nonce' => ['nonce' => 'ffff1111ffff1111'],
            'state_new' => ['telegram_delivery_state' => 'new', 'telegram_message_id' => null],
            'state_failed' => ['telegram_delivery_state' => 'failed'],
            default => [],
        };
        $seed = $this->seedWorld($options);

        if ($seedOption === 'other_plugin_id') {
            $otherPlugin = FlowPlugin::create([
                'flow_id' => $seed['flow']->id,
                'type' => 'telegram',
                'name' => 'แจ้งเตือนอีก',
                'enabled' => true,
                'trigger_condition' => 'always',
                'config' => ['access_token' => $this->pluginToken().'-x', 'chat_id' => self::PLUGIN_CHAT, 'authorized_user_ids' => [self::PERSON_ID]],
            ]);
            DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update(['telegram_plugin_id' => $otherPlugin->id]);
        }
        if ($seedOption === 'other_chat_id') {
            DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update(['telegram_chat_id' => '777']);
        }
        if ($seedOption === 'other_bot_log') {
            $otherUser = User::factory()->owner()->create();
            $otherBot = Bot::factory()->active()->line()->create(['user_id' => $otherUser->id]);
            DB::table('lead_recovery_logs')->where('id', $seed['log_id'])->update(['bot_id' => $otherBot->id]);
        }

        $data = $this->cbData('fs', $seedOption === 'unknown_approval_id' ? $seed['approval_id'] + 100000 : $seed['approval_id']);
        $callback = $this->baseCallback($callbackOverrides, $data);
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        [$response, $requests] = $this->tap($callback);

        $response->assertOk()->assertExactJson(['ok' => true]);
        self::assertCount($expectedRequests, $requests, $seedOption.': จำนวน request ไม่ตรง');
        $this->assertOnlyTelegramHost($requests);
        if ($expectedAnswer !== []) {
            $this->assertSingleAnswer($requests, ...$this->answerExpectation($expectedAnswer[0]));
        }
        $this->noRowChanged($beforeApproval, $this->approvalRow($seed['approval_id']), $beforeLog, $this->logRow($seed['log_id']));
    }

    private function answerExpectation(string $key): array
    {
        return match ($key) {
            'no_permission' => ['ไม่มีสิทธิ์กดปุ่มนี้', true],
            'not_found' => ['ไม่พบรายการนี้ หรือการ์ดนี้ใช้ไม่ได้แล้ว', true],
            'stale_card' => ['การ์ดนี้เป็นรุ่นเก่า ใช้การ์ดล่าสุดแทน', true],
            'expired' => ['หมดอายุแล้ว ไม่ได้ส่ง', true],
            'not_ready' => ['ปุ่มนี้ยังไม่เปิดใช้', true],
            'skip_too_late' => ['กดส่งไปแล้ว ข้ามไม่ได้', true],
            default => throw new \LogicException('unknown answer key '.$key),
        };
    }

    #[Test]
    public function outsider_gets_identical_answer_for_existing_and_missing_approval(): void
    {
        $seed = $this->seedWorld();
        $outsider = ['from' => ['id' => 666, 'first_name' => 'คนแปลกหน้า']];

        [$responseA, $requestsA] = $this->tap($this->baseCallback($outsider, $this->cbData('fs', $seed['approval_id'])));
        [$responseB, $requestsB] = $this->tap($this->baseCallback($outsider, $this->cbData('fs', $seed['approval_id'] + 100000)));

        $responseA->assertOk()->assertExactJson(['ok' => true]);
        $responseB->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requestsA, 'ไม่มีสิทธิ์กดปุ่มนี้', true);
        $this->assertSingleAnswer($requestsB, 'ไม่มีสิทธิ์กดปุ่มนี้', true);
        self::assertSame(
            json_encode($requestsA[0]['callback_query_id'].'|'.$requestsA[0]['text']),
            json_encode($requestsB[0]['callback_query_id'].'|'.$requestsB[0]['text']),
            'คนนอกต้องเห็นคำตอบเดียวกันไม่ว่า approval จะมีจริงหรือไม่',
        );
    }

    /** @return array<string, array<int, mixed>> */
    public static function allowlistAllowedCases(): array
    {
        return [
            'entry as integer' => [[555]],
            'entry with spaces' => [[' 555 ']],
        ];
    }

    #[Test]
    #[DataProvider('allowlistAllowedCases')]
    public function allowlist_entry_as_integer_or_spaced_string_is_allowed(array $allowlist): void
    {
        $seed = $this->seedWorld(['allowlist' => $allowlist]);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'รับคำสั่งแล้ว กำลังส่งให้ลูกค้า', false);
    }

    // ------------------------------------------------- 4) การ adopt message id (D76)

    #[Test]
    public function adoption_from_unknown_state_without_message_id_adopts_and_sends(): void
    {
        $seed = $this->seedWorld(['telegram_delivery_state' => 'unknown', 'telegram_message_id' => null]);
        $beforeLog = $this->logRow($seed['log_id']);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'รับคำสั่งแล้ว กำลังส่งให้ลูกค้า', false);
        $this->assertSingleEdit($requests);

        $approval = $this->approvalRow($seed['approval_id']);
        self::assertSame('approved', $approval->status);
        self::assertSame('sent', $approval->telegram_delivery_state, 'การ์ดที่ถึงจริงต้อง adopt message id');
        self::assertSame(77, $approval->telegram_message_id);
        self::assertSame('approved', $this->logRow($seed['log_id'])->delivery_status);
        $this->assertRowChangedOnly($beforeLog, $this->logRow($seed['log_id']), [
            'delivery_status', 'owner_action', 'owner_telegram_user_id', 'owner_action_at', 'check_overridden', 'updated_at',
        ]);
    }

    #[Test]
    public function adoption_from_sending_state_without_message_id_adopts_and_sends(): void
    {
        $seed = $this->seedWorld(['telegram_delivery_state' => 'sending', 'telegram_message_id' => null]);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fk', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'ข้ามแล้ว ไม่ส่งข้อความนี้', false);
        $this->assertSingleEdit($requests);

        $approval = $this->approvalRow($seed['approval_id']);
        self::assertSame('skipped', $approval->status);
        self::assertSame('sent', $approval->telegram_delivery_state);
        self::assertSame(77, $approval->telegram_message_id);
    }

    #[Test]
    public function adoption_does_not_happen_for_outsider_or_bad_nonce(): void
    {
        $seed = $this->seedWorld(['telegram_delivery_state' => 'unknown', 'telegram_message_id' => null]);
        $beforeApproval = $this->approvalRow($seed['approval_id']);

        $outsider = ['from' => ['id' => 666, 'first_name' => 'คนแปลกหน้า']];
        [$response, $requests] = $this->tap($this->baseCallback($outsider, $this->cbData('fs', $seed['approval_id'])));
        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'ไม่มีสิทธิ์กดปุ่มนี้', true);

        $afterOutsider = $this->approvalRow($seed['approval_id']);
        $this->assertRowChangedOnly($beforeApproval, $afterOutsider, []);
        self::assertNull($afterOutsider->telegram_message_id, 'คนนอกต้องไม่ adopt');

        $beforeWrongNonce = $this->approvalRow($seed['approval_id']);
        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'], 1, 'ffff1111ffff1111')));
        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'การ์ดนี้เป็นรุ่นเก่า ใช้การ์ดล่าสุดแทน', true);

        $afterWrongNonce = $this->approvalRow($seed['approval_id']);
        $this->assertRowChangedOnly($beforeWrongNonce, $afterWrongNonce, []);
        self::assertNull($afterWrongNonce->telegram_message_id, 'nonce ผิดต้องไม่ adopt');
        self::assertSame('unknown', $afterWrongNonce->telegram_delivery_state);
    }

    #[Test]
    public function unknown_state_with_stored_message_id_differing_is_not_found(): void
    {
        $seed = $this->seedWorld(['telegram_delivery_state' => 'unknown', 'telegram_message_id' => 88]);
        $beforeApproval = $this->approvalRow($seed['approval_id']);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'ไม่พบรายการนี้ หรือการ์ดนี้ใช้ไม่ได้แล้ว', true);
        $after = $this->approvalRow($seed['approval_id']);
        $this->assertRowChangedOnly($beforeApproval, $after, []);
        self::assertSame(88, $after->telegram_message_id, 'id ที่เก็บไว้ต้องไม่ถูกแทน');
    }

    // ------------------------------------------------- 5) ปุ่มส่ง (Send)

    #[Test]
    public function send_clean_writes_exact_columns_and_answers_then_edits(): void
    {
        $seed = $this->seedWorld();
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertNoSecretsInLog();

        // ลำดับ: ตอบ callback (toast) แล้วค่อยแก้การ์ด
        self::assertCount(2, $requests);
        self::assertSame('answerCallbackQuery', $this->methodOf($requests[0]));
        self::assertSame('editMessageText', $this->methodOf($requests[1]));
        $this->assertSingleAnswer($requests, 'รับคำสั่งแล้ว กำลังส่งให้ลูกค้า', false);
        $this->assertSingleEdit($requests);
        $this->assertOnlyTelegramHost($requests);

        $approval = $this->approvalRow($seed['approval_id']);
        self::assertSame('approved', $approval->status);
        self::assertFalse((bool) $approval->check_overridden);
        self::assertSame(self::UPDATE_ID, $approval->last_update_id);
        self::assertSame(self::NOW, $approval->updated_at);
        $this->assertRowChangedOnly($beforeApproval, $approval, ['status', 'check_overridden', 'last_update_id', 'updated_at']);

        $log = $this->logRow($seed['log_id']);
        self::assertSame('approved', $log->delivery_status);
        self::assertSame('sent', $log->owner_action);
        self::assertSame('555', $log->owner_telegram_user_id);
        self::assertSame(self::NOW, $log->owner_action_at);
        self::assertFalse((bool) $log->check_overridden);
        self::assertSame(self::NOW, $log->updated_at);
        $this->assertRowChangedOnly($beforeLog, $log, ['delivery_status', 'owner_action', 'owner_telegram_user_id', 'owner_action_at', 'check_overridden', 'updated_at']);

        // ไม่มีการยิงหาลูกค้า ไม่มี job ไม่มีแถวข้อความ
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        self::assertSame(5, DB::table('messages')->where('conversation_id', $seed['room']->id)->count(), 'ห้ามเพิ่มแถวข้อความ');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function checkShapes(): array
    {
        return [
            'sql null' => ['null-shape', '⚠️ ตรวจไม่ได้'],
            'json null' => ['null', '⚠️ ตรวจไม่ได้'],
            'empty list' => ['[]', '⚠️ ตรวจไม่ได้'],
            'empty object' => ['{}', '⚠️ ตรวจไม่ได้'],
            'failed as string' => ['{"failed":"oops","error":false}', '⚠️ ตรวจไม่ได้'],
            'failed missing' => ['{"error":false}', '⚠️ ตรวจไม่ได้'],
            'error null' => ['{"failed":[],"error":null}', '⚠️ ตรวจไม่ได้'],
            'error 0' => ['{"failed":[],"error":0}', '⚠️ ตรวจไม่ได้'],
            'error empty string' => ['{"failed":[],"error":""}', '⚠️ ตรวจไม่ได้'],
            'error string false' => ['{"failed":[],"error":"false"}', '⚠️ ตรวจไม่ได้'],
            'error true' => ['{"failed":[],"error":true}', '⚠️ ตรวจไม่ได้'],
            'failed object' => ['{"failed":{},"error":false}', '⚠️ ตรวจไม่ได้'],
            'clean' => ['clean', '✅ ผ่าน'],
            'one known key' => ['{"failed":["pressure"],"error":false}', '⚠️ เตือน: เร่งหรือกดดันลูกค้า'],
            'two known keys file order' => ['{"failed":["price_stock","off_topic"],"error":false}', '⚠️ เตือน: พูดถึงราคาหรือสต็อก · ไม่ตรงเรื่องที่คุยค้างไว้'],
            'one unknown key' => ['{"failed":["mystery"],"error":false}', '⚠️ ตรวจไม่ได้'],
            'known plus unknown' => ['{"failed":["support_free","zzz"],"error":false}', '⚠️ เตือน: บอกว่า Support ฟรี'],
        ];
    }

    #[Test]
    #[DataProvider('checkShapes')]
    public function send_flagged_and_unreadable_check_shapes(string $checkShape, string $expectedCheckLine): void
    {
        $check = match ($checkShape) {
            'null-shape' => null,
            'clean' => self::CLEAN_CHECK,
            default => $checkShape,
        };
        $expectedFlagged = FollowUpSendService::checkFlagged((string) $check);

        $seed = $this->seedWorld(['check_answers' => $check]);
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $expectedAnswer = $expectedFlagged ? 'รับคำสั่งแล้ว กำลังส่งให้ลูกค้า (ข้อความนี้มีคำเตือน)' : 'รับคำสั่งแล้ว กำลังส่งให้ลูกค้า';
        $this->assertSingleAnswer($requests, $expectedAnswer, false);
        $this->assertSingleEdit($requests);

        $approval = $this->approvalRow($seed['approval_id']);
        self::assertSame('approved', $approval->status);
        self::assertSame($expectedFlagged, (bool) $approval->check_overridden, 'check_overridden ต้องตรง checkFlagged บน approval');
        $this->assertRowChangedOnly($beforeApproval, $approval, ['status', 'check_overridden', 'last_update_id', 'updated_at']);

        $log = $this->logRow($seed['log_id']);
        self::assertSame('approved', $log->delivery_status);
        self::assertSame($expectedFlagged, (bool) $log->check_overridden, 'check_overridden ต้องตรง checkFlagged บน log');
        $this->assertRowChangedOnly($beforeLog, $log, ['delivery_status', 'owner_action', 'owner_telegram_user_id', 'owner_action_at', 'check_overridden', 'updated_at']);
        self::assertSame($expectedFlagged, str_contains($expectedCheckLine, '⚠️'));
    }

    #[Test]
    public function sender_accepts_the_pair_after_a_send_tap(): void
    {
        $seed = $this->seedWorld();

        // LINE fake ต้องลงทะเบียนก่อน tap — Http::fake สะสม stub และ stub แรกชนะเสมอ
        $pushHits = 0;
        Http::fake(function ($request) use (&$pushHits) {
            $url = $request->url();
            if ($url === self::LINE_QUOTA_URL) {
                return Http::response(json_encode(['type' => 'limited', 'value' => 200]), 200);
            }
            if ($url === self::LINE_CONSUMPTION_URL) {
                return Http::response(json_encode(['totalUsage' => 10]), 200);
            }
            if ($url === self::LINE_PUSH_URL) {
                $pushHits++;

                return Http::response('{}', 200, ['x-line-request-id' => self::LINE_REQUEST_ID]);
            }

            return Http::response('', 404);
        });

        [$response] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));
        $response->assertOk();

        $result = app(FollowUpSendService::class)->send($seed['approval_id'], $this->now());

        self::assertSame('sent', $result, 'sender ต้องรับคู่ที่ผ่านการกดส่ง');
        self::assertSame(1, $pushHits, 'LINE push ต้องยิงครั้งเดียว');
        self::assertSame('sent', $this->logRow($seed['log_id'])->delivery_status);
        self::assertSame('sent', $this->approvalRow($seed['approval_id'])->status);
    }

    #[Test]
    public function sender_accepts_flagged_pair_after_send_tap(): void
    {
        $seed = $this->seedWorld(['check_answers' => self::FLAGGED_CHECK]);

        // LINE fake ต้องลงทะเบียนก่อน tap — Http::fake สะสม stub และ stub แรกชนะเสมอ
        Http::fake(function ($request) {
            $url = $request->url();
            if ($url === self::LINE_QUOTA_URL) {
                return Http::response(json_encode(['type' => 'limited', 'value' => 200]), 200);
            }
            if ($url === self::LINE_CONSUMPTION_URL) {
                return Http::response(json_encode(['totalUsage' => 10]), 200);
            }
            if ($url === self::LINE_PUSH_URL) {
                return Http::response('{}', 200, ['x-line-request-id' => self::LINE_REQUEST_ID]);
            }

            return Http::response('', 404);
        });

        [$response] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));
        $response->assertOk();
        self::assertSame('approved', $this->approvalRow($seed['approval_id'])->status);

        $result = app(FollowUpSendService::class)->send($seed['approval_id'], $this->now());

        self::assertSame('sent', $result, 'sender ต้องรับคู่ที่ถูก flag แต่ owner กด override แล้ว');
        self::assertSame('sent', $this->logRow($seed['log_id'])->delivery_status);
    }

    #[Test]
    public function sender_refuses_the_pair_after_a_skip_tap_and_pushes_nothing(): void
    {
        $seed = $this->seedWorld();

        [$response] = $this->tap($this->baseCallback(data: $this->cbData('fk', $seed['approval_id'])));
        $response->assertOk();
        self::assertSame('skipped', $this->approvalRow($seed['approval_id'])->status);

        $pushHits = 0;
        Http::fake(function ($request) use (&$pushHits) {
            $url = $request->url();
            if ($url === self::LINE_QUOTA_URL) {
                return Http::response(json_encode(['type' => 'limited', 'value' => 200]), 200);
            }
            if ($url === self::LINE_CONSUMPTION_URL) {
                return Http::response(json_encode(['totalUsage' => 10]), 200);
            }
            if ($url === self::LINE_PUSH_URL) {
                $pushHits++;

                return Http::response('{}', 200, ['x-line-request-id' => self::LINE_REQUEST_ID]);
            }

            return Http::response('', 404);
        });

        $result = app(FollowUpSendService::class)->send($seed['approval_id'], $this->now());

        self::assertSame('blocked', $result, 'sender ต้องปฏิเสธคู่ที่ถูกข้าม');
        self::assertSame(0, $pushHits, 'ห้ามยิง LINE');
        self::assertSame('skipped', $this->logRow($seed['log_id'])->delivery_status);
    }

    /** @return array<string, array<int, mixed>> */
    public static function sendGateCases(): array
    {
        return [
            'outside at 22:00:00' => ['22:00', '2026-11-16 22:00:00', 'ตอนนี้นอกเวลาส่ง (08:00–22:00) ยังไม่ได้ส่ง กดใหม่ในเวลาได้', false],
            'outside at 07:59:59' => ['07:59', '2026-11-16 07:59:59', 'ตอนนี้นอกเวลาส่ง (08:00–22:00) ยังไม่ได้ส่ง กดใหม่ในเวลาได้', false],
            'pass at 21:59:59' => ['21:59', '2026-11-16 21:59:59', null, true],
            'pass at 08:00:00' => ['08:00', '2026-11-16 08:00:00', null, true],
            'switch off' => ['switch', '2026-11-16 10:00:00', 'ระบบตามลูกค้าของบอทนี้ปิดอยู่ ยังไม่ได้ส่ง', false],
            'handover on' => ['handover', '2026-11-16 10:00:00', 'แอดมินรับห้องนี้อยู่ ยังไม่ได้ส่ง', false],
            'room closed' => ['room', '2026-11-16 10:00:00', 'ห้องนี้ปิดไปแล้ว ไม่ได้ส่ง', false],
            'new customer message' => ['customer', '2026-11-16 10:00:00', 'ลูกค้าพิมพ์เข้ามาแล้ว ไม่ต้องตาม', false],
            'new bot message' => ['bot', '2026-11-16 10:00:00', 'บอทตอบในห้องนี้ไปแล้ว ไม่ได้ส่ง', false],
            'order created' => ['order', '2026-11-16 10:00:00', 'ลูกค้าสั่งซื้อแล้ว ไม่ต้องตาม', false],
            'passed slip' => ['slip', '2026-11-16 10:00:00', 'ลูกค้าสั่งซื้อแล้ว ไม่ต้องตาม', false],
            'capabilities blocked' => ['capabilities', '2026-11-16 10:00:00', 'บอทนี้ยังตั้งค่าไม่ครบ ยังไม่ได้ส่ง', false],
            'reason without own key' => ['other', '2026-11-15 10:00:00', 'ตอนนี้ยังส่งไม่ได้ ลองใหม่ภายหลัง', false],
        ];
    }

    #[Test]
    #[DataProvider('sendGateCases')]
    public function send_gate_table(string $case, string $when, ?string $expectedAnswer, bool $expectedSend): void
    {
        $options = match ($case) {
            'switch' => ['follow_up_enabled' => false],
            'handover' => ['is_handover' => true],
            'room' => ['room_status' => 'closed'],
            'capabilities' => ['bot_ids' => []],
            default => [],
        };
        $seed = $this->seedWorld($options);

        if ($case === 'customer') {
            $this->addMessage($seed['room']->id, 'user', '2026-11-16 09:30:00', 'ตกลงเอาครับ');
        }
        if ($case === 'bot') {
            $this->addMessage($seed['room']->id, 'bot', '2026-11-16 09:30:00', 'ตอบกลับแล้วครับ');
        }
        if ($case === 'order') {
            DB::table('orders')->insert([
                'bot_id' => $seed['bot']->id,
                'conversation_id' => $seed['room']->id,
                'total_amount' => 1500,
                'status' => 'completed',
                'created_at' => '2026-11-16 09:00:00',
                'updated_at' => '2026-11-16 09:00:00',
            ]);
        }
        if ($case === 'slip') {
            DB::table('slip_verifications')->insert([
                'bot_id' => $seed['bot']->id,
                'conversation_id' => $seed['room']->id,
                'status' => 'passed',
                'created_at' => '2026-11-16 09:00:00',
                'updated_at' => '2026-11-16 09:00:00',
            ]);
        }

        $this->freeze($when);
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);

        if ($expectedSend) {
            self::assertCount(2, $requests);
            $this->assertSingleAnswer($requests, 'รับคำสั่งแล้ว กำลังส่งให้ลูกค้า', false);
            $this->assertSingleEdit($requests);
            self::assertSame('approved', $this->approvalRow($seed['approval_id'])->status);
        } else {
            self::assertCount(1, $requests, 'gate ตันต้องมีแค่คำตอบ ไม่มีการแก้การ์ด');
            $this->assertSingleAnswer($requests, $expectedAnswer, true);
            $afterApproval = $this->approvalRow($seed['approval_id']);
            $afterLog = $this->logRow($seed['log_id']);
            $this->noRowChanged($beforeApproval, $afterApproval, $beforeLog, $afterLog);
            self::assertSame('pending', $afterApproval->status, 'gate ตันต้องค้าง pending ไม่ส่งเงียบๆ');
        }
    }

    #[Test]
    public function send_on_edited_revision_answers_not_ready(): void
    {
        $seed = $this->seedWorld(['revision' => 2, 'approval_text' => self::DRAFT."\nบรรทัดแก้ไข"]);
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'], 2)));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'ปุ่มนี้ยังไม่เปิดใช้', true);
        self::assertCount(1, $requests);
        $this->noRowChanged($beforeApproval, $this->approvalRow($seed['approval_id']), $beforeLog, $this->logRow($seed['log_id']));
    }

    #[Test]
    public function send_at_expiry_boundary_answers_expired_and_writes_nothing(): void
    {
        // หมดอายุ 21:00 (อยู่ในชั่วโมงส่ง) — เท่าวินาที = หมดอายุ ก่อนชั่วโมงส่งตัดสิน
        $seed = $this->seedWorld(['expires_at' => '2026-11-16 21:00:00']);
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        $this->freeze('2026-11-16 21:00:00');
        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'หมดอายุแล้ว ไม่ได้ส่ง', true);
        self::assertCount(1, $requests);
        $this->noRowChanged($beforeApproval, $this->approvalRow($seed['approval_id']), $beforeLog, $this->logRow($seed['log_id']));
        self::assertSame('pending', $this->approvalRow($seed['approval_id'])->status);
    }

    #[Test]
    public function skip_at_expiry_boundary_answers_expired_and_writes_nothing(): void
    {
        $seed = $this->seedWorld();

        $this->freeze('2026-11-16 22:12:00');
        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fk', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'หมดอายุแล้ว ไม่ได้ส่ง', true);
        self::assertSame('pending', $this->approvalRow($seed['approval_id'])->status);
    }

    #[Test]
    public function send_one_second_before_expiry_goes_through(): void
    {
        $seed = $this->seedWorld(['expires_at' => '2026-11-16 21:00:00']);

        $this->freeze('2026-11-16 20:59:59');
        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'รับคำสั่งแล้ว กำลังส่งให้ลูกค้า', false);
        self::assertSame('approved', $this->approvalRow($seed['approval_id'])->status);
    }

    // ------------------------------------------------- 6) ปุ่มข้าม (Skip)

    #[Test]
    public function skip_writes_exact_columns_and_answers_then_edits(): void
    {
        $seed = $this->seedWorld();
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fk', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        self::assertCount(2, $requests);
        $this->assertSingleAnswer($requests, 'ข้ามแล้ว ไม่ส่งข้อความนี้', false);
        $this->assertSingleEdit($requests);

        $approval = $this->approvalRow($seed['approval_id']);
        self::assertSame('skipped', $approval->status);
        self::assertFalse((bool) $approval->check_overridden);
        self::assertSame(self::UPDATE_ID, $approval->last_update_id);
        $this->assertRowChangedOnly($beforeApproval, $approval, ['status', 'last_update_id', 'updated_at']);

        $log = $this->logRow($seed['log_id']);
        self::assertSame('skipped', $log->delivery_status);
        self::assertSame('skipped', $log->owner_action);
        self::assertSame('555', $log->owner_telegram_user_id);
        self::assertSame(self::NOW, $log->owner_action_at);
        $this->assertRowChangedOnly($beforeLog, $log, ['delivery_status', 'owner_action', 'owner_telegram_user_id', 'owner_action_at', 'updated_at']);
    }

    #[Test]
    public function skip_has_no_gate_outside_hours_switch_off_or_handover(): void
    {
        $seedOff = $this->seedWorld(['follow_up_enabled' => false, 'expires_at' => '2026-11-17 12:00:00']);
        $this->freeze('2026-11-16 23:30:00');
        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fk', $seedOff['approval_id'])));
        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'ข้ามแล้ว ไม่ส่งข้อความนี้', false);
        self::assertSame('skipped', $this->approvalRow($seedOff['approval_id'])->status);

        $seedHandover = $this->seedWorld(['is_handover' => true, 'expires_at' => '2026-11-17 12:00:00']);
        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fk', $seedHandover['approval_id'])));
        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'ข้ามแล้ว ไม่ส่งข้อความนี้', false);
        self::assertSame('skipped', $this->approvalRow($seedHandover['approval_id'])->status);

        $seedClosed = $this->seedWorld(['room_status' => 'closed', 'expires_at' => '2026-11-17 12:00:00']);
        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fk', $seedClosed['approval_id'])));
        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'ข้ามแล้ว ไม่ส่งข้อความนี้', false);
        self::assertSame('skipped', $this->approvalRow($seedClosed['approval_id'])->status);
    }

    // ------------------------------------------------- 7) กดซ้ำ / สถานะอื่น

    #[Test]
    public function same_update_twice_transitions_once(): void
    {
        $seed = $this->seedWorld();

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));
        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'รับคำสั่งแล้ว กำลังส่งให้ลูกค้า', false);

        $afterFirst = $this->approvalRow($seed['approval_id']);
        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'กดส่งไปแล้ว กำลังส่งให้ลูกค้า', true);
        self::assertCount(1, $requests, 'การกดซ้ำต้องตอบอย่างเดียว ไม่แก้การ์ดซ้ำ');
        $afterSecond = $this->approvalRow($seed['approval_id']);
        $this->assertRowChangedOnly($afterFirst, $afterSecond, []);
        self::assertSame('approved', $afterSecond->status);
    }

    /** @return array<string, array<int, mixed>> */
    public static function repeatPairCases(): array
    {
        return [
            'fs then fs' => ['fs', 'fs', 'กดส่งไปแล้ว กำลังส่งให้ลูกค้า'],
            'fs then fk' => ['fs', 'fk', 'กดส่งไปแล้ว ข้ามไม่ได้'],
            'fk then fs' => ['fk', 'fs', 'ข้ามไปแล้ว ไม่ได้ส่ง'],
            'fk then fk' => ['fk', 'fk', 'ข้ามไปแล้ว ไม่ได้ส่ง'],
        ];
    }

    #[Test]
    #[DataProvider('repeatPairCases')]
    public function repeat_pairs_answer_the_state_they_found(string $first, string $second, string $expectedAnswer): void
    {
        $seed = $this->seedWorld();

        [$response] = $this->tap($this->baseCallback(data: $this->cbData($first, $seed['approval_id'])));
        $response->assertOk()->assertExactJson(['ok' => true]);

        $afterFirst = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);
        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData($second, $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, $expectedAnswer, true);
        self::assertCount(1, $requests);
        $afterSecond = $this->approvalRow($seed['approval_id']);
        $this->assertRowChangedOnly($afterFirst, $afterSecond, []);
        $this->assertRowChangedOnly($beforeLog, $this->logRow($seed['log_id']), []);
        self::assertSame($first === 'fs' ? 'approved' : 'skipped', $afterSecond->status);
    }

    /** @return array<string, array<int, mixed>> */
    public static function statusTableCases(): array
    {
        return [
            'fs on sending' => ['fs', 'sending', 'กดส่งไปแล้ว กำลังส่งให้ลูกค้า'],
            'fs on sent' => ['fs', 'sent', 'ส่งให้ลูกค้าไปแล้ว'],
            'fs on expired' => ['fs', 'expired', 'หมดอายุแล้ว ไม่ได้ส่ง'],
            'fs on cancelled' => ['fs', 'cancelled', 'รายการนี้ถูกยกเลิกแล้ว ไม่ได้ส่ง'],
            'fs on editing' => ['fs', 'editing', 'กำลังแก้ข้อความอยู่'],
            'fk on sending' => ['fk', 'sending', 'กดส่งไปแล้ว ข้ามไม่ได้'],
            'fk on approved' => ['fk', 'approved', 'กดส่งไปแล้ว ข้ามไม่ได้'],
            'fk on sent' => ['fk', 'sent', 'ส่งให้ลูกค้าไปแล้ว'],
            'fk on expired' => ['fk', 'expired', 'หมดอายุแล้ว ไม่ได้ส่ง'],
            'fk on cancelled' => ['fk', 'cancelled', 'รายการนี้ถูกยกเลิกแล้ว ไม่ได้ส่ง'],
            'fk on editing' => ['fk', 'editing', 'กำลังแก้ข้อความอยู่'],
        ];
    }

    #[Test]
    #[DataProvider('statusTableCases')]
    public function fs_and_fk_on_other_statuses_answer_the_status(string $code, string $status, string $expectedAnswer): void
    {
        $seed = $this->seedWorld(['approval_status' => $status]);
        $beforeApproval = $this->approvalRow($seed['approval_id']);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData($code, $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, $expectedAnswer, true);
        self::assertCount(1, $requests);
        $this->assertRowChangedOnly($beforeApproval, $this->approvalRow($seed['approval_id']), []);
        self::assertSame($status, $this->approvalRow($seed['approval_id'])->status);
    }

    #[Test]
    public function fe_and_fc_by_allowed_person_on_live_card_answer_not_ready(): void
    {
        $seed = $this->seedWorld();
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        foreach (['fe', 'fc'] as $code) {
            [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData($code, $seed['approval_id'])));
            $response->assertOk()->assertExactJson(['ok' => true]);
            $this->assertSingleAnswer($requests, 'ปุ่มนี้ยังไม่เปิดใช้', true);
            self::assertCount(1, $requests);
            $this->noRowChanged($beforeApproval, $this->approvalRow($seed['approval_id']), $beforeLog, $this->logRow($seed['log_id']));
        }

        // คนนอกกด fe ยังไงก็ได้คำตอบ no_permission
        $outsider = ['from' => ['id' => 666, 'first_name' => 'คนแปลกหน้า']];
        [$response, $requests] = $this->tap($this->baseCallback($outsider, $this->cbData('fe', $seed['approval_id'])));
        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'ไม่มีสิทธิ์กดปุ่มนี้', true);
    }

    // ------------------------------------------------- 8) แข่งกับ writer อื่น

    #[Test]
    public function lost_race_writes_nothing_and_answers_the_state_found(): void
    {
        // รอยต่อ: DB::listen — เมื่อ service อ่าน follow_up_approvals ครั้งที่สอง (แบบล็อกในธุรกรรม)
        // writer อื่นพลิกสถานะเป็น approved ก่อนที่ธุรกรรมจะตัดสิน
        $seed = $this->seedWorld();
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        $selects = 0;
        $listen = function ($query) use (&$selects, $seed): void {
            // จุดเชื่อม: หลัง service อ่านครั้งแรก (นอกธุรกรรม) และ "ก่อน" อ่าครั้งที่สองในธุรกรรม
            // writer อื่นพลิกสถานะเป็น approved ตรงนั้นพอดี
            if (str_starts_with($query->sql, 'select * from "follow_up_approvals" where "id" = ?')) {
                $selects++;
                if ($selects === 1) {
                    DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update([
                        'status' => 'approved',
                        'updated_at' => '2026-11-16 09:59:59',
                    ]);
                }
            }
        };
        DB::listen($listen);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSingleAnswer($requests, 'กดส่งไปแล้ว กำลังส่งให้ลูกค้า', true);
        self::assertCount(1, $requests);

        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame('approved', $after->status);
        self::assertSame('2026-11-16 09:59:59', $after->updated_at, 'writer อื่นต้องชนะ — ค่าของเขาต้องอยู่ครบ');
        $this->assertRowChangedOnly($beforeApproval, $after, ['status', 'updated_at']);
        $this->assertRowChangedOnly($beforeLog, $this->logRow($seed['log_id']), []);

        // ถอด listener แข่งรันออกให้หมด — DB::listen ของ Laravel ผูกกับ app event dispatcher
        app('events')->forget(QueryExecuted::class);
    }

    // ------------------------------------------------- 9) คำตอบหรือการแก้การ์ดล้ม

    /** @return array<string, array<int, mixed>> */
    public static function answerFailureCases(): array
    {
        return [
            'answer 500 then edit ok' => ['answer_500'],
            'answer ok edit 500' => ['edit_500'],
            'answer timeout' => ['answer_timeout'],
            'answer 400' => ['answer_400'],
        ];
    }

    #[Test]
    #[DataProvider('answerFailureCases')]
    public function answer_or_edit_failure_leaves_rows_written(string $case): void
    {
        $seed = $this->seedWorld(['check_answers' => self::FLAGGED_CHECK]);
        $beforeLog = $this->logRow($seed['log_id']);

        $stub = match ($case) {
            'answer_500' => function ($request) {
                if ($this->methodOf($request) === 'answerCallbackQuery') {
                    return Http::response('<html>'.self::BODY_CANARY.'</html>', 500);
                }

                return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
            },
            'edit_500' => function ($request) {
                if ($this->methodOf($request) === 'editMessageText') {
                    return Http::response('<html>'.self::BODY_CANARY.'</html>', 500);
                }

                return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
            },
            'answer_timeout' => function ($request) {
                if ($this->methodOf($request) === 'answerCallbackQuery') {
                    throw new ConnectionException('cURL error 28 timeout');
                }

                return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
            },
            'answer_400' => function ($request) {
                if ($this->methodOf($request) === 'answerCallbackQuery') {
                    return Http::response(json_encode(['ok' => false, 'description' => self::BODY_CANARY]), 400);
                }

                return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
            },
        };

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])), stub: $stub);

        $response->assertOk()->assertExactJson(['ok' => true]);
        self::assertCount(2, $requests, 'คำตอบหนึ่งครั้ง แก้การ์ดหนึ่งครั้ง ไม่มี retry');

        $approval = $this->approvalRow($seed['approval_id']);
        self::assertSame('approved', $approval->status, 'ผลเขียนต้องคงอยู่แม้คำตอบ/การ์ดล้ม');
        self::assertTrue((bool) $approval->check_overridden);
        $log = $this->logRow($seed['log_id']);
        self::assertSame('approved', $log->delivery_status);
        $this->assertRowChangedOnly($beforeLog, $log, ['delivery_status', 'owner_action', 'owner_telegram_user_id', 'owner_action_at', 'check_overridden', 'updated_at']);
    }

    // ------------------------------------------------- 10) refreshCard

    /** @return array<string, array<int, mixed>> */
    public static function refreshBlockedCases(): array
    {
        return [
            'approval missing' => ['approval_missing'],
            'log missing' => ['log_missing'],
            'bot missing' => ['bot_missing'],
            'state new' => ['state_new'],
            'state sending' => ['state_sending'],
            'state unknown' => ['state_unknown'],
            'state failed' => ['state_failed'],
            'message id null' => ['message_null'],
            'plugin missing' => ['plugin_missing'],
            'plugin disabled' => ['plugin_disabled'],
            'plugin without token' => ['plugin_no_token'],
            'plugin chat differs' => ['plugin_chat_differs'],
            'unknown approval status' => ['status_bogus'],
        ];
    }

    #[Test]
    #[DataProvider('refreshBlockedCases')]
    public function refresh_card_blocked_table(string $case): void
    {
        $options = match ($case) {
            'state_new' => ['telegram_delivery_state' => 'new', 'telegram_message_id' => null],
            'state_sending' => ['telegram_delivery_state' => 'sending'],
            'state_unknown' => ['telegram_delivery_state' => 'unknown'],
            'state_failed' => ['telegram_delivery_state' => 'failed'],
            'message_null' => ['telegram_message_id' => null],
            default => [],
        };
        $seed = $this->seedWorld(array_merge($options, ['approval_status' => $case === 'status_bogus' ? 'bogus' : 'sent']));

        if ($case === 'approval_missing') {
            DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->delete();
        }
        if ($case === 'log_missing') {
            DB::table('lead_recovery_logs')->where('id', $seed['log_id'])->delete();
        }
        if ($case === 'bot_missing') {
            DB::table('bots')->where('id', $seed['bot']->id)->delete();
        }
        if ($case === 'plugin_missing') {
            DB::table('flow_plugins')->where('id', $seed['plugin']->id)->delete();
        }
        if ($case === 'plugin_disabled') {
            DB::table('flow_plugins')->where('id', $seed['plugin']->id)->update(['enabled' => false]);
        }
        if ($case === 'plugin_no_token') {
            DB::table('flow_plugins')->where('id', $seed['plugin']->id)->update(['config' => json_encode(['access_token' => '', 'chat_id' => self::PLUGIN_CHAT, 'authorized_user_ids' => [self::PERSON_ID]])]);
        }
        if ($case === 'plugin_chat_differs') {
            DB::table('flow_plugins')->where('id', $seed['plugin']->id)->update(['config' => json_encode(['access_token' => $this->pluginToken(), 'chat_id' => '777', 'authorized_user_ids' => [self::PERSON_ID]])]);
        }

        if ($case === 'approval_missing') {
            $beforeApproval = null;
        } else {
            $beforeApproval = $this->approvalRow($seed['approval_id']);
        }
        $beforeLog = $case === 'log_missing' ? null : $this->logRow($seed['log_id']);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
        });

        $result = app(FollowUpTelegramService::class)->refreshCard($seed['approval_id'], $this->now());

        self::assertSame('blocked', $result, $case);
        self::assertCount(0, $requests, 'blocked ต้องไม่ยิงออก');
        if ($beforeApproval !== null) {
            $this->assertRowChangedOnly($beforeApproval, $this->approvalRow($seed['approval_id']), []);
        }
        if ($beforeLog !== null) {
            $this->assertRowChangedOnly($beforeLog, $this->logRow($seed['log_id']), []);
        }
    }

    #[Test]
    public function refresh_card_unchanged_for_pending_and_editing(): void
    {
        foreach (['pending', 'editing'] as $status) {
            $seed = $this->seedWorld(['approval_status' => $status]);
            $beforeApproval = $this->approvalRow($seed['approval_id']);

            $requests = [];
            Http::fake(function ($request) use (&$requests) {
                $requests[] = $request;

                return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
            });

            $result = app(FollowUpTelegramService::class)->refreshCard($seed['approval_id'], $this->now());

            self::assertSame('unchanged', $result, $status);
            self::assertCount(0, $requests);
            $this->assertRowChangedOnly($beforeApproval, $this->approvalRow($seed['approval_id']), []);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public static function refreshStatusLineCases(): array
    {
        return [
            'approved' => ['approved', 'approved', null, '⏳ กดส่งแล้ว กำลังส่งให้ลูกค้า'],
            'sending' => ['sending', 'sending', null, '⏳ กดส่งแล้ว กำลังส่งให้ลูกค้า'],
            'sent' => ['sent', 'sent', null, '✅ ส่งให้ลูกค้าแล้ว'],
            'skipped' => ['skipped', 'skipped', null, '⏭ ข้ามแล้ว ไม่ได้ส่ง'],
            'expired' => ['expired', 'expired', 'expired_before_send', '⌛ หมดอายุ ไม่ได้ส่ง'],
            'cancelled new_customer_message' => ['cancelled', 'cancelled', 'new_customer_message', '🚫 ยกเลิก ไม่ได้ส่ง (ลูกค้าพิมพ์เข้ามาแล้ว)'],
            'cancelled new_bot_message' => ['cancelled', 'cancelled', 'new_bot_message', '🚫 ยกเลิก ไม่ได้ส่ง (บอทตอบในห้องนี้ไปแล้ว)'],
            'cancelled order_created' => ['cancelled', 'cancelled', 'order_created', '🚫 ยกเลิก ไม่ได้ส่ง (ลูกค้าสั่งซื้อแล้ว)'],
            'cancelled passed_slip' => ['cancelled', 'cancelled', 'passed_slip', '🚫 ยกเลิก ไม่ได้ส่ง (ลูกค้าสั่งซื้อแล้ว)'],
            'cancelled message_arrived' => ['cancelled', 'cancelled', 'message_arrived', '🚫 ยกเลิก ไม่ได้ส่ง (ลูกค้าพิมพ์เข้ามาแล้ว)'],
            'send_failed recipient_invalid' => ['cancelled', 'send_failed', 'recipient_invalid', '🚫 ยกเลิก ไม่ได้ส่ง (ส่งถึงลูกค้ารายนี้ไม่ได้)'],
            'send_failed unsafe_text' => ['cancelled', 'send_failed', 'unsafe_text', '🚫 ยกเลิก ไม่ได้ส่ง (ข้อความมีสิ่งที่ส่งไม่ได้)'],
            'send_failed line_rejected_400' => ['cancelled', 'send_failed', 'line_rejected_400', '🚫 ยกเลิก ไม่ได้ส่ง (LINE ไม่รับข้อความ)'],
            'send_failed acceptance_unknown_gate_changed' => ['cancelled', 'send_failed', 'acceptance_unknown_gate_changed', '⚠️ ส่งแล้วแต่ยืนยันผลไม่ได้ โปรดเปิดห้องตรวจ'],
            'send_failed acceptance_unknown_expired' => ['cancelled', 'send_failed', 'acceptance_unknown_expired', '⚠️ ส่งแล้วแต่ยืนยันผลไม่ได้ โปรดเปิดห้องตรวจ'],
            'send_failed acceptance_unknown_attempts' => ['cancelled', 'send_failed', 'acceptance_unknown_attempts', '⚠️ ส่งแล้วแต่ยืนยันผลไม่ได้ โปรดเปิดห้องตรวจ'],
            'waiting retry sending' => ['sending', 'retry', null, '⏳ กดส่งแล้ว กำลังส่งให้ลูกค้า'],
        ];
    }

    #[Test]
    #[DataProvider('refreshStatusLineCases')]
    public function refresh_card_status_lines_table(string $approvalStatus, string $logStatus, ?string $reasonCode, string $expectedLine): void
    {
        // จับข้อความที่ publish ยิง "ก่อน" — บัตรต้องยัง new (publish ทำงานได้เฉพาะ new+pending)
        $seed = $this->seedWorld(['telegram_delivery_state' => 'new', 'telegram_message_id' => null]);
        $publishText = $this->publishTextForSameRows($seed['approval_id']);

        DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update(['status' => $approvalStatus]);
        DB::table('lead_recovery_logs')->where('id', $seed['log_id'])->update([
            'delivery_status' => $logStatus,
            'reason_code' => $reasonCode,
        ]);
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
        });

        $result = app(FollowUpTelegramService::class)->refreshCard($seed['approval_id'], $this->now());

        self::assertSame('refreshed', $result, $approvalStatus.'/'.$logStatus.'/'.$reasonCode);
        self::assertCount(1, $requests);
        self::assertSame($publishText."\n\n".$expectedLine, $requests[0]['text'], 'ข้อความก่อนบรรทัดสถานะต้องตรงกับที่ publish ส่ง');
        self::assertSame(json_encode(['inline_keyboard' => []]), $requests[0]['reply_markup']);
        $this->assertRowChangedOnly($beforeApproval, $this->approvalRow($seed['approval_id']), []);
        $this->assertRowChangedOnly($beforeLog, $this->logRow($seed['log_id']), []);
    }

    #[Test]
    public function refresh_card_golden_after_send_tap(): void
    {
        $seed = $this->seedWorld(['golden_ids' => true]);

        [$response] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));
        $response->assertOk()->assertExactJson(['ok' => true]);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
        });

        $result = app(FollowUpTelegramService::class)->refreshCard($seed['approval_id'], $this->now());

        self::assertSame('refreshed', $result);
        self::assertCount(1, $requests);
        self::assertSame(self::GOLDEN_AFTER_SEND, $requests[0]['text'], 'การ์ดหลังกดส่งต้องตรง golden คำต่อคำ');
    }

    #[Test]
    public function refresh_card_golden_after_skip_tap(): void
    {
        $seed = $this->seedWorld(['golden_ids' => true]);

        [$response] = $this->tap($this->baseCallback(data: $this->cbData('fk', $seed['approval_id'])));
        $response->assertOk()->assertExactJson(['ok' => true]);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
        });

        $result = app(FollowUpTelegramService::class)->refreshCard($seed['approval_id'], $this->now());

        self::assertSame('refreshed', $result);
        self::assertCount(1, $requests);
        self::assertSame(self::GOLDEN_AFTER_SKIP, $requests[0]['text'], 'การ์ดหลังกดข้ามต้องตรง golden คำต่อคำ');
    }

    #[Test]
    public function refresh_card_golden_cancelled_with_reason(): void
    {
        // บัตรรุ่น golden + ยัง new เพื่อให้ publish ยิงข้อความตั้งต้นได้ (แล้วแถวถึงสถานะ sent)
        $seed = $this->seedWorld(['golden_ids' => true, 'telegram_delivery_state' => 'new', 'telegram_message_id' => null]);
        $publishText = $this->publishTextForSameRows($seed['approval_id']);

        DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update(['status' => 'cancelled']);
        DB::table('lead_recovery_logs')->where('id', $seed['log_id'])->update([
            'delivery_status' => 'cancelled',
            'reason_code' => 'new_customer_message',
        ]);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
        });

        $result = app(FollowUpTelegramService::class)->refreshCard($seed['approval_id'], $this->now());

        self::assertSame('refreshed', $result);
        self::assertCount(1, $requests);
        self::assertSame(self::GOLDEN_CANCELLED, $requests[0]['text']);
        self::assertSame($publishText."\n\n".'🚫 ยกเลิก ไม่ได้ส่ง (ลูกค้าพิมพ์เข้ามาแล้ว)', $requests[0]['text']);
    }

    /** @return array<string, array{0: string}> */
    public static function refreshTransportFailureCases(): array
    {
        return [
            'edit 400 rejected' => ['rejected'],
            'edit 500 unknown' => ['unknown'],
        ];
    }

    #[Test]
    #[DataProvider('refreshTransportFailureCases')]
    public function refresh_card_transport_failure_maps_result_and_writes_nothing(string $case): void
    {
        $seed = $this->seedWorld(['approval_status' => 'approved']);
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        $requests = [];
        Http::fake(function ($request) use (&$requests, $case) {
            $requests[] = $request;

            return $case === 'rejected'
                ? Http::response(json_encode(['ok' => false, 'description' => self::BODY_CANARY]), 400)
                : Http::response('<html>'.self::BODY_CANARY.'</html>', 500);
        });

        $result = app(FollowUpTelegramService::class)->refreshCard($seed['approval_id'], $this->now());

        self::assertSame($case === 'rejected' ? 'failed' : 'unknown', $result);
        self::assertCount(1, $requests);
        $this->assertRowChangedOnly($beforeApproval, $this->approvalRow($seed['approval_id']), []);
        $this->assertRowChangedOnly($beforeLog, $this->logRow($seed['log_id']), []);
    }

    #[Test]
    public function refresh_card_keeps_buttons_on_unknown_delivery_state_of_blocked_rows(): void
    {
        // แถว pending (การ์ดยังมีปุ่ม) ต้องไม่ถูกแตะ
        $seed = $this->seedWorld();
        $beforeApproval = $this->approvalRow($seed['approval_id']);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
        });

        $result = app(FollowUpTelegramService::class)->refreshCard($seed['approval_id'], $this->now());

        self::assertSame('unchanged', $result);
        self::assertCount(0, $requests);
        $this->assertRowChangedOnly($beforeApproval, $this->approvalRow($seed['approval_id']), []);
    }
}
