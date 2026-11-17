<?php

namespace Tests\Feature\FollowUp;

use App\Jobs\CheckEditedFollowUp;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\User;
use App\Services\FollowUp\FollowUpApprovalService;
use App\Services\FollowUp\FollowUpSendService;
use App\Services\FollowUp\FollowUpTelegramService;
use App\Services\Payment\TelegramAlertBotService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
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
 * การ์ด B6c: ปุ่มแก้ข้อความบนการ์ด Telegram
 *
 * ครอบคลุม:
 * - route: update แบบ message -> FollowUpApprovalService::editReply, ตอบ 200 {ok:true} เสมอ (D80)
 * - editReply: ห้อง/รูปร่าง/คน เงียบ, การผูก, กติกาข้อความของเจ้านาย (D86), เซฟรุ่นใหม่ (D83)
 * - ปุ่ม fe: ช่อง ForceReply ทองคำ, ผูก editor, แพ้แข่ง, สถานะอื่น (D88)
 * - ปุ่ม fs/fc บนบัตรที่แก้: ยืนยันส่งหนึ่งแตะ (D84), ประตู (D78)
 * - syncCard: การ์ดเต็มรูปบน pending + บัตรแก้แล้ว, refreshCard คงเดิม (D82/D87)
 * - การขนส่ง sendFollowUpText + setWebhook allowed_updates
 *
 * ไม่มีการเรียกจริงออกนอกเครื่อง ยกเว้น socket ไป port ปิดในเครื่องหนึ่งครั้งตามการ์ด
 */
class TelegramEditTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 10:00:00';

    private const CHAT_ID = '888';

    private const PLUGIN_CHAT = '888';

    private const PERSON_ID = 555;

    private const UPDATE_ID = 999001;

    private const NONCE = 'abcd1234abcd1234';

    private const NEW_NONCE = 'ffff0000ffff0000';

    private const HTML_CANARY = 'htmlcanary-c';

    private const BODY_CANARY = 'bodycanary-c';

    private const ECHO_CANARY = 'canaryEcho77c';

    private const CLEAN_CHECK = '{"scores":{"safe_to_send":0.9},"failed":[],"error":false,"version":1}';

    private const FLAGGED_CHECK = '{"scores":{"safe_to_send":0.9},"failed":["pressure"],"error":false,"version":1}';

    private const DRAFT = "สวัสดีครับพี่ เมื่อวานที่คุยเรื่องบัญชีโฆษณาไว้\nมีจุดไหนให้ช่วยดูเพิ่มไหมครับ";

    private const EDITED_TEXT = "สวัสดีครับพี่ ผมเช็กให้แล้วนะครับ\nยังสนใจอยู่ไหมครับ 🙏";

    private const EXPIRES = '2026-11-16 22:12:00';

    /** golden จาก b6c-strings.json (sha256 d58ff51c…) — คัดลอกมาแบบคำต่อคำ */
    private const GOLDEN_PROMPT = <<<'TEXT'
✏️ แก้ข้อความ #123
ตอบกลับ (Reply) ข้อความนี้ด้วยข้อความใหม่ที่จะส่งให้ลูกค้า
ไม่เกิน 400 ตัวอักษร 3 บรรทัด · หมดอายุ 16/11 22:12
เปลี่ยนใจ: กด ส่ง หรือ ข้าม บนการ์ดได้เลย
TEXT;

    private const GOLDEN_AFTER_EDIT = <<<'TEXT'
🔔 <b>ตามลูกค้าที่เงียบไป</b> · #123
บอท: Line - Adsvance
ลูกค้า: สมชาย &lt;ร้าน A&amp;B&gt;
กลุ่ม: สนใจแต่ยังลังเล · Luna มั่นใจ 86%
ลูกค้าพิมพ์ล่าสุด: ขอคิดดูก่อนนะครับ
บอทตอบล่าสุด: ได้เลยครับพี่ สนใจตัวไหนทักมาได้เลยครับ

<b>ร่างข้อความ</b> · ✏️ แก้แล้ว
สวัสดีครับพี่ ผมเช็กให้แล้วนะครับ
ยังสนใจอยู่ไหมครับ 🙏

ตรวจข้อความ: ⏳ ยังไม่ได้ตรวจ
ข้อความนี้เจ้านายแก้เอง กด ยืนยันส่ง ถ้าจะส่งทั้งที่ยังไม่ผ่านการตรวจ
หมดอายุ: 16/11 22:12 (12 ชั่วโมง)
เปิดห้อง: https://admin.example.test/chat?botId=26&amp;conversationId=1317
TEXT;

    private const GOLDEN_AFTER_EDIT_CLEAN_CHECK = <<<'TEXT'
🔔 <b>ตามลูกค้าที่เงียบไป</b> · #123
บอท: Line - Adsvance
ลูกค้า: สมชาย &lt;ร้าน A&amp;B&gt;
กลุ่ม: สนใจแต่ยังลังเล · Luna มั่นใจ 86%
ลูกค้าพิมพ์ล่าสุด: ขอคิดดูก่อนนะครับ
บอทตอบล่าสุด: ได้เลยครับพี่ สนใจตัวไหนทักมาได้เลยครับ

<b>ร่างข้อความ</b> · ✏️ แก้แล้ว
สวัสดีครับพี่ ผมเช็กให้แล้วนะครับ
ยังสนใจอยู่ไหมครับ 🙏

ตรวจข้อความ: ✅ ผ่าน
หมดอายุ: 16/11 22:12 (12 ชั่วโมง)
เปิดห้อง: https://admin.example.test/chat?botId=26&amp;conversationId=1317
TEXT;

    private const LINE_QUOTA_URL = 'https://api.line.me/v2/bot/message/quota';

    private const LINE_CONSUMPTION_URL = 'https://api.line.me/v2/bot/message/quota/consumption';

    private const LINE_PUSH_URL = 'https://api.line.me/v2/bot/message/push';

    private const LINE_REQUEST_ID = 'req-canary-c';

    /** @var list<MessageLogged> */
    private array $logEvents = [];

    private ?string $worldToken = null;

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
            self::assertStringNotContainsString($this->pluginToken(), $text, 'token ห้ามลง log');
            if ($this->worldToken !== null) {
                self::assertStringNotContainsString($this->worldToken, $text, 'token ห้ามลง log');
            }
            self::assertStringNotContainsString(self::PLUGIN_CHAT, $text, 'chat id ห้ามลง log');
            self::assertStringNotContainsString(self::NONCE, $text, 'nonce ห้ามลง log');
            self::assertStringNotContainsString(self::NEW_NONCE, $text, 'nonce ห้ามลง log');
            self::assertStringNotContainsString(self::HTML_CANARY, $text, 'ข้อความห้ามลง log');
            self::assertStringNotContainsString(self::BODY_CANARY, $text, 'response body ห้ามลง log');
        }
    }

    private function assertNoBotMarkerInLog(): void
    {
        foreach ($this->logEvents as $event) {
            $text = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString('/bot', $text);
            self::assertStringNotContainsString($this->pluginToken(), $text);
        }
    }

    private function cbData(string $code, int $approvalId, int $revision = 1, string $nonce = self::NONCE): string
    {
        return $code.'|'.$approvalId.'|'.$revision.'|'.$nonce;
    }

    private function methodOf(object $request): string
    {
        $path = (string) $request->url();

        return substr($path, (int) strrpos($path, '/') + 1);
    }

    /** ยิง body เข้า route จริงใต้ Http::fake — คืน [TestResponse, รายการ request]
     * ทุกครั้งเริ่ม factory ใหม่ (Http::swap) เพื่อให้ stub ระยะก่อนหน้าใน test เดียวกันไม่ตามมา
     * (Laravel เรียก stub ทุกตัวที่ลงทะเบียนไว้ต่อ request — ตัวแรกที่คืน response ชนะ)
     */
    private function fire(array $body, ?string $token = null, ?string $secret = 'webhook-secret-test', ?\Closure $stub = null): array
    {
        $requests = [];
        $this->resetLogCaptures();
        Http::swap(new Factory);
        Http::fake(function ($request) use (&$requests, $stub) {
            $requests[] = $request;

            return $stub === null
                ? Http::response(json_encode(['ok' => true, 'result' => true]), 200)
                : $stub($request);
        });

        $headers = [];
        if ($secret !== null) {
            $headers['X-Telegram-Bot-Api-Secret-Token'] = $secret;
        }

        $response = $this->withHeaders($headers)
            ->postJson('/api/webhook/telegram-alert/'.($token ?? $this->worldToken ?? $this->pluginToken()), $body);

        return [$response, $requests];
    }

    /** stub ที่ sendMessage คืน message id ที่รันเพิ่มทีละ 1 เริ่มจาก 55 — จำลองช่องแก้จริง */
    private function stubWithMessageIds(array &$requests, int $firstMessageId = 55): \Closure
    {
        $nextId = $firstMessageId;

        return function ($request) use (&$requests, &$nextId) {
            $requests[] = $request;
            if ($this->methodOf($request) === 'sendMessage') {
                return Http::response(json_encode(['ok' => true, 'result' => ['message_id' => $nextId++]]), 200);
            }

            return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
        };
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
        self::assertSame($text, $answers[0]['text']);
        self::assertSame($alert, $answers[0]['show_alert'], 'show_alert ต้องเป็น JSON boolean ที่ถูกต้อง');
    }

    private function assertSingleEdit(array $requests, ?string $expectedText = null, ?array $expectedKeyboard = null): void
    {
        $edits = array_values(array_filter($requests, fn ($r) => $this->methodOf($r) === 'editMessageText'));
        self::assertCount(1, $edits, 'ต้องแก้การ์ดครั้งเดียว');
        self::assertSame('HTML', $edits[0]['parse_mode']);
        self::assertSame(json_encode(['is_disabled' => true]), $edits[0]['link_preview_options']);
        if ($expectedKeyboard !== null) {
            self::assertSame(json_encode(['inline_keyboard' => $expectedKeyboard]), $edits[0]['reply_markup']);
        }
        if ($expectedText !== null) {
            self::assertSame($expectedText, $edits[0]['text']);
        }
    }

    private function assertSingleSend(array $requests, string $expectedText, ?int $replyToMessageId = null, ?string $placeholder = null): void
    {
        $sends = array_values(array_filter($requests, fn ($r) => $this->methodOf($r) === 'sendMessage'));
        self::assertCount(1, $sends, 'ต้องโพสต์ข้อความครั้งเดียว');
        self::assertSame($expectedText, $sends[0]['text']);
        self::assertArrayNotHasKey('parse_mode', $sends[0], 'ข้อความล้วนห้ามมี parse_mode');
        self::assertSame(json_encode(['is_disabled' => true]), $sends[0]['link_preview_options']);
        if ($replyToMessageId !== null) {
            self::assertSame(json_encode(['message_id' => $replyToMessageId, 'allow_sending_without_reply' => true]), $sends[0]['reply_parameters']);
        }
        if ($placeholder !== null) {
            self::assertSame(json_encode(['force_reply' => true, 'input_field_placeholder' => $placeholder]), $sends[0]['reply_markup']);
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

    /** ผูกบัตรกับคน 555 + ช่องแก้ message id 55 — จุดเริ่มของทุกกรณีตอบช่อง */
    protected function bindPrompt(array $options = [], int $promptId = 55): array
    {
        $overrides = array_merge([
            'editor_telegram_user_id' => (string) self::PERSON_ID,
            'edit_prompt_message_id' => $promptId,
        ], $options['approval_overrides'] ?? []);
        unset($options['approval_overrides']);

        return $this->seedWorld(array_merge($options, ['approval_overrides' => $overrides]));
    }

    /** update แบบ message ที่ตอบช่องแก้ id 55 */
    private function replyMessage(array $overrides = [], ?array $replyOverrides = null): array
    {
        $reply = array_merge([
            'message_id' => 55,
            'date' => 1700000000,
            'chat' => ['id' => 888, 'type' => 'private'],
            'from' => ['id' => 777000, 'is_bot' => true, 'first_name' => 'Luna Bot'],
            'text' => self::GOLDEN_PROMPT,
        ], $replyOverrides ?? []);
        if (array_key_exists('text', $reply) && $reply['text'] === null) {
            unset($reply['text']);
        }

        return array_merge([
            'message_id' => 900,
            'from' => ['id' => self::PERSON_ID, 'first_name' => 'เจ้านาย'],
            'chat' => ['id' => 888, 'type' => 'private'],
            'date' => 1700000001,
            'reply_to_message' => $reply,
            'text' => self::EDITED_TEXT,
        ], $overrides);
    }

    private function postReply(array $message, int $updateId = self::UPDATE_ID, ?\Closure $stub = null, ?string $secret = 'webhook-secret-test'): array
    {
        $body = ['message' => $message];
        if ($updateId !== 0) {
            $body['update_id'] = $updateId;
        }

        return $this->fire($body, stub: $stub, secret: $secret);
    }

    /** @return array<string, mixed> */
    protected function seedWorld(array $options = []): array
    {
        $get = fn (string $key, mixed $default = null) => array_key_exists($key, $options) ? $options[$key] : $default;

        $user = User::factory()->owner()->create();
        $golden = (bool) $get('golden_ids', false);
        $botAttributes = [
            'user_id' => $user->id,
            'name' => $get('bot_name', 'Line - Adsvance'),
            'primary_chat_model' => 'openai/gpt-6-luna',
            'channel_access_token' => 'line-token-c',
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
            'external_customer_id' => 'Ub6c000112233445566778899aabbccdd',
            'is_handover' => $get('is_handover', false),
            'status' => $get('room_status', 'active'),
        ];
        if ($golden) {
            $roomAttributes['id'] = 1317;
        }
        $room = Conversation::factory()->line()->create($roomAttributes);

        // ข้อความลูกค้า (anchor) ต้องเป็นข้อความสุดท้าย เพื่อให้ recheck ผ่าน
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

    /** pin nonce ของการเซฟข้อความที่แก้ — ทางผ่าน seam ใน FollowUpApprovalService */
    private function bindPinnedNonce(string $nonce): void
    {
        $telegram = app(TelegramAlertBotService::class);
        $cardService = app(FollowUpTelegramService::class);
        $this->app->instance(FollowUpApprovalService::class, new class($telegram, $cardService, $nonce) extends FollowUpApprovalService
        {
            public function __construct(TelegramAlertBotService $telegram, FollowUpTelegramService $cardService, private readonly string $pinnedNonce)
            {
                parent::__construct($telegram, $cardService);
            }

            protected function newNonce(): string
            {
                return $this->pinnedNonce;
            }
        });
    }

    private function tap(array $callback, ?string $secret = 'webhook-secret-test', ?\Closure $stub = null, ?int $updateId = self::UPDATE_ID): array
    {
        $body = ['callback_query' => $callback];
        if ($updateId !== null) {
            $body['update_id'] = $updateId;
        }

        return $this->fire($body, secret: $secret, stub: $stub);
    }

    private function baseCallback(array $overrides = [], ?string $data = null): array
    {
        return array_merge([
            'id' => 'cb-canary-c',
            'from' => ['id' => self::PERSON_ID, 'first_name' => 'เจ้านาย'],
            'message' => ['message_id' => 77, 'chat' => ['id' => 888]],
            'data' => $data,
        ], $overrides);
    }

    /** ส่งข้อความตอบช่องแก้แล้วเทียบ golden ที่ไม่ใช่ข้อความ — คืน [บัตรก่อนบันทึก, บัตรหลังบันทึก] */
    private function assertNoLineCalls(): void
    {
        foreach ($this->logEvents as $event) {
            $text = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString('line-token', $text);
        }
    }

    private function pluginToken(): string
    {
        return 'tg-plugin-token-c';
    }

    // ---------------------------------------------------------------- 1) route

    #[Test]
    public function message_update_that_answers_the_prompt_saves_and_answers_ok(): void
    {
        $this->bindPinnedNonce(self::NEW_NONCE);
        $seed = $this->bindPrompt(['golden_ids' => true, 'check_answers' => null]);
        $requests = [];
        [$response, $requests] = $this->postReply($this->replyMessage(), stub: $this->stubWithMessageIds($requests));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertOnlyTelegramHost($requests);
        self::assertCount(2, $requests, 'เซฟต้องระบายการ์ดหนึ่งครั้งแล้วแจ้งคนหนึ่งครั้ง');
        self::assertSame('editMessageText', $this->methodOf($requests[0]));
        self::assertSame('sendMessage', $this->methodOf($requests[1]));

        $approval = $this->approvalRow($seed['approval_id']);
        self::assertSame(2, (int) $approval->revision);
        self::assertSame(self::EDITED_TEXT, $approval->text);
        self::assertNoSecretsInLog();
        $this->assertNoLineCalls();
    }

    #[Test]
    public function non_message_updates_stay_silent(): void
    {
        $seed = $this->bindPrompt();

        foreach ([
            'edited_message' => ['edited_message' => $this->replyMessage()],
            'channel_post' => ['channel_post' => $this->replyMessage()],
            'empty update' => [],
        ] as $label => $body) {
            [$response, $requests] = $this->fire($body);
            $response->assertOk();
            self::assertCount(0, $requests, $label.' ต้องเงียบ');
            $this->assertNoSecretsInLog();
        }

        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame(1, (int) $after->revision);
    }

    #[Test]
    public function message_update_with_bad_secret_is_401_and_calls_nothing(): void
    {
        $seed = $this->bindPrompt();
        [$response, $requests] = $this->postReply($this->replyMessage(), secret: 'wrong-secret');

        $response->assertStatus(401);
        self::assertCount(0, $requests);
        self::assertSame(1, (int) $this->approvalRow($seed['approval_id'])->revision);
        $this->assertNoSecretsInLog();
    }

    #[Test]
    public function message_update_with_unknown_plugin_is_404_and_calls_nothing(): void
    {
        $seed = $this->bindPrompt();
        [$response, $requests] = $this->fire(['message' => $this->replyMessage(), 'update_id' => self::UPDATE_ID], token: 'tg-plugin-token-missing');

        $response->assertStatus(404);
        self::assertCount(0, $requests);
        self::assertSame(1, (int) $this->approvalRow($seed['approval_id'])->revision);
    }

    #[Test]
    public function callback_query_with_message_key_takes_the_tap_path_only(): void
    {
        $seed = $this->seedWorld();
        $before = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        // message key ที่ "จะถูก" ทุกอย่างถ้าฝั่ง editReply ยอมรับ — ต้องไม่มีผล
        $callback = $this->baseCallback(data: $this->cbData('fs', $seed['approval_id']));
        $body = array_merge(['callback_query' => $callback, 'update_id' => self::UPDATE_ID], ['message' => $this->replyMessage()]);
        [$response, $requests] = $this->fire($body);

        $response->assertOk();
        self::assertCount(2, $requests, 'tap เท่านั้น: ตอบปุ่ม + แก้การ์ด ไม่มี sendMessage');
        $this->assertSingleAnswer($requests, 'รับคำสั่งแล้ว กำลังส่งให้ลูกค้า', false);
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame('approved', $after->status);
        self::assertSame('sent', $this->logRow($seed['log_id'])->owner_action);
        $this->assertRowChangedOnly($before, $after, ['status', 'check_overridden', 'updated_at', 'last_update_id']);
        $this->assertNoSecretsInLog();
    }

    // ---------------------------------------------------------------- 2) editReply เงียบ (ห้อง/รูปร่าง/คน)

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: array}> */
    public static function editReplySilentProvider(): array
    {
        return [
            'other chat' => ['other chat', [], ['chat' => ['id' => 999]]],
            'no reply_to_message' => ['no reply', [], ['reply_to_message' => null]],
            'human reply' => ['human reply', [], [], ['from' => ['id' => 777000, 'is_bot' => false]]],
            'reply is_bot missing' => ['is_bot missing', [], [], ['from' => ['id' => 777000]]],
            'replied text without marker' => ['no marker', [], [], ['text' => '🔔 ตามลูกค้าที่เงียบไป · #123']],
            'replied message_id as string' => ['reply id string', [], [], ['message_id' => '55']],
            'from.id missing' => ['from missing', [], ['from' => ['first_name' => 'x']]],
            'from.id zero' => ['from zero', [], ['from' => ['id' => 0]]],
            'from.id string' => ['from string', [], ['from' => ['id' => '555']]],
            'person outside allowlist' => ['outside allowlist', ['allowlist' => [999]], []],
            'allowlist missing' => ['allowlist missing', ['allowlist_missing' => true], []],
            'allowlist empty list' => ['allowlist empty', ['allowlist' => []], []],
        ];
    }

    #[Test]
    #[DataProvider('editReplySilentProvider')]
    public function edit_reply_silent_cases_never_read_the_approval_table(string $label, array $seedOptions, array $messageOverrides, array $replyOverrides = []): void
    {
        $seed = $this->bindPrompt($seedOptions);
        $before = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count): void {
            if (str_contains($query->sql, 'from "follow_up_approvals"')) {
                $count++;
            }
        });
        [$response, $requests] = $this->postReply($this->replyMessage($messageOverrides, $replyOverrides));
        app('events')->forget(QueryExecuted::class);

        $response->assertOk();
        self::assertCount(0, $requests, $label.' ต้องเงียบไม่ยิงออก');
        self::assertSame(0, $count, $label.' ต้องไม่อ่านตาราง follow_up_approvals');
        $this->noRowChanged($before, $this->approvalRow($seed['approval_id']), $beforeLog, $this->logRow($seed['log_id']));
        $this->assertNoSecretsInLog();
    }

    // ---------------------------------------------------------------- 3) editReply การผูกและสถานะ

    private const NOTICE_EDIT_GONE = 'ข้อความแก้นี้ใช้ไม่ได้แล้ว กดปุ่ม แก้ข้อความ บนการ์ดอีกครั้ง';

    private const NOTICE_EDIT_CLOSED = 'รายการนี้ปิดไปแล้ว แก้ข้อความไม่ได้';

    private const NOTICE_EDIT_NOT_EDITOR = 'ตอบได้เฉพาะคนที่กดปุ่ม แก้ข้อความ ถ้าจะแก้เอง กดปุ่มบนการ์ดก่อน';

    private const NOTICE_EDIT_EXPIRED = 'รายการนี้หมดอายุแล้ว ไม่ได้ส่ง';

    private const NOTICE_EDIT_SAVED = '✅ บันทึกข้อความใหม่ของ #{log_id} แล้ว ดูที่การ์ดด้านบน';

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: ?\Closure, 3: string, 4: array}> */
    public static function editReplyBindingProvider(): array
    {
        return [
            'unknown prompt id' => ['unknown prompt', [], null, self::NOTICE_EDIT_GONE, ['message_id' => 66]],
            'approval bound to other plugin' => ['other plugin', [], function (array $seed): void {
                $otherPlugin = FlowPlugin::create([
                    'flow_id' => $seed['flow']->id,
                    'type' => 'telegram',
                    'name' => 'อื่น',
                    'enabled' => true,
                    'trigger_condition' => 'always',
                    'config' => ['access_token' => 'other-token', 'chat_id' => self::PLUGIN_CHAT],
                ]);
                DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update(['telegram_plugin_id' => $otherPlugin->id]);
            }, self::NOTICE_EDIT_GONE, []],
            'approval stored chat differs' => ['other stored chat', ['approval_overrides' => ['telegram_chat_id' => '999']], null, self::NOTICE_EDIT_GONE, []],
            'approval whose log belongs to another bot' => ['other bot log', [], function (array $seed): void {
                $user = User::factory()->owner()->create();
                $bot2 = Bot::factory()->active()->line()->create(['user_id' => $user->id]);
                $flow2 = Flow::factory()->create(['bot_id' => $bot2->id]);
                $bot2->update(['default_flow_id' => $flow2->id]);
                DB::table('lead_recovery_logs')->where('id', $seed['log_id'])->update(['bot_id' => $bot2->id]);
            }, self::NOTICE_EDIT_GONE, []],
            'two approvals bound to the same prompt' => ['two bindings', [], function (array $seed): void {
                $log2 = (array) DB::table('lead_recovery_logs')->where('id', $seed['log_id'])->first();
                unset($log2['id']);
                // unique (conversation_id, silence_user_message_id) — เปลี่ยน anchor ให้ log ใหม่
                $anchor2 = (int) DB::table('messages')->insertGetId([
                    'conversation_id' => $seed['room']->id,
                    'sender' => 'user',
                    'content' => 'ยังคิดอยู่ครับ',
                    'type' => 'text',
                    'created_at' => '2026-11-15 07:58:30',
                    'updated_at' => '2026-11-15 07:58:30',
                ]);
                $log2['silence_user_message_id'] = $anchor2;
                $log2Id = (int) DB::table('lead_recovery_logs')->insertGetId($log2);
                $approval2 = (array) DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->first();
                unset($approval2['id']);
                $approval2['lead_recovery_log_id'] = $log2Id;
                DB::table('follow_up_approvals')->insert($approval2);
            }, self::NOTICE_EDIT_GONE, []],
            'status approved' => ['approved', ['approval_status' => 'approved'], null, self::NOTICE_EDIT_CLOSED, []],
            'status sending' => ['sending', ['approval_status' => 'sending'], null, self::NOTICE_EDIT_CLOSED, []],
            'status sent' => ['sent', ['approval_status' => 'sent'], null, self::NOTICE_EDIT_CLOSED, []],
            'status skipped' => ['skipped', ['approval_status' => 'skipped'], null, self::NOTICE_EDIT_CLOSED, []],
            'status expired' => ['expired', ['approval_status' => 'expired'], null, self::NOTICE_EDIT_CLOSED, []],
            'status cancelled' => ['cancelled', ['approval_status' => 'cancelled'], null, self::NOTICE_EDIT_CLOSED, []],
            'log not pending' => ['log approved', ['log_delivery_status' => 'approved'], null, self::NOTICE_EDIT_CLOSED, []],
            'expires exactly now' => ['expires now', ['expires_at' => self::NOW], null, self::NOTICE_EDIT_EXPIRED, []],
            'expires one second before' => ['expires before', ['expires_at' => '2026-11-16 09:59:59'], null, self::NOTICE_EDIT_EXPIRED, []],
        ];
    }

    #[Test]
    #[DataProvider('editReplyBindingProvider')]
    public function edit_reply_binding_and_open_cases_send_one_notice_and_write_nothing(string $label, array $seedOptions, ?\Closure $mutate, string $expectedNotice, array $replyOverrides): void
    {
        $seed = $this->bindPrompt($seedOptions);
        if ($mutate !== null) {
            $mutate($seed);
        }
        $before = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        [$response, $requests] = $this->postReply($this->replyMessage([], $replyOverrides));

        $response->assertOk();
        $this->assertSingleSend($requests, $expectedNotice, 900);
        self::assertSame(1, (int) $this->approvalRow($seed['approval_id'])->revision, $label.' ห้ามเขียนรุ่นใหม่');
        $this->noRowChanged($before, $this->approvalRow($seed['approval_id']) ?? $before, $beforeLog, $this->logRow($seed['log_id']) ?? $beforeLog);
        $this->assertNoSecretsInLog();
    }

    #[Test]
    public function edit_reply_one_second_before_expiry_still_saves(): void
    {
        // ขอบเขตการรับ: หมดอายุที่ now+1s ยังเซฟได้ (ตารางข้างบนมีแต่ now กับ now-1s)
        $seed = $this->bindPrompt(['expires_at' => '2026-11-16 10:00:01']);
        $requests = [];

        [$response, $requests] = $this->postReply($this->replyMessage(), stub: $this->stubWithMessageIds($requests));

        $response->assertOk();
        self::assertCount(2, $requests, 'เซฟ = ระบายการ์ดก่อนแล้วแจ้งคน');
        $this->assertSingleSend($requests, str_replace('{log_id}', (string) $seed['log_id'], self::NOTICE_EDIT_SAVED), 900);
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame(2, (int) $after->revision, 'เหลือหนึ่งวินาทียังเซฟได้');
        self::assertSame(self::EDITED_TEXT, $after->text);
    }

    #[Test]
    public function edit_reply_to_the_previous_prompt_after_a_second_edit_tap_gets_edit_gone(): void
    {
        $seed = $this->bindPrompt();
        $requests = [];
        $stub = $this->stubWithMessageIds($requests, 200);

        // Edit สองครั้ง — update ต่าง id เพื่อไม่โดนจับว่าส่งซ้ำ; การผูกย้ายไปช่องใหม่เสมอ
        [, $ignored1] = $this->tap($this->baseCallback(data: $this->cbData('fe', $seed['approval_id'])), stub: $stub, updateId: 1001);
        [, $ignored2] = $this->tap($this->baseCallback(data: $this->cbData('fe', $seed['approval_id'])), stub: $stub, updateId: 1002);
        self::assertSame(201, $this->approvalRow($seed['approval_id'])->edit_prompt_message_id);

        [$response, $requests] = $this->postReply($this->replyMessage(replyOverrides: ['message_id' => 200]));

        $response->assertOk();
        self::assertCount(1, $requests);
        $this->assertSingleSend($requests, self::NOTICE_EDIT_GONE, 900);
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame(201, $after->edit_prompt_message_id);
        self::assertSame(1, (int) $after->revision);
    }

    #[Test]
    public function edit_reply_by_the_non_editor_gets_the_not_editor_notice(): void
    {
        $seed = $this->bindPrompt(['approval_overrides' => [
            'editor_telegram_user_id' => '999',
            'edit_prompt_message_id' => 55,
        ]]);
        $before = $this->approvalRow($seed['approval_id']);

        [$response, $requests] = $this->postReply($this->replyMessage());

        $response->assertOk();
        $this->assertSingleSend($requests, self::NOTICE_EDIT_NOT_EDITOR, 900);
        $this->noRowChanged($before, $this->approvalRow($seed['approval_id']), $this->logRow($seed['log_id']), $this->logRow($seed['log_id']));
    }

    // ---------------------------------------------------------------- 4) editReply ชนิดข้อความและกติกา

    private const EDIT_RETRY_SUFFIX = ' — ตอบกลับข้อความ แก้ข้อความ เดิมอีกครั้งได้เลย';

    private const INVALID_NOT_TYPED = 'รับเฉพาะข้อความที่พิมพ์เอง (ไม่รับรูป สติกเกอร์ หรือข้อความที่ส่งต่อมา)';

    private const INVALID_COMMAND = 'ข้อความขึ้นต้นด้วย / ใช้ไม่ได้';

    private const INVALID_LENGTH = 'ข้อความต้องยาว 1–400 ตัวอักษร';

    private const INVALID_LINES = 'ข้อความต้องไม่เกิน 3 บรรทัด';

    private const INVALID_CHARACTERS = 'ข้อความมีอักขระที่ส่งไม่ได้ เช่น อีโมจิแบบผสมหลายตัว แท็บ หรืออักขระที่มองไม่เห็น';

    private const INVALID_ACCOUNT_DATA = 'ข้อความมีคำที่ดูเหมือนรหัสหรือข้อมูลบัญชี ส่งให้ลูกค้าไม่ได้';

    private const INVALID_CONTACT = 'ข้อความมีลิงก์ อีเมล หรือเบอร์โทร ส่งไม่ได้';

    #[Test]
    #[DataProvider('editReplyKindProvider')]
    public function edit_reply_kind_cases_get_not_typed_and_write_nothing(array $messageOverrides): void
    {
        $seed = $this->bindPrompt();
        $before = $this->approvalRow($seed['approval_id']);

        [$response, $requests] = $this->postReply($this->replyMessage($messageOverrides));

        $response->assertOk();
        $this->assertSingleSend($requests, self::INVALID_NOT_TYPED.self::EDIT_RETRY_SUFFIX, 900);
        self::assertSame(1, (int) $this->approvalRow($seed['approval_id'])->revision);
        $this->assertRowChangedOnly($before, $this->approvalRow($seed['approval_id']), []);
        $this->assertNoSecretsInLog();
    }

    /** @return array<string, array{0: array}> */
    public static function editReplyKindProvider(): array
    {
        return [
            'photo with caption' => [['photo' => [['file_id' => 'ph']], 'caption' => 'ข้อความแนบรูป', 'text' => null]],
            'sticker' => [['sticker' => ['file_id' => 'st'], 'text' => null]],
            'voice' => [['voice' => ['file_id' => 'vo'], 'text' => null]],
            'forward_origin' => [['forward_origin' => ['type' => 'user']]],
            'legacy forward_date' => [['forward_date' => 1700000000]],
            'via_bot' => [['via_bot' => ['id' => 1]]],
            'text as list' => [['text' => ['ข้อความ', 'สองบรรทัด']]],
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: ?string}> */
    public static function editReplyTextRulesProvider(): array
    {
        return [
            'command' => ['command', '/start', self::INVALID_COMMAND],
            // D91: ข้อความเข้า editReply จาก raw body — วรรคล้วนไม่โดน ConvertEmptyStringsToNull อีกแล้ว ถึงกฎความยาว
            'only spaces' => ['spaces', '   ', self::INVALID_LENGTH],
            '401 code points' => ['too long', str_repeat('ก', 401), self::INVALID_LENGTH],
            '400 code points saved' => ['max length', str_repeat('ก', 400), null],
            '4 lines' => ['4 lines', "บรรทัดหนึ่ง\nบรรทัดสอง\nบรรทัดสาม\nบรรทัดสี่", self::INVALID_LINES],
            '3 lines saved' => ['3 lines', "บรรทัดหนึ่ง\nบรรทัดสอง\nบรรทัดสาม", null],
            '3 lines with blank lines saved' => ['blank lines', "บรรทัดหนึ่ง\n\nบรรทัดสอง\n\nบรรทัดสาม", null],
            'joined emoji U+200D' => ['joined emoji', "ผม\u{200D}เช็กแล้ว", self::INVALID_CHARACTERS],
            'zero width space' => ['zero width', "ผม\u{200B}เช็กแล้ว", self::INVALID_CHARACTERS],
            'soft hyphen' => ['soft hyphen', "ผม\u{00AD}เช็กแล้ว", self::INVALID_CHARACTERS],
            'tab' => ['tab', "ผม\tเช็กแล้ว", self::INVALID_CHARACTERS],
            // D91: ขอบสตริงไม่ถูกตัดก่อนถึง controller อีกแล้ว — อักขระมองไม่เห็นทุกตำแหน่งถึง guard
            'NUL at the edges' => ['nul edges', "\u{0}ผมเช็กแล้ว\u{0}", self::INVALID_CHARACTERS],
            'zero width space at the edges' => ['zwsp edges', "\u{200B}ผมเช็กแล้ว\u{200B}", self::INVALID_CHARACTERS],
            'BOM at the edge' => ['bom edge', "\u{FEFF}ผมเช็กแล้ว", self::INVALID_CHARACTERS],
            'redaction marker' => ['marker', '[redacted:email]', self::INVALID_CHARACTERS],
            'password shaped text' => ['password', 'รหัสผ่าน: abc12345', self::INVALID_ACCOUNT_DATA],
            'forgot password phrasing' => ['forgot password', 'ลืมรหัส facebook ไหมครับ', self::INVALID_ACCOUNT_DATA],
            'url' => ['url', 'ดูที่ https://example.com/x', self::INVALID_CONTACT],
            'email' => ['email', 'mail me@example.com', self::INVALID_CONTACT],
            'phone with dashes' => ['phone dashes', 'โทร 081-234-5678', self::INVALID_CONTACT],
            'phone digits only' => ['phone digits', 'โทร 0812345678', self::INVALID_CONTACT],
            'owner may write handle and price' => ['handle and price', '@743ddeqy ราคา 1,500 บาท ส่วนลด 10%', null],
            'owner may write angle ampersand date' => ['angle ampersand', 'ราคา < 500 และ & พร้อมส่ง 16/11', null],
            'owner may write emoji families' => ['emoji', 'ยังสนใจอยู่ไหมครับ ❤️🙏👍🏻🇹🇭1️⃣', null],
            'rule order command before length' => ['command>length', '/'.str_repeat('x', 400), self::INVALID_COMMAND],
            'rule order length before lines' => ['length>lines', str_repeat('ก', 90)."\n".str_repeat('ก', 90)."\n".str_repeat('ก', 90)."\n".str_repeat('ก', 90)."\n".str_repeat('ก', 90), self::INVALID_LENGTH],
            'rule order lines before characters' => ['lines>characters', "ผม\u{200D}หนึ่ง\nผม\u{200D}สอง\nผม\u{200D}สาม\nผม\u{200D}สี่", self::INVALID_LINES],
            // Str::trim ของ TrimStrings middleware ลบอักขระมองไม่เห็นที่ "ขอบ" สตริงก่อนถึง controller —
            // ZWJ ท้ายสตริงไม่ถึง guard; ใช้ ZWJ กลางสตริงจึงพิสูจน์ได้ว่ากฎอักขระตัดสินก่อนกฎข้อมูลบัญชี
            'rule order characters before account data' => ['characters>account', "รหัสผ่าน: abc12345\u{200D}แล้ว", self::INVALID_CHARACTERS],
            'rule order account data before contact' => ['account>contact', 'รหัสผ่าน: abc12345 ดูที่ https://example.com', self::INVALID_ACCOUNT_DATA],
        ];
    }

    #[Test]
    #[DataProvider('editReplyTextRulesProvider')]
    public function edit_reply_text_rules_decide_by_the_first_failing_rule(string $label, string $text, ?string $expectedNotice): void
    {
        $seed = $this->bindPrompt();

        [$response, $requests] = $this->postReply($this->replyMessage(['text' => $text]));

        $response->assertOk();
        if ($expectedNotice !== null) {
            $this->assertSingleSend($requests, $expectedNotice.self::EDIT_RETRY_SUFFIX, 900);
            self::assertSame(1, (int) $this->approvalRow($seed['approval_id'])->revision, $label.' ห้ามเซฟ');
        } else {
            self::assertSame(2, (int) $this->approvalRow($seed['approval_id'])->revision, $label.' ต้องเซฟ');
            self::assertSame(2, count($requests), 'เซฟ = ระบายการ์ด + แจ้งคน');
            $this->assertSingleSend($requests, str_replace('{log_id}', (string) $seed['log_id'], self::NOTICE_EDIT_SAVED), 900);
        }
        $this->assertNoSecretsInLog();
    }

    #[Test]
    public function edit_reply_normalizes_line_breaks_and_outer_whitespace(): void
    {
        $seed = $this->bindPrompt();

        $this->postReply($this->replyMessage(['text' => "  สวัสดีครับพี่ \r\nยังสนใจอยู่ไหมครับ\r\n\r\n  "]));

        self::assertSame("สวัสดีครับพี่ \nยังสนใจอยู่ไหมครับ", $this->approvalRow($seed['approval_id'])->text);
    }

    // ------------------------------------------------- 4b) D91 — ข้อความตรงจาก raw body

    /** @return array<string, array{0: string}> */
    public static function d91RawTextProvider(): array
    {
        return [
            // คำตัดสิน D91 — ข้อความพวกนี้เคยโดน SanitizeInput กิน ("โบนัส b บาท ครับ" ฯลฯ) ต้องบันทึกตรงตามที่พิมพ์
            'bonus pattern' => ['โบนัส bonus=100 บาท ครับ'],
            'online pattern' => ['ร้านเปิด online=ทุกวัน'],
            'data colon pattern' => ['ดูข้อมูล data: ใหม่, ครับ'],
            'html bold as typed' => ['ตัวหนา <b>ลด</b> ครับ'],
        ];
    }

    #[Test]
    #[DataProvider('d91RawTextProvider')]
    public function d91_typed_text_reaches_editreply_byte_equal_through_the_raw_body(string $text): void
    {
        $seed = $this->bindPrompt();
        $requests = [];

        [$response, $requests] = $this->postReply($this->replyMessage(['text' => $text]), stub: $this->stubWithMessageIds($requests));

        $response->assertOk();
        self::assertCount(2, $requests, 'เซฟ = ระบายการ์ด + แจ้งคน');
        $this->assertSingleSend($requests, str_replace('{log_id}', (string) $seed['log_id'], self::NOTICE_EDIT_SAVED), 900);
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame(2, (int) $after->revision);
        self::assertSame($text, $after->text, 'D91: บันทึกตรงตามที่พิมพ์');
        if (str_contains($text, '<b>')) {
            // การ์ดไม่ตีความ HTML ของข้อความที่พิมพ์ — ตัวหนาต้องโผล่บนการ์ดแบบ escape แล้ว
            $edits = array_values(array_filter($requests, fn ($r) => $this->methodOf($r) === 'editMessageText'));
            self::assertStringContainsString('&lt;b&gt;ลด&lt;/b&gt;', (string) $edits[0]['text']);
        }
        $this->assertNoSecretsInLog();
    }

    // ------------------------- 4c) D93 — ปรับช่องว่าง/ตัวคั่นบรรทัด และกฎติดต่อแบบ marker

    /** @return array<string, array{0: string, 1: string}> */
    public static function d93WhitespaceNoticeProvider(): array
    {
        return [
            // F1: NBSP สองตัวว่างหลังปรับ — ต้องโดนกฎความยาว ไม่ใช่เซฟเป็นข้อความใหม่
            'two nbsp' => ["\u{00A0}\u{00A0}", self::INVALID_LENGTH],
            'ideographic space only' => ["\u{3000}", self::INVALID_LENGTH],
            // F1: U+2028 ต้องนับเป็นตัวคั่นบรรทัด — กฎ 3 บรรทัดต้องเห็น 5 วรรคนี้
            'five parts joined by U+2028' => ["ก\u{2028}ข\u{2028}ค\u{2028}ง\u{2028}จ", self::INVALID_LINES],
        ];
    }

    #[Test]
    #[DataProvider('d93WhitespaceNoticeProvider')]
    public function d93_blank_after_normalizing_or_too_many_lines_gets_its_notice(string $text, string $expectedNotice): void
    {
        $seed = $this->bindPrompt();

        [$response, $requests] = $this->postReply($this->replyMessage(['text' => $text]));

        $response->assertOk();
        $this->assertSingleSend($requests, $expectedNotice.self::EDIT_RETRY_SUFFIX, 900);
        self::assertSame(1, (int) $this->approvalRow($seed['approval_id'])->revision, 'D93: ข้อความที่ปรับแล้วว่างหมดหรือบรรทัดเกิน ห้ามเซฟ');
        $this->assertNoSecretsInLog();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function d93SavedTextProvider(): array
    {
        return [
            // เทสต์เดิมของ D91 (d91_nbsp_at_the_edges_is_saved_as_typed, :1034) → D93: เก็บแบบตัดขอบเป็นช่องว่างธรรมดา
            'nbsp at both edges' => ["\u{00A0}สวัสดีครับพี่\u{00A0}", 'สวัสดีครับพี่'],
            'nbsp between words becomes a plain space' => ["สวัสดี\u{00A0}ครับพี่", 'สวัสดี ครับพี่'],
            'U+2028 becomes a newline' => ["ก\u{2028}ข", "ก\nข"],
            // F2: redact แค่แปลงรูปอักขระ (…, ＡＢＣ, ½) — ไม่ใช่ของที่ห้าม ต้องเซฟตรงตามที่พิมพ์
            'ellipsis as typed' => ['จุดไข่ปลา… ครับ', 'จุดไข่ปลา… ครับ'],
            'fullwidth letters as typed' => ['ＡＢＣ ตัวเต็ม', 'ＡＢＣ ตัวเต็ม'],
            'vulgar fraction half as typed' => ['ครึ่ง ½ ราคา', 'ครึ่ง ½ ราคา'],
        ];
    }

    #[Test]
    #[DataProvider('d93SavedTextProvider')]
    public function d93_normalizes_separators_and_saves_harmless_typing_byte_equal(string $text, string $expectedSaved): void
    {
        $seed = $this->bindPrompt();
        $requests = [];

        [$response, $requests] = $this->postReply($this->replyMessage(['text' => $text]), stub: $this->stubWithMessageIds($requests));

        $response->assertOk();
        self::assertCount(2, $requests, 'เซฟ = ระบายการ์ด + แจ้งคน');
        $this->assertSingleSend($requests, str_replace('{log_id}', (string) $seed['log_id'], self::NOTICE_EDIT_SAVED), 900);
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame(2, (int) $after->revision);
        self::assertSame($expectedSaved, $after->text, 'D93: เซฟหลังปรับช่องว่าง/ตัวคั่น — เนื้อความตรงตามที่พิมพ์');
        $this->assertNoSecretsInLog();
    }

    /** @return array<string, array{0: string}> */
    public static function d93ContactProvider(): array
    {
        return [
            // เลขเต็ม/เลขไทย/เบอร์คั่น NBSP — redact ทับด้วย marker [redacted: ต้องโดนกฎติดต่อเหมือนเดิม
            'fullwidth digits phone' => ['โทร '."\u{FF10}\u{FF18}\u{FF11}\u{FF12}\u{FF13}\u{FF14}\u{FF15}\u{FF16}\u{FF17}\u{FF18}".' ครับ'],
            'thai digits phone' => ['ไทยเลข '."\u{0E51}\u{0E52}\u{0E53}\u{0E54}\u{0E55}\u{0E56}\u{0E57}\u{0E58}\u{0E59}\u{0E50}".' ครับ'],
            'phone joined by nbsp' => ["โทร 081\u{00A0}234\u{00A0}5678 ครับ"],
            'url' => ['ดูที่ https://example.co/x ครับ'],
            'email' => ['ส่งที่ a@b.co ครับ'],
        ];
    }

    #[Test]
    #[DataProvider('d93ContactProvider')]
    public function d93_redact_marker_cases_get_the_contact_notice(string $text): void
    {
        $seed = $this->bindPrompt();

        [$response, $requests] = $this->postReply($this->replyMessage(['text' => $text]));

        $response->assertOk();
        $this->assertSingleSend($requests, self::INVALID_CONTACT.self::EDIT_RETRY_SUFFIX, 900);
        self::assertSame(1, (int) $this->approvalRow($seed['approval_id'])->revision, 'D93: เบอร์/ลิงก์/อีเมลยังห้ามเซฟ');
        foreach ($requests as $request) {
            self::assertStringNotContainsString($text, (string) $request['text'], 'D93: ห้าม echo ข้อความที่พิมพ์');
        }
        $this->assertNoSecretsInLog();
    }

    #[Test]
    public function d91_body_that_does_not_decode_to_an_update_calls_nothing(): void
    {
        $seed = $this->bindPrompt();
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        // D91: body ที่ decode ไม่ได้ต้องไม่เรียกอะไร —
        // JSON พังจริงๆ โดน ValidateJsonContent middleware ของระบบตอบ 400 ก่อนถึง controller (พฤติกรรม production)
        // ส่วน JSON ถูกไวยากรณ์แต่ไม่มี message เป็น array ถึง controller และตอบ {ok:true} เงียบๆ ตาม D91
        $responses = [];
        foreach (['{not-json' => 400, '{"message":"not-an-array","update_id":5}' => 200] as $body => $expectedStatus) {
            $requests = [];
            $this->resetLogCaptures();
            Http::swap(new Factory);
            Http::fake(function ($request) use (&$requests) {
                $requests[] = $request;

                return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
            });

            $response = $this->call('POST', '/api/webhook/telegram-alert/'.$this->worldToken, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'webhook-secret-test',
            ], $body);

            $response->assertStatus($expectedStatus);
            if ($expectedStatus === 200) {
                $response->assertExactJson(['ok' => true]);
            }
            self::assertCount(0, $requests, $body.' ต้องไม่ยิงออก');
            $responses[] = $expectedStatus;
        }

        self::assertSame([400, 200], $responses, 'ต้องครอบทั้งสองกรณี');
        $this->noRowChanged($beforeApproval, $this->approvalRow($seed['approval_id']), $beforeLog, $this->logRow($seed['log_id']));
    }

    #[Test]
    #[DataProvider('editReplyNoEchoProvider')]
    public function rejected_typed_text_never_reaches_a_request_or_a_log(string $label, string $text, string $expectedNotice): void
    {
        $seed = $this->bindPrompt();

        [$response, $requests] = $this->postReply($this->replyMessage(['text' => $text]));

        $response->assertOk();
        // ข้อความแจ้งต้องมาจริง (ไม่ใช่เพราะระบบเงียบ) และไม่มีทางออกอะไร echo ข้อความที่พิมพ์
        $this->assertSingleSend($requests, $expectedNotice.self::EDIT_RETRY_SUFFIX, 900);
        foreach ($requests as $request) {
            self::assertStringNotContainsString(self::ECHO_CANARY, (string) $request['text'], $label.' ห้าม echo ข้อความที่พิมพ์');
        }
        foreach ($this->logEvents as $event) {
            $line = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString(self::ECHO_CANARY, $line, $label.' ข้อความที่พิมพ์ห้ามลง log');
        }
        self::assertSame(1, (int) $this->approvalRow($seed['approval_id'])->revision);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function editReplyNoEchoProvider(): array
    {
        $echo = self::ECHO_CANARY;

        return [
            'command rule' => ['command', '/'.$echo, self::INVALID_COMMAND],
            'length rule' => ['length', str_repeat('x', 399).$echo, self::INVALID_LENGTH],
            'lines rule' => ['lines', implode("\n", array_fill(0, 4, $echo)), self::INVALID_LINES],
            // ZWJ ต้องอยู่กลางสตริง — ขอบสตริงถูก Str::trim ของ middleware ลบก่อนถึง controller
            'characters rule' => ['characters', $echo."\u{200D} ครับ", self::INVALID_CHARACTERS],
            'account data rule' => ['account', $echo.' รหัสผ่าน: abc12345', self::INVALID_ACCOUNT_DATA],
            'contact rule' => ['contact', $echo.' โทร 081-234-5678', self::INVALID_CONTACT],
        ];
    }

    #[Test]
    public function saved_typed_text_never_reaches_a_notice_or_a_log(): void
    {
        $this->bindPinnedNonce(self::NEW_NONCE);
        $seed = $this->bindPrompt();

        $canaryText = 'ตามงาน '.self::ECHO_CANARY.' ครับ';
        [, $requests] = $this->postReply($this->replyMessage(['text' => $canaryText]));

        self::assertSame($canaryText, $this->approvalRow($seed['approval_id'])->text, 'ข้อความต้องเข้าการ์ด');
        foreach ($requests as $request) {
            if ($this->methodOf($request) === 'sendMessage') {
                self::assertStringNotContainsString(self::ECHO_CANARY, (string) $request['text'], 'ข้อความแจ้งห้าม echo');
            }
        }
        foreach ($this->logEvents as $event) {
            $line = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString(self::ECHO_CANARY, $line, 'ข้อความที่พิมพ์ห้ามลง log');
        }
    }

    // ---------------------------------------------------------------- 5) ปุ่ม แก้ข้อความ (fe)

    #[Test]
    public function edit_tap_sends_the_golden_prompt_and_binds_the_editor(): void
    {
        $seed = $this->seedWorld(['golden_ids' => true]);
        $before = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);
        $requests = [];

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fe', $seed['approval_id'])), stub: $this->stubWithMessageIds($requests));

        $response->assertOk();
        self::assertCount(2, $requests, 'fe = โพสต์ช่องก่อนแล้วตอบปุ่ม');
        self::assertSame('sendMessage', $this->methodOf($requests[0]));
        self::assertSame('answerCallbackQuery', $this->methodOf($requests[1]));
        $this->assertSingleSend($requests, self::GOLDEN_PROMPT, 77, 'พิมพ์ข้อความใหม่ที่จะส่งให้ลูกค้า');
        $this->assertSingleAnswer($requests, 'ตอบกลับข้อความถัดไปในห้องนี้เพื่อพิมพ์ข้อความใหม่', false);
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame('555', $after->editor_telegram_user_id);
        self::assertSame(55, $after->edit_prompt_message_id);
        self::assertSame(999001, $after->last_update_id);
        $this->assertRowChangedOnly($before, $after, ['editor_telegram_user_id', 'edit_prompt_message_id', 'last_update_id', 'updated_at']);
        $this->noRowChanged($beforeLog, $this->logRow($seed['log_id']), $beforeLog, $this->logRow($seed['log_id']));
        $this->assertNoSecretsInLog();
    }

    private function stubFailing(string $method, int $status = 400): \Closure
    {
        return function ($request) use ($method, $status) {
            if ($this->methodOf($request) === $method) {
                return Http::response(json_encode(['ok' => false, 'description' => 'Bad Request: test']), $status);
            }

            return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
        };
    }

    private function stubThrowing(string $method): \Closure
    {
        return function ($request) use ($method) {
            if ($this->methodOf($request) === $method) {
                throw new ConnectionException('connection refused by test');
            }

            return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
        };
    }

    private function stubOkWithoutMessageId(string $method): \Closure
    {
        return function ($request) use ($method) {
            if ($this->methodOf($request) === $method) {
                return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
            }

            return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
        };
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function editTapPromptFailureProvider(): array
    {
        return [
            'sendMessage 400' => ['400 rejected', '400'],
            'sendMessage 500' => ['500 unknown', '500'],
            'sendMessage throws' => ['throw unknown', 'throw'],
            'ok true without message id' => ['no message id', 'no_id'],
        ];
    }

    #[Test]
    #[DataProvider('editTapPromptFailureProvider')]
    public function edit_tap_failed_prompt_answers_edit_prompt_failed_and_writes_nothing(string $label, string $kind): void
    {
        $seed = $this->seedWorld();
        $before = $this->approvalRow($seed['approval_id']);
        $stub = match ($kind) {
            '400' => $this->stubFailing('sendMessage', 400),
            '500' => $this->stubFailing('sendMessage', 500),
            'throw' => $this->stubThrowing('sendMessage'),
            default => $this->stubOkWithoutMessageId('sendMessage'),
        };

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fe', $seed['approval_id'])), stub: $stub);

        $response->assertOk();
        $this->assertSingleAnswer($requests, 'เปิดช่องแก้ข้อความไม่สำเร็จ กดปุ่ม แก้ข้อความ อีกครั้ง', true);
        $this->assertRowChangedOnly($before, $this->approvalRow($seed['approval_id']), []);
        $this->assertNoSecretsInLog();
    }

    #[Test]
    public function edit_tap_with_the_same_update_twice_sends_one_prompt(): void
    {
        $seed = $this->seedWorld();
        $requests = [];
        $stub = $this->stubWithMessageIds($requests);

        [, $requests1] = $this->tap($this->baseCallback(data: $this->cbData('fe', $seed['approval_id'])), stub: $stub);
        [, $requests2] = $this->tap($this->baseCallback(data: $this->cbData('fe', $seed['approval_id'])), stub: $stub);

        $sends1 = array_values(array_filter($requests1, fn ($r) => $this->methodOf($r) === 'sendMessage'));
        self::assertCount(1, $sends1, 'ครั้งแรกโพสต์ช่องหนึ่งครั้ง');
        $sends2 = array_values(array_filter($requests2, fn ($r) => $this->methodOf($r) === 'sendMessage'));
        self::assertCount(0, $sends2, 'update เดิมซ้ำต้องไม่โพสต์ช่องใหม่');
        self::assertCount(1, $requests2, 'update เดิมซ้ำตอบปุ่มอย่างเดียว');
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame(55, $after->edit_prompt_message_id);
        self::assertSame(999001, $after->last_update_id);
    }

    #[Test]
    public function edit_tap_with_a_new_update_replaces_the_binding(): void
    {
        $seed = $this->seedWorld();
        $requests = [];
        $stub = $this->stubWithMessageIds($requests);

        [, $requests1] = $this->tap($this->baseCallback(data: $this->cbData('fe', $seed['approval_id'])), stub: $stub, updateId: 1001);
        [, $requests2] = $this->tap($this->baseCallback(data: $this->cbData('fe', $seed['approval_id'])), stub: $stub, updateId: 1002);

        $sends1 = array_values(array_filter($requests1, fn ($r) => $this->methodOf($r) === 'sendMessage'));
        $sends2 = array_values(array_filter($requests2, fn ($r) => $this->methodOf($r) === 'sendMessage'));
        self::assertCount(1, $sends1);
        self::assertCount(1, $sends2, 'Edit สองครั้งได้ช่องสองช่อง');
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame(56, $after->edit_prompt_message_id, 'การผูกใหม่ต้องแทนที่เสมอ (D88 latest prompt wins)');
        self::assertSame(1002, $after->last_update_id);
    }

    #[Test]
    public function edit_tap_by_outsider_answers_no_permission_and_sends_no_prompt(): void
    {
        $seed = $this->seedWorld();

        [$response, $requests] = $this->tap($this->baseCallback(['from' => ['id' => 777]], data: $this->cbData('fe', $seed['approval_id'])));

        $response->assertOk();
        $this->assertSingleAnswer($requests, 'ไม่มีสิทธิ์กดปุ่มนี้', true);
        $sends = array_values(array_filter($requests, fn ($r) => $this->methodOf($r) === 'sendMessage'));
        self::assertCount(0, $sends);
        self::assertNull($this->approvalRow($seed['approval_id'])->edit_prompt_message_id);
    }

    /** @return array<string, array{0: string, 1: int, 2: string}> */
    public static function editTapNonPendingProvider(): array
    {
        return [
            'approved' => ['approved', 2, 'กดส่งไปแล้ว กำลังส่งให้ลูกค้า'],
            'sending' => ['sending', 2, 'กดส่งไปแล้ว กำลังส่งให้ลูกค้า'],
            'sent' => ['sent', 2, 'ส่งให้ลูกค้าไปแล้ว'],
            'skipped' => ['skipped', 2, 'ข้ามไปแล้ว ไม่ได้ส่ง'],
            'expired status' => ['expired', 2, 'หมดอายุแล้ว ไม่ได้ส่ง'],
            'cancelled' => ['cancelled', 2, 'รายการนี้ถูกยกเลิกแล้ว ไม่ได้ส่ง'],
            'editing' => ['editing', 1, 'กำลังแก้ข้อความอยู่'],
        ];
    }

    #[Test]
    #[DataProvider('editTapNonPendingProvider')]
    public function edit_tap_on_non_pending_status_answers_the_status_found_and_repaints(string $status, int $expectedRequests, string $expectedAnswer): void
    {
        $seed = $this->seedWorld(['approval_status' => $status]);
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fe', $seed['approval_id'])));

        $response->assertOk();
        self::assertCount($expectedRequests, $requests);
        $this->assertSingleAnswer($requests, $expectedAnswer, true);
        $sends = array_values(array_filter($requests, fn ($r) => $this->methodOf($r) === 'sendMessage'));
        self::assertCount(0, $sends, 'สถานะที่ปิดแล้วห้ามเปิดช่องแก้');
        $this->noRowChanged($beforeApproval, $this->approvalRow($seed['approval_id']), $beforeLog, $this->logRow($seed['log_id']));
    }

    #[Test]
    #[DataProvider('editTapNonPendingProvider')]
    public function confirm_tap_on_non_pending_status_answers_the_status_found_and_repaints(string $status, int $expectedRequests, string $expectedAnswer): void
    {
        $seed = $this->seedWorld(['approval_status' => $status]);
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fc', $seed['approval_id'])));

        $response->assertOk();
        self::assertCount($expectedRequests, $requests);
        $this->assertSingleAnswer($requests, $expectedAnswer, true);
        $this->noRowChanged($beforeApproval, $this->approvalRow($seed['approval_id']), $beforeLog, $this->logRow($seed['log_id']));
    }

    #[Test]
    public function edit_tap_at_expiry_answers_expired_and_sends_no_prompt(): void
    {
        $seed = $this->seedWorld(['expires_at' => self::NOW]);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fe', $seed['approval_id'])));

        $response->assertOk();
        self::assertCount(1, $requests, 'หมดอายุตอบเฉยๆ ไม่แตะการ์ด');
        $this->assertSingleAnswer($requests, 'หมดอายุแล้ว ไม่ได้ส่ง', true);
        self::assertNull($this->approvalRow($seed['approval_id'])->edit_prompt_message_id);
    }

    #[Test]
    public function edit_tap_conditional_update_losing_race_writes_nothing(): void
    {
        $seed = $this->seedWorld();
        $requests = [];
        $stub = $this->stubWithMessageIds($requests);
        $seen = 0;
        DB::listen(function (QueryExecuted $query) use (&$seen, $seed): void {
            if (str_contains($query->sql, 'from "follow_up_approvals"') && str_contains($query->sql, 'where "id" = ?')) {
                $seen++;
                if ($seen === 1) {
                    // นักเขียนอื่นยึดบัตรระหว่างอ่านครั้งแรกกับ UPDATE มีเงื่อนไข
                    DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update(['status' => 'approved']);
                }
            }
        });

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fe', $seed['approval_id'])), stub: $stub);
        app('events')->forget(QueryExecuted::class);

        $response->assertOk();
        self::assertGreaterThanOrEqual(1, $seen, 'ต้อง inject ได้จริง');
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame('approved', $after->status, 'เขียนเดิมของนักเขียนอื่นคงอยู่');
        self::assertNull($after->edit_prompt_message_id, 'fe แพ้แข่งต้องไม่ผูกช่อง');
        self::assertNull($after->last_update_id);
        self::assertSame(1, (int) $after->revision);
        self::assertCount(3, $requests, 'โพสต์ช่องสำเร็จ + ตอบจากสถานะที่เจอ + ระบายการ์ด (D82)');
        $this->assertSingleAnswer($requests, 'กดส่งไปแล้ว กำลังส่งให้ลูกค้า', true);
    }

    /** D90: fe แพ้แข่งแบบ nonce ย้ายใต้ UPDATE มีเงื่อนไข — บัตรยัง pending มีชีวิต = stale แล้ว syncCard (D87) */
    #[Test]
    public function edit_tap_losing_the_nonce_race_answers_stale_and_syncs_the_card(): void
    {
        $seed = $this->seedWorld();
        $requests = [];
        $stub = $this->stubWithMessageIds($requests);
        $seen = 0;
        DB::listen(function (QueryExecuted $query) use (&$seen, $seed): void {
            if (str_contains($query->sql, 'from "follow_up_approvals"') && str_contains($query->sql, 'where "id" = ?')) {
                $seen++;
                if ($seen === 1) {
                    // นักเขียนอื่นเปลี่ยน nonce ระหว่างอ่านครั้งแรกกับ UPDATE มีเงื่อนไข
                    DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update(['nonce' => self::NEW_NONCE]);
                }
            }
        });

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fe', $seed['approval_id'])), stub: $stub);
        app('events')->forget(QueryExecuted::class);

        $response->assertOk();
        self::assertGreaterThanOrEqual(1, $seen, 'ต้อง inject ได้จริง');
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame(self::NEW_NONCE, $after->nonce, 'nonce ของนักเขียนอื่นคงอยู่');
        self::assertSame('pending', $after->status);
        self::assertNull($after->edit_prompt_message_id, 'fe แพ้แข่งต้องไม่ผูกช่อง');
        self::assertNull($after->last_update_id);
        self::assertSame(1, (int) $after->revision);
        self::assertCount(3, $requests, 'โพสต์ช่องสำเร็จ + ตอบ stale + syncCard (D90)');
        $this->assertSingleAnswer($requests, 'การ์ดนี้เป็นรุ่นเก่า ใช้การ์ดล่าสุดแทน', true);
        $this->assertSingleEdit($requests);
    }

    /** D90: fc แพ้แข่งก่อนล็อก — บัตรยัง pending มีชีวิตแต่รุ่นย้าย = stale, เขียนอะไรไม่ลง, syncCard */
    #[Test]
    public function confirm_tap_losing_the_revision_race_before_the_lock_answers_stale_and_syncs(): void
    {
        $seed = $this->seedWorld($this->editedCard());
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);
        $requests = [];
        $stub = $this->stubWithMessageIds($requests);
        $seen = 0;
        DB::listen(function (QueryExecuted $query) use (&$seen, $seed): void {
            if (str_contains($query->sql, 'from "follow_up_approvals"') && str_contains($query->sql, 'where "id" = ?')) {
                $seen++;
                if ($seen === 1) {
                    // การแก้อื่นเซฟระหว่างอ่านครั้งแรกกับตอนล็อก — รุ่นย้ายก่อนธุรกรรม
                    DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update(['revision' => 3]);
                }
            }
        });

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fc', 777, 2, self::NONCE)), stub: $stub);
        app('events')->forget(QueryExecuted::class);

        $response->assertOk();
        self::assertGreaterThanOrEqual(1, $seen, 'ต้อง inject ได้จริง');
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame(3, (int) $after->revision, 'รุ่นของการแก้อื่นคงอยู่');
        self::assertSame('pending', $after->status);
        self::assertNull($after->confirmed_revision);
        self::assertNull($after->last_update_id);
        self::assertCount(2, $requests, 'ตอบ stale + syncCard เท่านั้น — ไม่เขียนอะไร');
        $this->assertSingleAnswer($requests, 'การ์ดนี้เป็นรุ่นเก่า ใช้การ์ดล่าสุดแทน', true);
        $this->assertSingleEdit($requests);
        $this->assertRowChangedOnly($beforeApproval, $after, ['revision']);
        $this->assertRowChangedOnly($beforeLog, $this->logRow($seed['log_id']), []);
    }

    // ---------------------------------------------------------------- 6) ตอบช่องแล้วเซฟรุ่นใหม่

    #[Test]
    public function valid_reply_saves_exact_columns_and_painted_card_golden(): void
    {
        $this->bindPinnedNonce(self::NEW_NONCE);
        $seed = $this->bindPrompt(['golden_ids' => true, 'check_answers' => null]);
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);
        $requests = [];

        [$response, $requests] = $this->postReply($this->replyMessage(), stub: $this->stubWithMessageIds($requests));

        $response->assertOk()->assertExactJson(['ok' => true]);
        self::assertCount(2, $requests, 'เซฟ = ระบายการ์ดก่อนแล้วแจ้งคน');
        self::assertSame('editMessageText', $this->methodOf($requests[0]), 'ระบายการ์ดมาก่อนข้อความแจ้ง');
        self::assertSame('sendMessage', $this->methodOf($requests[1]));
        $this->assertSingleEdit($requests, self::GOLDEN_AFTER_EDIT, [
            [
                ['text' => 'ยืนยันส่ง', 'callback_data' => 'fc|777|2|'.self::NEW_NONCE],
                ['text' => 'ข้าม', 'callback_data' => 'fk|777|2|'.self::NEW_NONCE],
                ['text' => 'แก้ข้อความ', 'callback_data' => 'fe|777|2|'.self::NEW_NONCE],
            ],
        ]);
        $this->assertSingleSend($requests, str_replace('{log_id}', (string) $seed['log_id'], self::NOTICE_EDIT_SAVED), 900);

        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame(2, (int) $after->revision);
        self::assertSame(self::NEW_NONCE, $after->nonce);
        self::assertSame(self::EDITED_TEXT, $after->text);
        self::assertNull($after->check_answers);
        self::assertSame(0, (int) $after->check_overridden);
        self::assertNull($after->confirmed_revision);
        self::assertSame(999001, $after->last_update_id);
        self::assertSame('pending', $after->status);
        self::assertSame('555', $after->editor_telegram_user_id, 'การผูกต้องคงอยู่หลังเซฟ');
        self::assertSame(55, $after->edit_prompt_message_id, 'การผูกต้องคงอยู่หลังเซฟ');
        $this->assertRowChangedOnly($beforeApproval, $after, [
            'revision', 'nonce', 'text', 'check_answers', 'check_overridden', 'confirmed_revision', 'last_update_id', 'updated_at',
        ]);
        $this->noRowChanged($beforeLog, $this->logRow($seed['log_id']), $beforeLog, $this->logRow($seed['log_id']));

        // ไม่มีอะไรส่งถึงลูกค้า: ไม่มี LINE ไม่มี job ไม่มีแถวข้อความลูกค้าใหม่ (ข้อความ bot เดิมจาก seed มี 2)
        foreach ($requests as $request) {
            self::assertStringNotContainsString('api.line.me', (string) $request->url(), 'การ์ดนี้ห้ามส่งหาลูกค้า');
        }
        Queue::assertNothingPushed();
        Bus::assertDispatched(CheckEditedFollowUp::class, fn ($job) => $job->approvalId === $seed['approval_id'] && $job->revision === 2);
        Bus::assertDispatchedTimes(CheckEditedFollowUp::class, 1);
        $this->assertOnlyEditChecks(1);
        self::assertSame(2, DB::table('messages')->where('sender', 'bot')->count(), 'ห้ามสร้างแถวข้อความลูกค้า');
        $this->assertNoSecretsInLog();
    }

    #[Test]
    public function second_valid_reply_to_the_same_prompt_saves_revision_3(): void
    {
        $this->bindPinnedNonce(self::NEW_NONCE);
        $seed = $this->bindPrompt(['golden_ids' => true, 'check_answers' => null]);
        $requests = [];

        [, $requests] = $this->postReply($this->replyMessage(), stub: $this->stubWithMessageIds($requests));
        [, $requests] = $this->postReply($this->replyMessage(['text' => 'รบกวนอีกทีครับ']), updateId: self::UPDATE_ID + 1, stub: $this->stubWithMessageIds($requests));

        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame(3, (int) $after->revision);
        self::assertSame('รบกวนอีกทีครับ', $after->text);
        $this->assertSingleEdit($requests, null, [
            [
                ['text' => 'ยืนยันส่ง', 'callback_data' => 'fc|777|3|'.self::NEW_NONCE],
                ['text' => 'ข้าม', 'callback_data' => 'fk|777|3|'.self::NEW_NONCE],
                ['text' => 'แก้ข้อความ', 'callback_data' => 'fe|777|3|'.self::NEW_NONCE],
            ],
        ]);
    }

    #[Test]
    public function the_same_update_twice_saves_once_and_stays_silent(): void
    {
        $this->bindPinnedNonce(self::NEW_NONCE);
        $seed = $this->bindPrompt(['golden_ids' => true, 'check_answers' => null]);
        $requests = [];

        [, $requests1] = $this->postReply($this->replyMessage(), stub: $this->stubWithMessageIds($requests));
        [, $requests2] = $this->postReply($this->replyMessage(), stub: $this->stubWithMessageIds($requests));

        self::assertSame(2, (int) $this->approvalRow($seed['approval_id'])->revision);
        $sends2 = array_values(array_filter($requests2, fn ($r) => $this->methodOf($r) === 'sendMessage'));
        self::assertCount(0, $sends2, 'update เดิมซ้ำ = เงียบ ไม่มีข้อความแจ้งครั้งที่สอง');
        self::assertCount(0, $requests2, 'update เดิมซ้ำต้องไม่ระบายการ์ดซ้ำและไม่แจ้ง');
        $edits1 = array_values(array_filter($requests1, fn ($r) => $this->methodOf($r) === 'editMessageText'));
        self::assertCount(1, $edits1, 'ครั้งแรกระบายการ์ดหนึ่งครั้ง');
    }

    #[Test]
    public function sync_card_shows_the_clean_check_after_luna_fills_the_answers(): void
    {
        $this->bindPinnedNonce(self::NEW_NONCE);
        $seed = $this->bindPrompt(['golden_ids' => true, 'check_answers' => null]);
        $requests = [];
        $this->postReply($this->replyMessage(), stub: $this->stubWithMessageIds($requests));
        $this->resetLogCaptures();

        // B6d จะเขียน check_answers เอง — ที่นี่จำลองค่าที่ Luna ผ่านแล้ว
        DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update(['check_answers' => self::CLEAN_CHECK]);
        $requests = [];
        $this->fakeTelegramCapture($requests);

        $result = app(FollowUpTelegramService::class)->syncCard($seed['approval_id'], $this->now());

        self::assertSame('refreshed', $result);
        $this->assertSingleEdit($requests, self::GOLDEN_AFTER_EDIT_CLEAN_CHECK, [
            [
                ['text' => 'ส่ง', 'callback_data' => 'fs|777|2|'.self::NEW_NONCE],
                ['text' => 'ข้าม', 'callback_data' => 'fk|777|2|'.self::NEW_NONCE],
                ['text' => 'แก้ข้อความ', 'callback_data' => 'fe|777|2|'.self::NEW_NONCE],
            ],
        ]);
    }

    #[Test]
    public function the_card_text_never_exceeds_4096_code_points_on_the_worst_escape(): void
    {
        $this->bindPinnedNonce(self::NEW_NONCE);
        $seed = $this->bindPrompt();
        $requests = [];

        $this->postReply($this->replyMessage(['text' => str_repeat('"', 400)]), stub: $this->stubWithMessageIds($requests));

        $text = $this->methodOf($requests[0]) === 'editMessageText' ? (string) $requests[0]['text'] : '';
        self::assertLessThanOrEqual(4096, mb_strlen($text, 'UTF-8'), 'การ์ดต้องไม่เกิน 4096 จุดโค้ด');
        self::assertStringContainsString(str_repeat('&quot;', 400), $text, 'ข้อความที่แก้ต้องอยู่ครบทั้งข้อความบนการ์ด');
    }

    #[Test]
    public function save_losing_a_race_between_read_and_lock_writes_nothing(): void
    {
        $seed = $this->bindPrompt();
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $seen = 0;
        DB::listen(function (QueryExecuted $query) use (&$seen, $seed): void {
            if (str_contains($query->sql, 'from "follow_up_approvals"') && str_contains($query->sql, 'where "id" = ?')) {
                $seen++;
                if ($seen === 1) {
                    // นักเขียนอื่นข้ามบัตรระหว่างอ่านครั้งแรก (step 4) กับล็อกในธุรกรรมเซฟ (อ่านครั้งที่สอง)
                    DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update(['status' => 'skipped']);
                }
            }
        });

        [$response, $requests] = $this->postReply($this->replyMessage());
        app('events')->forget(QueryExecuted::class);

        $response->assertOk();
        self::assertGreaterThanOrEqual(1, $seen, 'ต้อง inject ได้จริง');
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame('skipped', $after->status, 'สถานะจากนักเขียนอื่นคงอยู่');
        self::assertSame(1, (int) $after->revision);
        self::assertSame(self::DRAFT, $after->text);
        $this->assertRowChangedOnly($beforeApproval, $after, ['status', 'updated_at']);
        self::assertCount(1, $requests, 'แพ้แข่ง = ข้อความแจ้งเดียว ไม่ระบายการ์ด');
        $this->assertSingleSend($requests, self::NOTICE_EDIT_CLOSED, 900);
        Bus::assertNothingDispatched();
    }

    // ---------------------------------------------------------------- 7) ส่ง/ยืนยันส่งบนบัตรที่แก้แล้ว

    /** stub รวม Telegram+LINE — ใช้เมื่อเทสต์ต่อเนื่องจาก tap ไปถึงผู้ส่งจริง */
    private function stubTelegramAndLine(array &$requests, ?int $firstMessageId = 55, ?array &$pushes = null): \Closure
    {
        return function ($request) use (&$requests, &$firstMessageId, &$pushes) {
            $requests[] = $request;
            $url = (string) $request->url();
            if (str_starts_with($url, 'https://api.line.me/')) {
                if (str_ends_with($url, '/message/push')) {
                    $pushes[] = $request;

                    return Http::response('{}', 200, ['x-line-request-id' => self::LINE_REQUEST_ID]);
                }

                return Http::response(
                    json_encode($url === self::LINE_QUOTA_URL ? ['type' => 'limited', 'value' => 200] : ['totalUsage' => 10]),
                    200,
                );
            }
            if ($this->methodOf($request) === 'sendMessage') {
                return Http::response(json_encode(['ok' => true, 'result' => ['message_id' => $firstMessageId++]]), 200);
            }

            return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
        };
    }

    /** ตัวเลือก seed ของบัตรแก้แล้ว (revision 2 + ข้อความใหม่) */
    private function editedCard(array $extra = []): array
    {
        return array_merge([
            'golden_ids' => true,
            'revision' => 2,
            'approval_text' => self::EDITED_TEXT,
            'check_answers' => null,
        ], $extra);
    }

    #[Test]
    public function confirm_tap_approves_the_edited_text_and_the_sender_sends_it(): void
    {
        $seed = $this->seedWorld($this->editedCard());
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);
        $requests = [];
        $pushes = [];

        [$response, $requests] = $this->tap(
            $this->baseCallback(data: $this->cbData('fc', 777, 2, self::NONCE)),
            stub: $this->stubTelegramAndLine($requests, 55, $pushes),
        );

        $response->assertOk();
        $this->assertSingleAnswer($requests, 'รับคำสั่งแล้ว กำลังส่งข้อความที่แก้ให้ลูกค้า', false);
        $this->assertSingleEdit($requests, null, []);
        $afterLog = $this->logRow($seed['log_id']);
        self::assertSame('approved', $afterLog->delivery_status);
        self::assertSame('edited', $afterLog->owner_action);
        self::assertSame('555', $afterLog->owner_telegram_user_id);
        self::assertSame(self::NOW, $afterLog->owner_action_at);
        self::assertSame(1, (int) $afterLog->check_overridden);
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame('approved', $after->status);
        self::assertSame(1, (int) $after->check_overridden);
        self::assertSame(2, (int) $after->confirmed_revision);
        $this->assertRowChangedOnly($beforeApproval, $after, ['status', 'check_overridden', 'confirmed_revision', 'updated_at', 'last_update_id']);
        $this->assertRowChangedOnly($beforeLog, $afterLog, ['delivery_status', 'owner_action', 'owner_telegram_user_id', 'owner_action_at', 'check_overridden', 'updated_at']);
        Queue::assertNothingPushed();

        // ผู้ส่งจริง (LINE fake) — ข้อความที่ลูกค้าได้คือข้อความที่แก้
        $result = app(FollowUpSendService::class)->send(777, $this->now());
        self::assertSame('sent', $result);
        self::assertCount(1, $pushes);
        self::assertSame(self::EDITED_TEXT, $pushes[0]['messages'][0]['text']);
        $messageRow = DB::table('messages')->where('conversation_id', $seed['room']->id)->where('sender', 'bot')->orderByDesc('id')->first();
        self::assertNotNull($messageRow);
        self::assertSame(self::EDITED_TEXT, $messageRow->content);
        self::assertNull($messageRow->model_used);
    }

    #[Test]
    public function send_tap_on_edited_without_clean_check_answers_confirm_needed_and_syncs(): void
    {
        $seed = $this->seedWorld($this->editedCard());
        $beforeApproval = $this->approvalRow($seed['approval_id']);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', 777, 2, self::NONCE)));

        $response->assertOk();
        $this->assertSingleAnswer($requests, 'ข้อความนี้แก้เองและยังไม่ผ่านการตรวจ กดปุ่ม ยืนยันส่ง บนการ์ดถ้าจะส่ง', true);
        $this->assertSingleEdit($requests, null, [
            [
                ['text' => 'ยืนยันส่ง', 'callback_data' => 'fc|777|2|'.self::NONCE],
                ['text' => 'ข้าม', 'callback_data' => 'fk|777|2|'.self::NONCE],
                ['text' => 'แก้ข้อความ', 'callback_data' => 'fe|777|2|'.self::NONCE],
            ],
        ]);
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame('pending', $after->status, 'ยืนยันก่อนเท่านั้น ห้ามเขียน');
        $this->assertRowChangedOnly($beforeApproval, $after, []);
    }

    #[Test]
    public function send_tap_on_edited_with_clean_check_approves_and_the_sender_sends_it(): void
    {
        $seed = $this->seedWorld($this->editedCard(['check_answers' => self::CLEAN_CHECK]));
        $requests = [];
        $pushes = [];

        [$response, $requests] = $this->tap(
            $this->baseCallback(data: $this->cbData('fs', 777, 2, self::NONCE)),
            stub: $this->stubTelegramAndLine($requests, 55, $pushes),
        );

        $response->assertOk();
        $this->assertSingleAnswer($requests, 'รับคำสั่งแล้ว กำลังส่งให้ลูกค้า', false);
        $afterLog = $this->logRow($seed['log_id']);
        self::assertSame('approved', $afterLog->delivery_status);
        self::assertSame('edited', $afterLog->owner_action);
        self::assertSame(0, (int) $afterLog->check_overridden, 'ผ่านการตรวจแล้วไม่ต้องยืนยัน');
        $after = $this->approvalRow($seed['approval_id']);
        self::assertNull($after->confirmed_revision);

        $result = app(FollowUpSendService::class)->send(777, $this->now());
        self::assertSame('sent', $result);
        self::assertCount(1, $pushes);
        self::assertSame(self::EDITED_TEXT, $pushes[0]['messages'][0]['text']);
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function fcWrongCardProvider(): array
    {
        return [
            'fc on unedited card' => ['unedited', null],
            'fc on edited clean check' => ['edited clean', self::CLEAN_CHECK],
        ];
    }

    #[Test]
    #[DataProvider('fcWrongCardProvider')]
    public function fc_on_a_card_that_does_not_need_confirmation_answers_stale_and_repaints(string $label, ?string $check): void
    {
        $seed = $this->seedWorld($this->editedCard(['revision' => $check === null ? 1 : 2, 'approval_text' => $check === null ? self::DRAFT : self::EDITED_TEXT, 'check_answers' => $check]));
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $revision = $check === null ? 1 : 2;

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fc', 777, $revision, self::NONCE)));

        $response->assertOk();
        $this->assertSingleAnswer($requests, 'การ์ดนี้เป็นรุ่นเก่า ใช้การ์ดล่าสุดแทน', true);
        self::assertCount(2, $requests, 'stale บนบัตรที่ยัง pending = ตอบแล้วระบายการ์ด (D87)');
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame('pending', $after->status);
        $this->assertRowChangedOnly($beforeApproval, $after, []);
        $this->assertNoSecretsInLog();
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function editedRowCheckShapeProvider(): array
    {
        return [
            'sql null' => ['null-shape', null],
            'json null' => ['null', 'null'],
            'empty list' => ['[]', '[]'],
            'empty object' => ['{}', '{}'],
            'failed as string' => ['failed string', '{"failed":"oops","error":false}'],
            'failed missing' => ['failed missing', '{"error":false}'],
            'error null' => ['error null', '{"failed":[],"error":null}'],
            'error 0' => ['error 0', '{"failed":[],"error":0}'],
            'error empty string' => ['error empty', '{"failed":[],"error":""}'],
            'error string false' => ['error string false', '{"failed":[],"error":"false"}'],
            'error true' => ['error true', '{"failed":[],"error":true}'],
            'failed object' => ['failed object', '{"failed":{},"error":false}'],
            'one known key' => ['flagged known', '{"failed":["pressure"],"error":false}'],
            'two known keys file order' => ['flagged two', '{"failed":["price_stock","off_topic"],"error":false}'],
            'one unknown key' => ['flagged unknown', '{"failed":["mystery"],"error":false}'],
            'known plus unknown' => ['flagged mixed', '{"failed":["support_free","zzz"],"error":false}'],
        ];
    }

    #[Test]
    #[DataProvider('editedRowCheckShapeProvider')]
    public function every_not_clean_shape_on_an_edited_row_confirms_first(string $label, ?string $shape): void
    {
        $seed = $this->seedWorld($this->editedCard(['check_answers' => $shape]));

        // fs ก่อน — ต้องถูกบังคับยืนยัน
        [, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', 777, 2, self::NONCE)));
        self::assertCount(2, $requests, $label.' fs = ตอบ + ระบายการ์ด');
        $this->assertSingleAnswer($requests, 'ข้อความนี้แก้เองและยังไม่ผ่านการตรวจ กดปุ่ม ยืนยันส่ง บนการ์ดถ้าจะส่ง', true);
        self::assertSame('pending', $this->approvalRow($seed['approval_id'])->status);

        // fc ตาม — แตะเดียวส่งได้ (D84)
        [, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fc', 777, 2, self::NONCE)));
        self::assertCount(2, $requests, $label.' fc = ตอบ + ระบายสถานะ');
        $this->assertSingleAnswer($requests, 'รับคำสั่งแล้ว กำลังส่งข้อความที่แก้ให้ลูกค้า', false);
        $log = $this->logRow($seed['log_id']);
        self::assertSame('approved', $log->delivery_status);
        self::assertSame('edited', $log->owner_action);
        self::assertSame(1, (int) $log->check_overridden);
        self::assertSame(2, (int) $this->approvalRow($seed['approval_id'])->confirmed_revision);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function fcGateCases(): array
    {
        return [
            'outside at 22:00:00' => ['22:00', '2026-11-16 22:00:00', 'ตอนนี้นอกเวลาส่ง (08:00–22:00) ยังไม่ได้ส่ง กดใหม่ในเวลาได้'],
            'outside at 07:59:59' => ['07:59', '2026-11-16 07:59:59', 'ตอนนี้นอกเวลาส่ง (08:00–22:00) ยังไม่ได้ส่ง กดใหม่ในเวลาได้'],
            'switch off' => ['switch', '2026-11-16 10:00:00', 'ระบบตามลูกค้าของบอทนี้ปิดอยู่ ยังไม่ได้ส่ง'],
            'handover on' => ['handover', '2026-11-16 10:00:00', 'แอดมินรับห้องนี้อยู่ ยังไม่ได้ส่ง'],
            'room closed' => ['room', '2026-11-16 10:00:00', 'ห้องนี้ปิดไปแล้ว ไม่ได้ส่ง'],
            'new customer message' => ['customer', '2026-11-16 10:00:00', 'ลูกค้าพิมพ์เข้ามาแล้ว ไม่ต้องตาม'],
            'new bot message' => ['bot', '2026-11-16 10:00:00', 'บอทตอบในห้องนี้ไปแล้ว ไม่ได้ส่ง'],
            'order created' => ['order', '2026-11-16 10:00:00', 'ลูกค้าสั่งซื้อแล้ว ไม่ต้องตาม'],
            'passed slip' => ['slip', '2026-11-16 10:00:00', 'ลูกค้าสั่งซื้อแล้ว ไม่ต้องตาม'],
            'capabilities blocked' => ['capabilities', '2026-11-16 10:00:00', 'บอทนี้ยังตั้งค่าไม่ครบ ยังไม่ได้ส่ง'],
            'reason without own key' => ['other', '2026-11-15 10:00:00', 'ตอนนี้ยังส่งไม่ได้ ลองใหม่ภายหลัง'],
        ];
    }

    #[Test]
    #[DataProvider('fcGateCases')]
    public function the_gate_blocks_fc_on_an_edited_text_the_same_way(string $case, string $when, string $expectedAnswer): void
    {
        $seed = $this->seedWorldForGate($case);
        $this->freeze($when);
        $beforeApproval = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fc', 777, 2, self::NONCE)));

        $response->assertOk();
        self::assertCount(1, $requests, 'gate ตันต้องมีแค่คำตอบ ไม่มีการแก้การ์ด');
        $this->assertSingleAnswer($requests, $expectedAnswer, true);
        $afterApproval = $this->approvalRow($seed['approval_id']);
        $this->noRowChanged($beforeApproval, $afterApproval, $beforeLog, $this->logRow($seed['log_id']));
        self::assertSame('pending', $afterApproval->status);
        self::assertNull($afterApproval->confirmed_revision);
    }

    #[Test]
    #[DataProvider('fcGateCases')]
    public function the_gate_blocks_fs_on_an_edited_clean_text_the_same_way(string $case, string $when, string $expectedAnswer): void
    {
        $seed = $this->seedWorldForGate($case, self::CLEAN_CHECK);
        $this->freeze($when);
        $beforeApproval = $this->approvalRow($seed['approval_id']);

        [$response, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', 777, 2, self::NONCE)));

        $response->assertOk();
        self::assertCount(1, $requests);
        $this->assertSingleAnswer($requests, $expectedAnswer, true);
        self::assertSame('pending', $this->approvalRow($seed['approval_id'])->status);
    }

    /** seed บัตรแก้แล้ว (check ไม่สะอาด) พร้อมเงื่อนไข gate ของแต่ละกรณี */
    private function seedWorldForGate(string $case, ?string $check = null): array
    {
        $options = $this->editedCard();
        if ($check !== null) {
            $options['check_answers'] = $check;
        }
        $options = array_merge($options, match ($case) {
            'switch' => ['follow_up_enabled' => false],
            'handover' => ['is_handover' => true],
            'room' => ['room_status' => 'closed'],
            'capabilities' => ['bot_ids' => []],
            default => [],
        });
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

        return $seed;
    }

    #[Test]
    public function old_buttons_after_an_edit_answer_stale_and_repaint_the_current_card(): void
    {
        $this->bindPinnedNonce(self::NEW_NONCE);
        $seed = $this->bindPrompt(['golden_ids' => true, 'check_answers' => null]);
        $requests = [];
        $this->postReply($this->replyMessage(), stub: $this->stubWithMessageIds($requests));
        $beforeApproval = $this->approvalRow($seed['approval_id']);

        [, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', 777, 1, self::NONCE)));
        self::assertCount(2, $requests, 'ปุ่มเก่า = ตอบ stale แล้วระบายการ์ด (D87)');
        $this->assertSingleAnswer($requests, 'การ์ดนี้เป็นรุ่นเก่า ใช้การ์ดล่าสุดแทน', true);
        $this->assertSingleEdit($requests, self::GOLDEN_AFTER_EDIT, [
            [
                ['text' => 'ยืนยันส่ง', 'callback_data' => 'fc|777|2|'.self::NEW_NONCE],
                ['text' => 'ข้าม', 'callback_data' => 'fk|777|2|'.self::NEW_NONCE],
                ['text' => 'แก้ข้อความ', 'callback_data' => 'fe|777|2|'.self::NEW_NONCE],
            ],
        ]);
        self::assertSame('pending', $this->approvalRow($seed['approval_id'])->status);
        $this->assertRowChangedOnly($beforeApproval, $this->approvalRow($seed['approval_id']), []);

        [, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fk', 777, 1, self::NONCE)));
        self::assertCount(2, $requests);
        $this->assertSingleAnswer($requests, 'การ์ดนี้เป็นรุ่นเก่า ใช้การ์ดล่าสุดแทน', true);
        self::assertSame('pending', $this->approvalRow($seed['approval_id'])->status);
    }

    #[Test]
    public function after_a_third_edit_the_revision_2_data_answers_stale(): void
    {
        $this->bindPinnedNonce(self::NEW_NONCE);
        $seed = $this->bindPrompt(['golden_ids' => true, 'check_answers' => null]);
        $requests = [];

        [, $requests] = $this->postReply($this->replyMessage(), stub: $this->stubWithMessageIds($requests));
        [, $requests] = $this->postReply($this->replyMessage(['text' => 'ที่สองครับ']), updateId: self::UPDATE_ID + 1, stub: $this->stubWithMessageIds($requests));
        self::assertSame(3, (int) $this->approvalRow($seed['approval_id'])->revision);

        [, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fc', 777, 2, self::NEW_NONCE)));

        self::assertCount(2, $requests);
        $this->assertSingleAnswer($requests, 'การ์ดนี้เป็นรุ่นเก่า ใช้การ์ดล่าสุดแทน', true);
        self::assertSame('pending', $this->approvalRow($seed['approval_id'])->status);
        self::assertSame(3, (int) $this->approvalRow($seed['approval_id'])->revision);
    }

    #[Test]
    public function skip_after_an_edit_closes_the_room_for_later_replies(): void
    {
        $this->bindPinnedNonce(self::NEW_NONCE);
        $seed = $this->bindPrompt(['golden_ids' => true, 'check_answers' => null]);
        $requests = [];

        [, $requests] = $this->postReply($this->replyMessage(), stub: $this->stubWithMessageIds($requests));
        [, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fk', 777, 2, self::NEW_NONCE)));
        self::assertSame('skipped', $this->logRow($seed['log_id'])->delivery_status);
        $this->assertSingleAnswer($requests, 'ข้ามแล้ว ไม่ส่งข้อความนี้', false);

        [, $requests] = $this->postReply($this->replyMessage(['text' => 'ตามอีกครั้ง']), updateId: self::UPDATE_ID + 1);
        self::assertCount(1, $requests);
        $this->assertSingleSend($requests, self::NOTICE_EDIT_CLOSED, 900);
        self::assertSame(2, (int) $this->approvalRow($seed['approval_id'])->revision, 'หลังข้ามแล้วตอบช่องห้ามเซฟ');
    }

    #[Test]
    public function send_and_skip_keep_working_while_a_prompt_is_open(): void
    {
        // fe แล้ว fk — บัตรยังไม่แก้ เส้นทางเดิมต้องยังทำงาน
        $seedA = $this->seedWorld();
        $requests = [];
        [, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fe', $seedA['approval_id'])), stub: $this->stubWithMessageIds($requests));
        [, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fk', $seedA['approval_id'])));
        $this->assertSingleAnswer($requests, 'ข้ามแล้ว ไม่ส่งข้อความนี้', false);
        self::assertSame('skipped', $this->logRow($seedA['log_id'])->delivery_status);
    }

    #[Test]
    public function send_keeps_working_while_a_prompt_is_open(): void
    {
        $seed = $this->seedWorld();
        $requests = [];
        [, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fe', $seed['approval_id'])), stub: $this->stubWithMessageIds($requests));
        [, $requests] = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));
        $this->assertSingleAnswer($requests, 'รับคำสั่งแล้ว กำลังส่งให้ลูกค้า', false);
        self::assertSame('approved', $this->logRow($seed['log_id'])->delivery_status);
        self::assertSame('sent', $this->logRow($seed['log_id'])->owner_action);
    }

    // ---------------------------------------------------------------- 8) syncCard

    private function cardService(): FollowUpTelegramService
    {
        return app(FollowUpTelegramService::class);
    }

    private function fakeTelegramCapture(array &$requests): void
    {
        Http::swap(new Factory);
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
        });
    }

    #[Test]
    public function sync_card_on_pending_unedited_sends_the_full_card_with_buttons(): void
    {
        $seed = $this->seedWorld(['golden_ids' => true]);
        $requests = [];
        $this->fakeTelegramCapture($requests);

        $result = $this->cardService()->syncCard($seed['approval_id'], $this->now());

        self::assertSame('refreshed', $result);
        self::assertCount(1, $requests);
        $text = (string) $requests[0]['text'];
        self::assertStringContainsString('<b>ร่างข้อความ</b>', $text, 'pending ต้องได้การ์ดเต็มรูป ไม่ใช่บรรทัดสถานะ');
        self::assertStringContainsString('ตรวจข้อความ: ✅ ผ่าน', $text);
        self::assertStringNotContainsString('กดส่งแล้ว', $text);
        $this->assertSingleEdit($requests, null, [
            [
                ['text' => 'ส่ง', 'callback_data' => 'fs|777|1|'.self::NONCE],
                ['text' => 'ข้าม', 'callback_data' => 'fk|777|1|'.self::NONCE],
                ['text' => 'แก้ข้อความ', 'callback_data' => 'fe|777|1|'.self::NONCE],
            ],
        ]);
    }

    #[Test]
    public function sync_card_on_pending_edited_shows_the_edited_card_with_confirm_buttons(): void
    {
        $seed = $this->seedWorld($this->editedCard());
        $requests = [];
        $this->fakeTelegramCapture($requests);

        $result = $this->cardService()->syncCard($seed['approval_id'], $this->now());

        self::assertSame('refreshed', $result);
        $this->assertSingleEdit($requests, self::GOLDEN_AFTER_EDIT, [
            [
                ['text' => 'ยืนยันส่ง', 'callback_data' => 'fc|777|2|'.self::NONCE],
                ['text' => 'ข้าม', 'callback_data' => 'fk|777|2|'.self::NONCE],
                ['text' => 'แก้ข้อความ', 'callback_data' => 'fe|777|2|'.self::NONCE],
            ],
        ]);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: \Closure}> */
    public static function syncCardBlockedProvider(): array
    {
        return [
            'delivery state new' => ['state new', ['telegram_delivery_state' => 'new'], fn (array $seed): ?int => $seed['approval_id']],
            'delivery state sending with message' => ['state sending', ['telegram_delivery_state' => 'sending', 'telegram_message_id' => 77], fn (array $seed): ?int => $seed['approval_id']],
            'delivery state unknown' => ['state unknown', ['telegram_delivery_state' => 'unknown', 'telegram_message_id' => 77], fn (array $seed): ?int => $seed['approval_id']],
            'delivery state failed' => ['state failed', ['telegram_delivery_state' => 'failed', 'telegram_message_id' => 77], fn (array $seed): ?int => $seed['approval_id']],
            'message id null' => ['no message id', ['telegram_message_id' => null], fn (array $seed): ?int => $seed['approval_id']],
            'plugin missing' => ['plugin missing', [], function (array $seed): ?int {
                // FK nullOnDelete: ลบ plugin แล้ว telegram_plugin_id ของบัตรกลายเป็น null
                DB::table('flow_plugins')->where('id', $seed['plugin']->id)->delete();

                return $seed['approval_id'];
            }],
            'plugin disabled' => ['plugin disabled', [], function (array $seed): ?int {
                DB::table('flow_plugins')->where('id', $seed['plugin']->id)->update(['enabled' => false]);

                return $seed['approval_id'];
            }],
            'plugin without token' => ['no token', [], function (array $seed): ?int {
                $config = json_decode((string) DB::table('flow_plugins')->where('id', $seed['plugin']->id)->value('config'), true);
                unset($config['access_token']);
                DB::table('flow_plugins')->where('id', $seed['plugin']->id)->update(['config' => json_encode($config, JSON_UNESCAPED_UNICODE)]);

                return $seed['approval_id'];
            }],
            'plugin chat differs from approval chat' => ['chat differs', ['approval_overrides' => ['telegram_chat_id' => '777']], fn (array $seed): ?int => $seed['approval_id']],
            'bot missing' => ['bot missing', [], function (array $seed): ?int {
                $user = User::factory()->owner()->create();
                $bot2 = Bot::factory()->active()->line()->create(['user_id' => $user->id]);
                $flow2 = Flow::factory()->create(['bot_id' => $bot2->id]);
                $bot2->update(['default_flow_id' => $flow2->id]);
                DB::table('lead_recovery_logs')->where('id', $seed['log_id'])->update(['bot_id' => $bot2->id]);
                // cascade: ลบ bot2 แล้ว log และ approval หายตาม — อ่านบัตรไม่เจอ = blocked
                $bot2->delete();

                return $seed['approval_id'];
            }],
            'log missing' => ['log missing', [], function (array $seed): ?int {
                DB::table('lead_recovery_logs')->where('id', $seed['log_id'])->delete(); // cascade ลบ approval ตาม

                return null;
            }],
            'approval missing' => ['approval missing', [], fn (array $seed): ?int => null],
        ];
    }

    #[Test]
    #[DataProvider('syncCardBlockedProvider')]
    public function sync_card_blocked_cases_write_nothing_and_call_nothing(string $label, array $seedOptions, \Closure $target): void
    {
        $seed = $this->seedWorld($seedOptions);
        $approvalId = $target($seed);
        // จับ before หลัง mutate — FK บางกรณี (เช่น nullOnDelete) เปลี่ยนแถวตั้งแต่ตอนจัดโลก
        $before = $this->approvalRow($seed['approval_id']);
        $requests = [];
        $this->fakeTelegramCapture($requests);

        $result = $this->cardService()->syncCard($approvalId ?? 999999, $this->now());

        self::assertSame('blocked', $result, $label.' ต้อง blocked');
        self::assertCount(0, $requests, $label.' ห้ามยิงออก');
        $after = $this->approvalRow($seed['approval_id']);
        if ($before !== null && $after !== null) {
            $this->assertRowChangedOnly($before, $after, []);
        }
    }

    #[Test]
    public function sync_card_on_editing_status_is_unchanged_without_requests(): void
    {
        $seed = $this->seedWorld(['approval_status' => 'editing']);
        $requests = [];
        $this->fakeTelegramCapture($requests);

        $result = $this->cardService()->syncCard($seed['approval_id'], $this->now());

        self::assertSame('unchanged', $result);
        self::assertCount(0, $requests);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function syncCardOtherStatusProvider(): array
    {
        return [
            'approved' => ['approved', []],
            'sent' => ['sent', []],
            'skipped' => ['skipped', []],
            'expired' => ['expired', []],
            'cancelled' => ['cancelled', ['log_delivery_status' => 'cancelled', 'log_overrides' => ['reason_code' => 'new_customer_message']]],
        ];
    }

    #[Test]
    #[DataProvider('syncCardOtherStatusProvider')]
    public function sync_card_on_other_statuses_behaves_exactly_like_refresh_card(string $status, array $seedOptions): void
    {
        $seed = $this->seedWorld(array_merge(['approval_status' => $status], $seedOptions));
        $requests = [];
        $this->fakeTelegramCapture($requests);
        $refreshResult = $this->cardService()->refreshCard($seed['approval_id'], $this->now());
        $refreshText = count($requests) > 0 ? (string) $requests[0]['text'] : null;
        $refreshEditCount = count($requests);

        $requests = [];
        $this->fakeTelegramCapture($requests);
        $syncResult = $this->cardService()->syncCard($seed['approval_id'], $this->now());
        $syncText = count($requests) > 0 ? (string) $requests[0]['text'] : null;

        self::assertSame($refreshResult, $syncResult, $status.' sync ต้องได้ผลเดียวกับ refresh');
        self::assertSame($refreshText, $syncText);
        self::assertSame($refreshEditCount, count($requests));
    }

    #[Test]
    public function sync_card_transport_failures_map_to_failed_and_unknown(): void
    {
        $seed = $this->seedWorld();

        $requests = [];
        Http::swap(new Factory);
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response(json_encode(['ok' => false]), 400);
        });
        self::assertSame('failed', $this->cardService()->syncCard($seed['approval_id'], $this->now()));

        $requests = [];
        Http::swap(new Factory);
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response(json_encode(['ok' => false]), 500);
        });
        self::assertSame('unknown', $this->cardService()->syncCard($seed['approval_id'], $this->now()));

        $requests = [];
        Http::swap(new Factory);
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;
            throw new ConnectionException('cURL error 28');
        });
        self::assertSame('unknown', $this->cardService()->syncCard($seed['approval_id'], $this->now()));

        self::assertSame('pending', $this->approvalRow($seed['approval_id'])->status);
        $this->assertNoSecretsInLog();
    }

    // ---------------------------------------------------------------- 9) การขนส่ง sendFollowUpText

    private function textService(): TelegramAlertBotService
    {
        return app(TelegramAlertBotService::class);
    }

    #[Test]
    public function send_follow_up_text_carries_the_prompt_fields_and_no_parse_mode(): void
    {
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response(json_encode(['ok' => true, 'result' => ['message_id' => 5]]), 200);
        });

        $result = $this->textService()->sendFollowUpText('TOK', '888', 'ช่องแก้ '.self::HTML_CANARY, 77, 'คำใบ้');

        self::assertSame('accepted', $result['outcome']);
        self::assertSame(200, $result['status']);
        self::assertSame(5, $result['message_id']);
        self::assertCount(1, $requests);
        self::assertStringEndsWith('/botTOK/sendMessage', (string) $requests[0]->url());
        self::assertSame('888', $requests[0]['chat_id']);
        self::assertSame('ช่องแก้ '.self::HTML_CANARY, $requests[0]['text']);
        self::assertArrayNotHasKey('parse_mode', $requests[0], 'ข้อความล้วนห้ามมี parse_mode');
        self::assertSame(json_encode(['is_disabled' => true]), $requests[0]['link_preview_options']);
        self::assertSame(json_encode(['message_id' => 77, 'allow_sending_without_reply' => true]), $requests[0]['reply_parameters']);
        self::assertSame(json_encode(['force_reply' => true, 'input_field_placeholder' => 'คำใบ้']), $requests[0]['reply_markup']);
        foreach ($this->logEvents as $event) {
            $line = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString(self::HTML_CANARY, $line, 'ข้อความห้ามลง log');
        }
    }

    #[Test]
    public function send_follow_up_text_without_reply_and_placeholder_sends_bare_fields(): void
    {
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response(json_encode(['ok' => true, 'result' => ['message_id' => 9]]), 200);
        });

        $result = $this->textService()->sendFollowUpText('TOK', '888', 'แจ้งเฉยๆ');

        self::assertSame('accepted', $result['outcome']);
        self::assertArrayNotHasKey('reply_parameters', $requests[0]);
        self::assertArrayNotHasKey('reply_markup', $requests[0]);
    }

    /** @return array<string, array{0: string, 1: \Closure, 2: string, 3: ?int}> */
    public static function sendTextTransportProvider(): array
    {
        $ok = fn (array $body, int $status = 200) => fn () => Http::response(json_encode($body), $status);
        $html = fn (int $status = 200) => fn () => Http::response('<html>nope</html>', $status);

        return [
            'ok result object' => ['ok result', $ok(['ok' => true, 'result' => ['message_id' => 7]]), 'accepted', 7],
            'ok result true' => ['ok no id', $ok(['ok' => true, 'result' => true]), 'unknown', null],
            'ok without result' => ['ok empty', $ok(['ok' => true]), 'unknown', null],
            'ok false at 200' => ['ok false 200', $ok(['ok' => false]), 'unknown', null],
            'html at 200' => ['html 200', $html(), 'unknown', null],
            '400 ok false' => ['400', $ok(['ok' => false], 400), 'rejected', null],
            '403 ok false' => ['403', $ok(['ok' => false], 403), 'rejected', null],
            '429 ok false' => ['429', $ok(['ok' => false], 429), 'rejected', null],
            'html at 400' => ['html 400', $html(400), 'unknown', null],
            'redirect' => ['302', fn () => Http::response('', 302, ['Location' => 'https://elsewhere.test']), 'unknown', null],
            '500' => ['500', $ok(['ok' => false], 500), 'unknown', null],
        ];
    }

    #[Test]
    #[DataProvider('sendTextTransportProvider')]
    public function send_follow_up_text_transport_table(string $label, \Closure $stub, string $expectedOutcome, ?int $expectedMessageId): void
    {
        Http::fake(fn ($request) => $stub());

        $result = $this->textService()->sendFollowUpText('TOK', '888', 'ข้อความ '.self::BODY_CANARY);

        self::assertSame($expectedOutcome, $result['outcome'], $label);
        self::assertSame($expectedMessageId, $result['message_id']);
        self::assertArrayHasKey('status', $result);
        foreach ($this->logEvents as $event) {
            $line = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString(self::BODY_CANARY, $line);
            self::assertStringNotContainsString('TOK', $line);
        }
    }

    #[Test]
    public function send_follow_up_text_thrown_connection_is_unknown_without_leaking(): void
    {
        Http::fake(function ($request) {
            throw new ConnectionException('cURL error 28');
        });

        $result = $this->textService()->sendFollowUpText('TOK', '888', 'ข้อความ '.self::BODY_CANARY);

        self::assertSame(['outcome' => 'unknown', 'status' => null, 'message_id' => null], $result);
        foreach ($this->logEvents as $event) {
            $line = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString(self::BODY_CANARY, $line);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: ?int}> */
    public static function sendTextRejectProvider(): array
    {
        return [
            'empty token' => ['', '888', 'ข้อความ', null],
            'empty chat' => ['TOK', '', 'ข้อความ', null],
            'empty text' => ['TOK', '888', '', 77],
            'reply id zero' => ['TOK', '888', 'ข้อความ', 0],
        ];
    }

    #[Test]
    #[DataProvider('sendTextRejectProvider')]
    public function send_follow_up_text_rejects_empty_arguments_before_any_request(string $token, string $chatId, string $text, ?int $replyId): void
    {
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response(json_encode(['ok' => true]), 200);
        });

        $result = $this->textService()->sendFollowUpText($token, $chatId, $text, $replyId);

        self::assertSame('rejected', $result['outcome']);
        self::assertNull($result['message_id']);
        self::assertCount(0, $requests);
    }

    #[Test]
    public function send_follow_up_text_real_socket_to_closed_port_is_unknown_without_leaking(): void
    {
        $port = 55800 + (getmypid() % 900);
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

        $result = $service->sendFollowUpText($this->pluginToken(), self::PLUGIN_CHAT, 'ช่องแก้ '.self::HTML_CANARY);

        self::assertSame(['outcome' => 'unknown', 'status' => null, 'message_id' => null], $result);
        $this->assertNoBotMarkerInLog();
        $this->assertNoSecretsInLog();
    }

    #[Test]
    public function saving_an_edit_invalidates_the_previous_clean_check_and_confirmation(): void
    {
        $this->bindPinnedNonce(self::NEW_NONCE);
        $seed = $this->bindPrompt([
            'golden_ids' => true,
            'check_answers' => self::CLEAN_CHECK,
            'approval_updated_at' => '2026-11-16 09:59:00',
            'approval_overrides' => [
                'check_overridden' => true,
                'confirmed_revision' => 1,
                'last_update_id' => self::UPDATE_ID - 1,
            ],
        ]);
        $before = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);
        self::assertSame(self::DRAFT, $before->text);
        self::assertSame(1, (int) $before->revision);
        self::assertSame(self::NONCE, $before->nonce);
        self::assertNotNull($before->check_answers);
        self::assertFalse(FollowUpSendService::checkFlagged((string) $before->check_answers));
        self::assertSame(1, (int) $before->check_overridden);
        self::assertSame(1, (int) $before->confirmed_revision);
        self::assertSame('2026-11-16 09:59:00', $before->updated_at);
        $requests = [];

        [$response, $requests] = $this->postReply($this->replyMessage(), stub: $this->stubWithMessageIds($requests));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $after = $this->approvalRow($seed['approval_id']);
        self::assertSame(self::EDITED_TEXT, $after->text);
        self::assertSame(2, (int) $after->revision);
        self::assertSame(self::NEW_NONCE, $after->nonce);
        self::assertNull($after->check_answers, 'The old clean check must not apply to the new wording');
        self::assertSame(0, (int) $after->check_overridden);
        self::assertNull($after->confirmed_revision);
        self::assertSame(self::NOW, $after->updated_at);
        self::assertSame(self::UPDATE_ID, (int) $after->last_update_id);
        $this->assertRowChangedOnly($before, $after, [
            'text', 'revision', 'nonce', 'check_answers', 'check_overridden', 'confirmed_revision', 'updated_at', 'last_update_id',
        ]);
        $this->assertRowChangedOnly($beforeLog, $this->logRow($seed['log_id']), []);

        self::assertCount(2, $requests);
        self::assertSame('editMessageText', $this->methodOf($requests[0]));
        self::assertSame('sendMessage', $this->methodOf($requests[1]));
        $this->assertSingleEdit($requests, self::GOLDEN_AFTER_EDIT, [
            [
                ['text' => 'ยืนยันส่ง', 'callback_data' => 'fc|777|2|'.self::NEW_NONCE],
                ['text' => 'ข้าม', 'callback_data' => 'fk|777|2|'.self::NEW_NONCE],
                ['text' => 'แก้ข้อความ', 'callback_data' => 'fe|777|2|'.self::NEW_NONCE],
            ],
        ]);
        $this->assertSingleSend($requests, str_replace('{log_id}', (string) $seed['log_id'], self::NOTICE_EDIT_SAVED), 900);
        $this->assertOnlyTelegramHost($requests);
        Queue::assertNothingPushed();
        Bus::assertDispatched(CheckEditedFollowUp::class, fn ($job) => $job->approvalId === $seed['approval_id'] && $job->revision === 2);
        Bus::assertDispatchedTimes(CheckEditedFollowUp::class, 1);
        $this->assertOnlyEditChecks(1);
        self::assertSame(2, DB::table('messages')->where('sender', 'bot')->count());
        $this->assertNoSecretsInLog();
    }

    #[Test]
    public function two_saved_edits_dispatch_only_their_new_revisions(): void
    {
        $seed = $this->bindPrompt();
        $this->postReply($this->replyMessage())[0]->assertOk();
        $this->postReply($this->replyMessage(['text' => 'รบกวนอีกทีครับ']), updateId: self::UPDATE_ID + 1)[0]->assertOk();

        $jobs = Bus::dispatched(CheckEditedFollowUp::class);
        self::assertSame([[$seed['approval_id'], 2], [$seed['approval_id'], 3]], $jobs->map(fn ($job) => [$job->approvalId, $job->revision])->all());
        $this->assertOnlyEditChecks(2);
    }

    private function assertOnlyEditChecks(int $count): void
    {
        $fake = Bus::getFacadeRoot();
        // This installed BusFake has no total-count API; inspect all three dispatch paths.
        $commands = (new \ReflectionProperty($fake, 'commands'))->getValue($fake);
        self::assertSame([CheckEditedFollowUp::class], array_keys($commands));
        self::assertCount($count, $commands[CheckEditedFollowUp::class]);
        self::assertSame([], (new \ReflectionProperty($fake, 'commandsSync'))->getValue($fake));
        self::assertSame([], (new \ReflectionProperty($fake, 'commandsAfterResponse'))->getValue($fake));
    }

    public static function noEditCheckDispatchCases(): iterable
    {
        yield 'not editor' => [['approval_overrides' => ['editor_telegram_user_id' => '777']], [], false, self::NOTICE_EDIT_NOT_EDITOR, 'refused', 'not_open'];
        yield 'closed approval' => [['approval_status' => 'skipped'], [], false, self::NOTICE_EDIT_CLOSED, 'refused', 'not_open'];
        yield 'closed log' => [['log_delivery_status' => 'approved'], [], false, self::NOTICE_EDIT_CLOSED, 'refused', 'not_open'];
        yield 'expired' => [['expires_at' => self::NOW], [], false, self::NOTICE_EDIT_EXPIRED, 'refused', 'not_open'];
        yield 'duplicate update' => [['approval_overrides' => ['last_update_id' => self::UPDATE_ID]], [], false, null, 'ignored', 'repeat_update'];
        yield 'save race' => [[], [], true, self::NOTICE_EDIT_CLOSED, 'refused', 'save_race'];
        foreach (self::editReplyKindProvider() as $label => [$message]) {
            yield 'kind / '.$label => [[], $message, false, self::INVALID_NOT_TYPED.self::EDIT_RETRY_SUFFIX, 'refused', 'invalid_kind'];
        }
        foreach (self::editReplyTextRulesProvider() as $label => [, $text, $notice]) {
            if ($notice !== null) {
                yield 'text / '.$label => [[], ['text' => $text], false, $notice.self::EDIT_RETRY_SUFFIX, 'refused', 'invalid_text'];
            }
        }
        yield 'malformed room' => [[], ['chat' => ['id' => null]], false, null, 'ignored', 'room'];
        yield 'wrong room' => [[], ['chat' => ['id' => 999]], false, null, 'ignored', 'room'];
        yield 'malformed reply' => [[], ['reply_to_message' => null], false, null, 'ignored', 'reply_shape'];
        yield 'unauthorized person' => [[], ['from' => ['id' => 999]], false, null, 'ignored', 'person'];
        yield 'wrong prompt' => [[], ['reply_to_message' => ['message_id' => 999, 'from' => ['is_bot' => true], 'text' => self::GOLDEN_PROMPT]], false, self::NOTICE_EDIT_GONE, 'refused', 'binding'];
        yield 'binding lost after lookup' => [[], [], false, self::NOTICE_EDIT_GONE, 'refused', 'binding', true];
    }

    #[Test]
    #[DataProvider('noEditCheckDispatchCases')]
    public function refused_or_duplicate_edit_never_dispatches_a_check(array $options, array $message, bool $race, ?string $expectedNotice, string $expectedResult, string $expectedReason, bool $loseBinding = false): void
    {
        $seed = $this->bindPrompt($options);
        $before = $this->approvalRow($seed['approval_id']);
        $beforeLog = $this->logRow($seed['log_id']);
        $raced = false;
        $bindingLost = false;
        if ($loseBinding) {
            DB::listen(function (QueryExecuted $query) use ($seed, &$bindingLost): void {
                if (! $bindingLost && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "follow_up_approvals" as "a"')) {
                    // The join returned the binding ids, but the initial row read loses it.
                    $bindingLost = true;
                    DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->delete();
                }
            });
        }
        $transactionLevel = DB::transactionLevel();
        if ($race) {
            DB::listen(function (QueryExecuted $query) use ($seed, &$raced, $transactionLevel): void {
                if (! $raced
                    && str_starts_with($query->sql, 'select')
                    && str_contains($query->sql, 'from "lead_recovery_logs"')
                    && str_contains($query->sql, 'where "id" = ?')
                    && DB::transactionLevel() === $transactionLevel + 1
                ) {
                    // QueryExecuted runs after the read: change the approval after the
                    // locked log read, but BEFORE the locked approval is fetched.
                    self::assertSame([$seed['log_id']], $query->bindings);
                    $raced = true;
                    DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update(['status' => 'skipped']);
                }
            });
        }
        try {
            [$response, $requests] = $this->postReply($this->replyMessage($message));
            $response->assertOk();
        } finally {
            app('events')->forget(QueryExecuted::class);
        }
        $editLogs = array_values(array_filter($this->logEvents, fn ($event) => $event->message === 'Follow-up edit reply'));
        self::assertCount(1, $editLogs);
        self::assertSame($expectedResult, $editLogs[0]->context['result']);
        self::assertSame($expectedReason, $editLogs[0]->context['reason']);
        $hasBinding = $loseBinding || in_array($expectedReason, ['repeat_update', 'not_open', 'invalid_kind', 'invalid_text', 'save_race'], true);
        self::assertSame($hasBinding ? $seed['approval_id'] : null, $editLogs[0]->context['approval_id']);
        self::assertSame($hasBinding ? $seed['log_id'] : null, $editLogs[0]->context['log_id']);
        if ($expectedNotice === null) {
            self::assertSame([], $requests, 'Ignored edits must be silent');
        } else {
            self::assertCount(1, $requests, 'Refused edits send only their notice');
            $this->assertSingleSend($requests, $expectedNotice, 900);
        }
        Bus::assertNothingDispatched();
        $after = $this->approvalRow($seed['approval_id']);
        if ($loseBinding) {
            self::assertNull($after, 'The racer deleted the approval; the edit must not recreate it');
        } else {
            self::assertSame((int) $before->revision, (int) $after->revision);
            $this->assertRowChangedOnly($before, $after, $race ? ['status'] : []);
            if ($race) {
                self::assertSame('skipped', $after->status);
            }
        }
        $this->assertRowChangedOnly($beforeLog, $this->logRow($seed['log_id']), []);
        self::assertSame($race, $raced);
        self::assertSame($loseBinding, $bindingLost);
    }

    #[Test]
    public function check_dispatch_observes_committed_revision_before_any_repaint_or_notice(): void
    {
        $seed = $this->bindPrompt();
        $level = DB::transactionLevel();
        $requests = [];
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function ($job) use ($seed, $level, &$requests): void {
            self::assertInstanceOf(CheckEditedFollowUp::class, $job);
            self::assertSame([$seed['approval_id'], 2], [$job->approvalId, $job->revision]);
            self::assertSame($level, DB::transactionLevel(), 'Dispatch must follow the save commit');
            self::assertSame(2, (int) $this->approvalRow($seed['approval_id'])->revision);
            self::assertSame(self::EDITED_TEXT, $this->approvalRow($seed['approval_id'])->text);
            self::assertSame([], $requests, 'Dispatch precedes both repaint and notice');
        });
        Bus::swap($dispatcher);
        [$response] = $this->postReply($this->replyMessage(), stub: $this->stubWithMessageIds($requests));
        $response->assertOk()->assertExactJson(['ok' => true]);
        self::assertCount(2, $requests);
    }

    #[Test]
    public function check_dispatch_carries_the_revision_written_under_the_lock_not_the_first_read(): void
    {
        $seed = $this->bindPrompt();
        $raced = false;
        DB::listen(function (QueryExecuted $query) use ($seed, &$raced): void {
            if (! $raced && str_starts_with($query->sql, 'select * from "follow_up_approvals" ')) {
                $raced = true;
                DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update(['revision' => 5]);
            }
        });
        try {
            $this->postReply($this->replyMessage())[0]->assertOk();
        } finally {
            app('events')->forget(QueryExecuted::class);
        }
        self::assertTrue($raced);
        self::assertSame(6, (int) $this->approvalRow($seed['approval_id'])->revision);
        Bus::assertDispatched(CheckEditedFollowUp::class, fn ($job) => $job->approvalId === $seed['approval_id'] && $job->revision === 6);
        $this->assertOnlyEditChecks(1);
    }

    #[Test]
    public function check_dispatch_does_not_reread_a_revision_changed_after_the_save_write(): void
    {
        $seed = $this->bindPrompt();
        $raced = false;
        DB::listen(function (QueryExecuted $query) use ($seed, &$raced): void {
            if (! $raced && str_starts_with($query->sql, 'update "follow_up_approvals"')) {
                $raced = true;
                DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update(['revision' => 3]);
            }
        });
        try {
            $this->postReply($this->replyMessage())[0]->assertOk();
        } finally {
            app('events')->forget(QueryExecuted::class);
        }
        self::assertTrue($raced);
        self::assertSame(3, (int) $this->approvalRow($seed['approval_id'])->revision);
        Bus::assertDispatched(CheckEditedFollowUp::class, fn ($job) => $job->approvalId === $seed['approval_id'] && $job->revision === 2);
        $this->assertOnlyEditChecks(1);
    }

    #[Test]
    public function check_dispatch_failure_preserves_edit_repaint_notice_and_class_only_warning(): void
    {
        $this->bindPinnedNonce(self::NEW_NONCE);
        $seed = $this->bindPrompt(['golden_ids' => true, 'check_answers' => self::CLEAN_CHECK, 'approval_updated_at' => '2026-11-16 09:59:00']);
        $beforeLog = $this->logRow($seed['log_id']);
        $marker = 'exception-message-must-not-leak-b6g';
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->with(\Mockery::type(CheckEditedFollowUp::class))->andThrow(new \RuntimeException($marker));
        Bus::swap($dispatcher);
        $requests = [];
        [$response, $requests] = $this->postReply($this->replyMessage(), stub: $this->stubWithMessageIds($requests));
        $response->assertOk()->assertExactJson(['ok' => true]);
        $row = $this->approvalRow($seed['approval_id']);
        self::assertSame(self::EDITED_TEXT, $row->text);
        self::assertSame(2, (int) $row->revision);
        self::assertSame(self::NEW_NONCE, $row->nonce);
        self::assertNull($row->check_answers);
        self::assertSame(self::NOW, $row->updated_at);
        $this->assertRowChangedOnly($beforeLog, $this->logRow($seed['log_id']), []);
        $this->assertSingleEdit($requests, self::GOLDEN_AFTER_EDIT);
        $this->assertSingleSend($requests, str_replace('{log_id}', (string) $seed['log_id'], self::NOTICE_EDIT_SAVED), 900);
        $warnings = array_values(array_filter($this->logEvents, fn ($e) => $e->level === 'warning'));
        self::assertCount(1, $warnings);
        self::assertSame(['approval_id' => $seed['approval_id'], 'exception_class' => \RuntimeException::class], $warnings[0]->context);
        foreach ($this->logEvents as $event) {
            $line = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
            foreach ([$marker, self::EDITED_TEXT, $this->pluginToken(), self::NONCE, self::NEW_NONCE, 'Ub6c000112233445566778899aabbccdd', '0.9'] as $private) {
                self::assertStringNotContainsString($private, $line);
            }
        }
        $this->assertNoSecretsInLog();
    }
}
