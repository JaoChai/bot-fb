<?php

namespace Tests\Feature\FollowUp;

use App\Jobs\CheckEditedFollowUp;
use App\Jobs\ReconcileCustomerFollowUps;
use App\Jobs\SendCustomerFollowUp;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\User;
use App\Services\FollowUp\FollowUpDecisionsService;
use App\Services\FollowUp\FollowUpOutcomeService;
use App\Services\FollowUp\FollowUpTelegramService;
use App\Support\FollowUpRedactor;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EditCheckJobTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 10:00:00';

    private const BEFORE = '2026-11-16 09:59:00';

    private const EXPIRES = '2026-11-16 22:12:00';

    private const TEXT = "สวัสดีครับพี่ ผมเช็กให้แล้วนะครับ\nยังสนใจอยู่ไหมครับ 🙏";

    private const DRAFT = "สวัสดีครับพี่ เมื่อวานที่คุยเรื่องบัญชีโฆษณาไว้\nมีจุดไหนให้ช่วยดูเพิ่มไหมครับ";

    private const CONTEXT = ['C: ขอคิดดูก่อนนะครับ', 'B: ได้เลยครับพี่ สนใจตัวไหนทักมาได้เลยครับ'];

    private const NONCE = 'abcd1234abcd1234';

    private const CUSTOMER = 'Ub6g000112233445566778899aabbccdd';

    private const MARKER = 'exception-message-must-not-leak-b6g';

    private const SCORES = ['support_free' => 0.1, 'promise' => 0.1, 'cannot_self' => 0.1, 'price_stock' => 0.1, 'pressure' => 0.1, 'off_topic' => 0.1];

    private const EMPTY_SCORES = ['support_free' => null, 'promise' => null, 'cannot_self' => null, 'price_stock' => null, 'pressure' => null, 'off_topic' => null];

    private const OLD_CHECK = '{"scores":{"safe_to_send":0.9},"failed":[],"error":false,"version":1}';

    private const SHOP = 'Thai online shop chat (LINE). The bot sells Facebook ad accounts (Nolimit Personal, Nolimit BM), Facebook pages and cheap profile accounts. Order flow: customer picks a product and quantity -> bot summarises -> customer types "ยืนยัน" (confirm) -> bot sends the bank transfer summary -> customer sends a payment slip. The conversation below went silent more than 3 hours ago and no payment was recorded. C = customer, B = bot.';

    private const CARD = <<<'TEXT'
🔔 <b>ตามลูกค้าที่เงียบไป</b> · #123
บอท: Line - Adsvance
ลูกค้า: สมชาย &lt;ร้าน A&amp;B&gt;
กลุ่ม: สนใจแต่ยังลังเล · Luna มั่นใจ 86%
ลูกค้าพิมพ์ล่าสุด: ขอคิดดูก่อนนะครับ
บอทตอบล่าสุด: ได้เลยครับพี่ สนใจตัวไหนทักมาได้เลยครับ

<b>ร่างข้อความ</b> · ✏️ แก้แล้ว
สวัสดีครับพี่ ผมเช็กให้แล้วนะครับ
ยังสนใจอยู่ไหมครับ 🙏

ตรวจข้อความ: {result}
{note}หมดอายุ: 16/11 22:12 (12 ชั่วโมง)
เปิดห้อง: https://admin.example.test/chat?botId=26&amp;conversationId=1317
TEXT;

    private const NOTE = "ข้อความนี้เจ้านายแก้เอง กด ยืนยันส่ง ถ้าจะส่งทั้งที่ยังไม่ผ่านการตรวจ\n";

    private string $url;

    private string $token;

    private string $key;

    private string $secret;

    private string $lineToken;

    private array $requests = [];

    private array $events = [];

    private string $expectedText = self::TEXT;

    private string $expectedGroup = 'G3';

    private ?string $rawText = null;

    private int $baseTransactionLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeze(self::NOW);
        $this->url = 'https://openrouter-decisions'.'.test/api/alpha/decisions';
        $this->token = 'tg-plugin-'.'token-b6g';
        $this->key = 'test'.'-key-b6g';
        $this->secret = 'webhook-secret-'.'b6g';
        $this->lineToken = 'line-'.'token-b6g';
        config([
            'services.openrouter.api_key' => $this->key,
            'customer_follow_up.decisions_url' => $this->url,
            'services.telegram_alert.secret' => $this->secret,
            'customer_follow_up.admin_web_url' => 'https://admin.example.test',
            'customer_follow_up.bot_ids' => [26],
            'customer_follow_up.automatic_bot_ids' => [],
            'customer_follow_up.quota_reserve_messages' => 100,
        ]);
        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->events[] = $event;
        });
        $this->baseTransactionLevel = DB::transactionLevel();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function freeze(string $when): void
    {
        Carbon::setTestNow(CarbonImmutable::parse($when, 'Asia/Bangkok'));
    }

    // Independent, reduced copy of TelegramEditTest::seedWorld/editedCard, not inheritance.
    private function seedWorld(array $approval = [], array $log = []): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create([
            'id' => 26, 'user_id' => $user->id, 'name' => 'Line - Adsvance',
            'primary_chat_model' => 'openai/gpt-6-luna', 'channel_access_token' => $this->lineToken,
        ]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        $plugin = FlowPlugin::create([
            'flow_id' => $flow->id, 'type' => 'telegram', 'name' => 'แจ้งเตือน', 'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => $this->token, 'chat_id' => '888', 'authorized_user_ids' => [555]],
        ]);
        DB::table('bot_settings')->insert([
            'bot_id' => 26, 'follow_up_enabled' => true, 'follow_up_mode' => 'approve',
            'follow_up_started_at' => '2026-10-17 10:00:00', 'follow_up_activation_message_id' => 0,
            'follow_up_activated_at' => '2026-10-17 10:00:00', 'created_at' => self::BEFORE, 'updated_at' => self::BEFORE,
        ]);
        $profile = CustomerProfile::factory()->create(['channel_type' => 'line', 'display_name' => 'สมชาย <ร้าน A&B>']);
        Conversation::factory()->line()->create([
            'id' => 1317, 'bot_id' => 26, 'customer_profile_id' => $profile->id,
            'external_customer_id' => self::CUSTOMER, 'is_handover' => false, 'status' => 'active',
        ]);
        $anchor = DB::table('messages')->insertGetId([
            'conversation_id' => 1317, 'sender' => 'user', 'content' => 'ประเมินราคาให้หน่อยครับ',
            'type' => 'text', 'created_at' => '2026-11-15 07:59:00', 'updated_at' => self::BEFORE,
        ]);
        DB::table('lead_recovery_logs')->insert(array_replace([
            'id' => 123, 'conversation_id' => 1317, 'bot_id' => 26, 'attempt_number' => 0, 'attempt_count' => 0,
            'message_mode' => 'ai', 'delivery_status' => 'pending', 'silence_user_message_id' => $anchor,
            'observed_last_message_id' => $anchor, 'customer_key' => hash('sha256', '26|line|'.self::CUSTOMER),
            'payment_step' => false, 'transfer_summary_sent' => false, 'group_code' => 'G3', 'group_confidence' => 0.86,
            'context_redacted' => json_encode(self::CONTEXT, JSON_UNESCAPED_UNICODE),
            'check_answers' => self::OLD_CHECK, 'check_overridden' => true,
            'draft' => self::DRAFT, 'drafted_at' => '2026-11-15 09:30:00', 'draft_day' => '2026-11-15',
            'draft_model' => 'openai/gpt-6-luna', 'model_cost' => 0.0125,
            'usage_totals' => '{"draft":{"input_tokens":51,"output_tokens":17,"cost":0.0125}}',
            'created_at' => self::BEFORE, 'updated_at' => self::BEFORE,
        ], $log));
        DB::table('follow_up_approvals')->insert(array_replace([
            'id' => 777, 'lead_recovery_log_id' => 123, 'status' => 'pending', 'revision' => 2, 'nonce' => self::NONCE,
            'text' => self::TEXT, 'check_answers' => null, 'check_overridden' => true, 'confirmed_revision' => 1,
            'expires_at' => self::EXPIRES, 'telegram_plugin_id' => $plugin->id, 'telegram_chat_id' => '888',
            'telegram_message_id' => 77, 'telegram_delivery_state' => 'sent', 'editor_telegram_user_id' => '555',
            'edit_prompt_message_id' => 55, 'created_at' => self::BEFORE, 'updated_at' => self::BEFORE,
        ], $approval));
    }

    private function rows(): array
    {
        return [
            ($a = DB::table('follow_up_approvals')->where('id', 777)->first()) === null ? null : (array) $a,
            ($l = DB::table('lead_recovery_logs')->where('id', 123)->first()) === null ? null : (array) $l,
        ];
    }

    private function expectedJson(array $scores = self::SCORES, array $failed = [], bool $error = false): string
    {
        return json_encode(['scores' => $scores, 'failed' => $failed, 'error' => $error, 'version' => 'check-v1'], JSON_UNESCAPED_UNICODE);
    }

    private function fakeHttp(string $kind = 'clean', ?float $cost = 0.0003, ?\Closure $during = null, string $telegram = 'ok'): void
    {
        Http::fake(function (Request $request) use ($kind, $cost, $during, $telegram) {
            $this->requests[] = $request;
            if ($request->url() === $this->url) {
                $this->assertSame($this->baseTransactionLevel, DB::transactionLevel(), 'Model I/O must precede the write transaction');
                $this->assertSame('openrouter-decisions.test', parse_url($request->url(), PHP_URL_HOST));
                $this->assertSame('POST', $request->method());
                $this->assertSame([
                    'shop' => self::SHOP, 'conversation' => self::CONTEXT, 'group' => $this->expectedGroup, 'draft' => $this->expectedText,
                ], $request['state']);
                $this->assertSame(['model', 'state', 'questions'], array_keys($request->data()));
                foreach ([self::CUSTOMER, $this->token, $this->key, $this->lineToken, $this->secret] as $private) {
                    $this->assertStringNotContainsString($private, $request->body());
                }
                if ($this->rawText !== null && $this->rawText !== $this->expectedText) {
                    $this->assertStringNotContainsString($this->rawText, json_encode($request->data(), JSON_UNESCAPED_UNICODE));
                }
                if ($during !== null) {
                    $during();
                }
                if ($kind === 'connection') {
                    throw new ConnectionException(self::MARKER);
                }
                if ($kind === 'http500') {
                    return Http::response(['body' => self::MARKER], 500);
                }
                $scores = self::SCORES;
                foreach (explode('+', $kind) as $key) {
                    if (array_key_exists($key, $scores)) {
                        $scores[$key] = 0.9;
                    }
                }
                $answers = array_map(fn ($score) => ['noul' => $score], $scores);
                if ($kind === 'non_numeric') {
                    $answers['pressure']['noul'] = 'not-a-score';
                } elseif ($kind === 'missing_key') {
                    unset($answers['off_topic']);
                }

                return Http::response(['answers' => $answers, 'usage' => ['input_tokens' => 30, 'output_tokens' => 8, 'cost' => $cost]]);
            }
            if (parse_url($request->url(), PHP_URL_HOST) === 'api.telegram.org') {
                $this->assertSame($this->baseTransactionLevel, DB::transactionLevel(), 'Repaint must follow the commit');
                if ($telegram === 'connection') {
                    throw new ConnectionException(self::MARKER);
                }

                return Http::response(['ok' => $telegram === 'ok', 'result' => true], $telegram === 'http500' ? 500 : 200);
            }
            if ($request->url() === 'https://api.line.me/v2/bot/message/quota') {
                return Http::response(['type' => 'limited', 'value' => 200]);
            }
            if ($request->url() === 'https://api.line.me/v2/bot/message/quota/consumption') {
                return Http::response(['totalUsage' => 10]);
            }
            if ($request->url() === 'https://api.line.me/v2/bot/message/push') {
                return Http::response('{}', 200, ['x-line-request-id' => 'req-b6g']);
            }
            $this->fail('Unexpected HTTP request: '.$request->url());
        });
    }

    private function handle(int $id = 777, int $revision = 2, ?FollowUpTelegramService $telegram = null): void
    {
        (new CheckEditedFollowUp($id, $revision))->handle(app(FollowUpDecisionsService::class), $telegram ?? app(FollowUpTelegramService::class));
    }

    private function jobEvents(): array
    {
        return array_values(array_filter($this->events, fn ($e) => $e->message === 'Follow-up edit check'));
    }

    private function assertInfo(string $result): void
    {
        $events = $this->jobEvents();
        $this->assertCount(1, $events);
        $this->assertSame('info', $events[0]->level);
        $context = $events[0]->context;
        $this->assertSame(['approval_id', 'log_id', 'revision', 'result', 'milliseconds'], array_keys($context));
        $this->assertSame([777, 123, 2, $result], array_slice(array_values($context), 0, 4));
        $this->assertIsInt($context['milliseconds']);
        $this->assertGreaterThanOrEqual(0, $context['milliseconds']);
        $this->assertPrivateLogs();
    }

    private function assertPrivateLogs(): void
    {
        foreach ($this->events as $event) {
            $line = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
            foreach ([self::TEXT, self::MARKER, self::CUSTOMER, $this->token, $this->key, $this->lineToken, $this->secret, self::NONCE, '0.9', '0.1'] as $private) {
                $this->assertStringNotContainsString($private, $line);
            }
        }
    }

    private function assertPaint(string $result, bool $clean, string $text = self::TEXT, string $nonce = self::NONCE): void
    {
        $edits = array_values(array_filter($this->requests, fn ($r) => str_ends_with($r->url(), '/editMessageText')));
        $this->assertCount(1, $edits);
        $this->assertSame('api.telegram.org', parse_url($edits[0]->url(), PHP_URL_HOST));
        $this->assertSame(str_replace([self::TEXT, '{result}', '{note}'], [$text, $result, $clean ? '' : self::NOTE], self::CARD), $edits[0]['text']);
        $this->assertSame([
            'inline_keyboard' => [[
                ['text' => $clean ? 'ส่ง' : 'ยืนยันส่ง', 'callback_data' => ($clean ? 'fs' : 'fc').'|777|2|'.$nonce],
                ['text' => 'ข้าม', 'callback_data' => 'fk|777|2|'.$nonce],
                ['text' => 'แก้ข้อความ', 'callback_data' => 'fe|777|2|'.$nonce],
            ]],
        ], json_decode($edits[0]['reply_markup'], true));
    }

    private function assertWritten(array $before, string $json, ?float $cost, string $when = self::NOW): void
    {
        [$approval, $log] = $this->rows();
        $this->assertSame(array_replace($before[0], ['check_answers' => $json, 'updated_at' => $when]), $approval);
        $expectedLog = $before[1];
        if ($cost !== null) {
            $this->assertSame($cost, (float) $log['model_cost']);
            $expectedLog['model_cost'] = $log['model_cost']; // Driver encodes DECIMAL as string on PostgreSQL.
            $expectedLog['updated_at'] = $when;
        }
        $this->assertSame($expectedLog, $log);
    }

    public static function results(): iterable
    {
        $labels = [
            'support_free' => 'บอกว่า Support ฟรี', 'promise' => 'สัญญาผลหรือเวลา', 'cannot_self' => 'บอกว่าลูกค้าทำเองไม่ได้',
            'price_stock' => 'พูดถึงราคาหรือสต็อก', 'pressure' => 'เร่งหรือกดดันลูกค้า', 'off_topic' => 'ไม่ตรงเรื่องที่คุยค้างไว้',
        ];
        $cases = ['clean' => [self::SCORES, [], false, '✅ ผ่าน']];
        foreach ($labels as $key => $label) {
            $cases[$key] = [array_replace(self::SCORES, [$key => 0.9]), [$key], false, '⚠️ เตือน: '.$label];
        }
        $cases['support_free+pressure'] = [array_replace(self::SCORES, ['support_free' => 0.9, 'pressure' => 0.9]), ['support_free', 'pressure'], false, '⚠️ เตือน: บอกว่า Support ฟรี · เร่งหรือกดดันลูกค้า'];
        $cases['http500'] = [self::EMPTY_SCORES, ['check_error'], true, '⚠️ ตรวจไม่ได้'];
        $cases['connection'] = $cases['http500'];
        $cases['non_numeric'] = [array_replace(self::SCORES, ['pressure' => null]), ['check_error'], true, '⚠️ ตรวจไม่ได้'];
        $cases['missing_key'] = [array_replace(self::SCORES, ['off_topic' => null]), ['check_error'], true, '⚠️ ตรวจไม่ได้'];
        foreach ($cases as $kind => [$scores, $failed, $error, $line]) {
            foreach (['no cost' => [null, null], 'first cost' => [null, 0.0003], 'add cost' => [0.0125, 0.0003]] as $seed => [$old, $cost]) {
                $returned = in_array($kind, ['http500', 'connection'], true) ? null : $cost;
                $want = $returned === null ? null : ($old === null ? 0.0003 : 0.0128);
                yield $kind.' / '.$seed => [$kind, $scores, $failed, $error, $line, $old, $cost, $want];
            }
        }
    }

    #[DataProvider('results')]
    public function test_result_persists_exact_rows_and_paints_once(string $kind, array $scores, array $failed, bool $error, string $line, ?float $old, ?float $cost, ?float $want): void
    {
        $this->seedWorld(log: ['model_cost' => $old]);
        $before = $this->rows();
        $this->fakeHttp($kind, $cost);
        $this->handle();
        $this->assertWritten($before, $this->expectedJson($scores, $failed, $error), $want);
        $this->assertPaint($line, $kind === 'clean');
        $this->assertCount(2, $this->requests);
        $this->assertInfo($error ? 'unreadable' : ($failed === [] ? 'clean' : 'flagged'));
        $after = $this->rows();
        $this->requests = [];
        $this->events = [];
        $this->handle();
        $this->assertSame($after, $this->rows());
        Http::assertSentCount(2);
        $this->assertSame([], $this->requests);
        $this->assertSame([], $this->events);
    }

    public static function closed(): iterable
    {
        yield 'revision mismatch only' => [['revision' => 3], [], 777];
        yield 'checked only' => [['check_answers' => self::OLD_CHECK], [], 777];
        foreach (['approved', 'skipped', 'expired', 'cancelled', 'sending'] as $status) {
            yield 'approval '.$status.' only' => [['status' => $status], [], 777];
        }
        yield 'log status only' => [[], ['delivery_status' => 'approved'], 777];
        yield 'equal expiry only' => [['expires_at' => self::NOW], [], 777];
        yield 'past expiry only' => [['expires_at' => '2026-11-16 09:59:59'], [], 777];
        yield 'unknown id only' => [[], [], 999];
    }

    #[DataProvider('closed')]
    public function test_initial_closed_condition_alone_is_read_only_and_silent(array $approval, array $log, int $id): void
    {
        $this->seedWorld($approval, $log);
        $before = $this->rows();
        $this->fakeHttp();
        $this->handle($id);
        $this->assertSame($before, $this->rows());
        Http::assertNothingSent();
        $this->assertSame([], $this->events);
    }

    public function test_missing_log_after_the_initial_approval_read_is_silent(): void
    {
        $this->seedWorld();
        $racer = null;
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$racer, &$fired): void {
            if (! $fired && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "follow_up_approvals"')) {
                $fired = true;
                DB::table('lead_recovery_logs')->where('id', 123)->delete();
                $racer = $this->rows();
            }
        });
        $this->fakeHttp();
        $this->handle();
        $this->assertTrue($fired);
        $this->assertSame($racer, $this->rows());
        Http::assertNothingSent();
        $this->assertSame([], $this->events);
    }

    public function test_one_second_before_expiry_is_open(): void
    {
        $this->seedWorld(['expires_at' => '2026-11-16 10:00:01']);
        $this->fakeHttp();
        $this->handle();
        $this->assertSame($this->expectedJson(), $this->rows()[0]['check_answers']);
        $this->assertCount(2, $this->requests);
    }

    public static function racers(): iterable
    {
        yield 'owner edits' => [['revision' => 3, 'text' => 'รบกวนอีกทีครับ', 'nonce' => 'ffff0000ffff0000'], [], null, null];
        yield 'owner confirms' => [['status' => 'approved'], ['delivery_status' => 'approved'], null, null];
        yield 'owner skips' => [['status' => 'skipped'], ['delivery_status' => 'skipped'], null, null];
        yield 'approval alone closes' => [['status' => 'cancelled'], [], null, null];
        yield 'log alone closes' => [[], ['delivery_status' => 'approved'], null, null];
        yield 'another check lands' => [['check_answers' => self::OLD_CHECK], [], null, null];
        yield 'clock reaches expiry' => [[], [], self::EXPIRES, null];
        yield 'clock passes expiry' => [[], [], '2026-11-16 22:12:01', null];
        yield 'approval disappears' => [[], [], null, 'approval'];
        yield 'log disappears' => [[], [], null, 'log'];
    }

    #[DataProvider('racers')]
    public function test_inflight_race_drops_result_cost_and_repaint(array $approval, array $log, ?string $clock, ?string $delete): void
    {
        $this->seedWorld();
        $racer = null;
        $this->fakeHttp(during: function () use ($approval, $log, $clock, $delete, &$racer): void {
            if ($approval !== []) {
                DB::table('follow_up_approvals')->where('id', 777)->update($approval);
            }
            if ($log !== []) {
                DB::table('lead_recovery_logs')->where('id', 123)->update($log);
            }
            if ($clock !== null) {
                $this->freeze($clock);
            }
            if ($delete !== null) {
                DB::table($delete === 'approval' ? 'follow_up_approvals' : 'lead_recovery_logs')->where('id', $delete === 'approval' ? 777 : 123)->delete();
            }
            $racer = $this->rows();
        });
        $this->handle();
        $this->assertNotNull($racer);
        $this->assertSame($racer, $this->rows());
        $this->assertCount(1, $this->requests);
        $this->assertInfo('dropped');
    }

    public function test_reconciler_expiring_during_model_call_wins(): void
    {
        $this->seedWorld();
        $racer = null;
        $this->fakeHttp(during: function () use (&$racer): void {
            $this->freeze(self::EXPIRES);
            // Reconciler is real; its repaint is isolated so only this job's HTTP is counted.
            $telegram = \Mockery::mock(FollowUpTelegramService::class);
            $telegram->shouldReceive('refreshCard')->once()->with(777, \Mockery::type(CarbonImmutable::class))->andReturn('refreshed');
            (new ReconcileCustomerFollowUps)->handle($telegram, app(FollowUpOutcomeService::class));
            $racer = $this->rows();
            $this->assertSame('expired', $racer[0]['status']);
            $this->assertSame('expired', $racer[1]['delivery_status']);
        });
        $this->handle();
        $this->assertSame($racer, $this->rows());
        $this->assertCount(1, $this->requests);
        $this->assertInfo('dropped');
    }

    public function test_second_clock_sets_both_timestamps_after_seven_seconds(): void
    {
        $this->seedWorld();
        $before = $this->rows();
        $this->fakeHttp(during: fn () => $this->freeze('2026-11-16 10:00:07'));
        $this->handle();
        $this->assertWritten($before, $this->expectedJson(), 0.0128, '2026-11-16 10:00:07');
        $this->assertInfo('clean');
    }

    public static function texts(): array
    {
        return [
            'plain Thai' => ['ยังสนใจอยู่ไหมครับ', 'ยังสนใจอยู่ไหมครับ'],
            'price and at' => ['ลดเหลือ 1,500 บาทครับ ทักที่ @adsvance ได้เลย', 'ลดเหลือ 1,500 บาทครับ ทักที่ @adsvance ได้เลย'],
            'ascii dots' => ['รอพี่อยู่นะครับ...', 'รอพี่อยู่นะครับ...'],
            'ellipsis' => ['รอพี่อยู่นะครับ…', 'รอพี่อยู่นะครับ...'],
            'full width' => ['โปร ＡＢＣ ครับ', 'โปร ABC ครับ'],
            'fraction' => ['ลด ½ ราคา', 'ลด 1⁄2 ราคา'],
        ];
    }

    #[DataProvider('texts')]
    public function test_only_redacted_owner_text_leaves_while_stored_text_stays_exact(string $raw, string $safe): void
    {
        $this->seedWorld(['text' => $raw]);
        $this->rawText = $raw;
        $this->expectedText = $safe;
        $before = $this->rows();
        $this->fakeHttp();
        $this->handle();
        $this->assertWritten($before, $this->expectedJson(), 0.0128);
        $this->assertPaint('✅ ผ่าน', true, $raw);
        $this->assertCount(2, $this->requests);
        $this->assertInfo('clean');
    }

    public static function badContexts(): iterable
    {
        yield 'null' => [null];
        yield 'not JSON' => ['not JSON'];
        yield 'empty list' => ['[]'];
        // A nonempty object must not be decoded as an associative array of safe lines.
        yield 'object' => ['{"line":"C: ขอคิดดูก่อนนะครับ"}'];
        yield 'too many lines' => [json_encode(array_fill(0, FollowUpRedactor::MAX_LINES + 1, 'C: สนใจครับ'), JSON_UNESCAPED_UNICODE)];
    }

    #[DataProvider('badContexts')]
    public function test_invalid_context_stores_unreadable_without_model_request(?string $context): void
    {
        if ($context === 'not JSON' && DB::getDriverName() === 'pgsql') {
            $this->markTestSkipped('PostgreSQL JSON column rejects malformed JSON before the job can read it; exercised on SQLite.');
        }
        $this->seedWorld(log: ['context_redacted' => $context]);
        $before = $this->rows();
        $this->fakeHttp();
        $this->handle();
        $this->assertWritten($before, $this->expectedJson(self::EMPTY_SCORES, ['check_error'], true), null);
        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('ตรวจข้อความ: ⚠️ ตรวจไม่ได้', $this->requests[0]['text']);
        $this->assertInfo('unreadable');
    }

    public function test_missing_api_key_stores_unreadable_and_only_repaints(): void
    {
        $this->seedWorld();
        config(['services.openrouter.api_key' => null]);
        $before = $this->rows();
        $this->fakeHttp();
        $this->handle();
        $this->assertWritten($before, $this->expectedJson(self::EMPTY_SCORES, ['check_error'], true), null);
        $this->assertCount(1, $this->requests);
        $this->assertPaint('⚠️ ตรวจไม่ได้', false);
        $this->assertInfo('unreadable');
    }

    public function test_null_group_is_empty_string_not_a_constant(): void
    {
        $this->seedWorld(log: ['group_code' => null]);
        $this->expectedGroup = '';
        $this->fakeHttp();
        $this->handle();
        $this->assertSame($this->expectedJson(), $this->rows()[0]['check_answers']);
        $this->assertCount(2, $this->requests);
    }

    #[DataProvider('telegramFailures')]
    public function test_telegram_failure_does_not_undo_the_committed_check(string $failure): void
    {
        $this->seedWorld();
        $before = $this->rows();
        $this->fakeHttp(telegram: $failure);
        $this->handle();
        $this->assertWritten($before, $this->expectedJson(), 0.0128);
        $this->assertCount(2, $this->requests);
        $this->assertInfo('clean');
    }

    public static function telegramFailures(): array
    {
        return [['http500'], ['connection']];
    }

    public function test_throwing_sync_is_caught_after_commit_with_class_only_warning(): void
    {
        $this->seedWorld();
        $before = $this->rows();
        $this->fakeHttp();
        $telegram = \Mockery::mock(FollowUpTelegramService::class);
        $telegram->shouldReceive('syncCard')->once()->with(777, \Mockery::on(function ($instant): bool {
            $this->assertSame($this->baseTransactionLevel, DB::transactionLevel());
            $this->assertSame(self::NOW, $instant->format('Y-m-d H:i:s'));
            $this->assertSame($this->expectedJson(), $this->rows()[0]['check_answers']);

            return true;
        }))->andThrow(new RuntimeException(self::MARKER));
        $this->handle(telegram: $telegram);
        $this->assertWritten($before, $this->expectedJson(), 0.0128);
        $warnings = array_values(array_filter($this->events, fn ($e) => $e->level === 'warning'));
        $this->assertCount(1, $warnings);
        $this->assertSame(['approval_id' => 777, 'exception_class' => RuntimeException::class], $warnings[0]->context);
        $this->assertInfo('clean');
    }

    public static function taps(): array
    {
        return [
            'clean send' => ['clean', 'fs', 'รับคำสั่งแล้ว กำลังส่งให้ลูกค้า', true, false],
            'clean confirm' => ['clean', 'fc', 'การ์ดนี้เป็นรุ่นเก่า ใช้การ์ดล่าสุดแทน', false, false],
            'flagged send' => ['support_free', 'fs', 'ข้อความนี้แก้เองและยังไม่ผ่านการตรวจ กดปุ่ม ยืนยันส่ง บนการ์ดถ้าจะส่ง', false, false],
            'flagged confirm' => ['support_free', 'fc', 'รับคำสั่งแล้ว กำลังส่งข้อความที่แก้ให้ลูกค้า', true, true],
        ];
    }

    #[DataProvider('taps')]
    public function test_real_tap_obeys_the_new_check_and_clean_send_pushes_owner_text(string $kind, string $code, string $answer, bool $approved, bool $overridden): void
    {
        $this->seedWorld(['check_overridden' => false, 'confirmed_revision' => null], ['check_overridden' => false]);
        Bus::fake();
        $this->fakeHttp($kind);
        $this->handle();
        $before = $this->rows();
        $this->requests = [];
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secret)->postJson('/api/webhook/telegram-alert/'.$this->token, [
            'update_id' => 999001,
            'callback_query' => ['id' => 'cb-b6g', 'from' => ['id' => 555], 'message' => ['message_id' => 77, 'chat' => ['id' => 888]], 'data' => $code.'|777|2|'.self::NONCE],
        ])->assertOk()->assertExactJson(['ok' => true]);
        $answers = array_values(array_filter($this->requests, fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery')));
        $this->assertCount(1, $answers);
        $this->assertSame($answer, $answers[0]['text']);
        $this->assertCount(2, $this->requests);
        if (! $approved) {
            $this->assertSame($before, $this->rows());
            Bus::assertNothingDispatched();

            return;
        }
        [$approval, $log] = $this->rows();
        $this->assertSame(array_replace($before[0], [
            'status' => 'approved', 'check_overridden' => $approval['check_overridden'],
            'confirmed_revision' => $code === 'fc' ? 2 : null, 'last_update_id' => 999001, 'updated_at' => self::NOW,
        ]), $approval);
        $this->assertSame($overridden, (bool) $approval['check_overridden']);
        $this->assertSame(array_replace($before[1], [
            'delivery_status' => 'approved', 'owner_action' => 'edited', 'owner_telegram_user_id' => '555',
            'owner_action_at' => self::NOW, 'check_overridden' => $log['check_overridden'], 'updated_at' => self::NOW,
        ]), $log);
        $this->assertSame($overridden, (bool) $log['check_overridden']);
        Bus::assertDispatched(SendCustomerFollowUp::class, fn ($job) => $job->approvalId === 777);
        Bus::assertDispatchedTimes(SendCustomerFollowUp::class, 1);
        Bus::assertDispatchedJobsCount(1);
        if ($kind === 'clean') {
            $this->requests = [];
            app()->call([new SendCustomerFollowUp(777), 'handle']);
            $pushes = array_values(array_filter($this->requests, fn ($r) => str_ends_with($r->url(), '/message/push')));
            $this->assertCount(1, $pushes);
            $this->assertSame(json_encode(['to' => self::CUSTOMER, 'messages' => [['type' => 'text', 'text' => self::TEXT]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $pushes[0]->body());
            $this->assertSame('sent', $this->rows()[0]['status']);
            $this->assertSame('sent', $this->rows()[1]['delivery_status']);
        }
    }

    public function test_sync_queue_edit_reply_checks_once_and_last_repaint_is_clean(): void
    {
        config(['queue.default' => 'sync']);
        $this->seedWorld(['revision' => 1, 'text' => self::DRAFT, 'check_answers' => self::OLD_CHECK]);
        $this->fakeHttp();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secret)->postJson('/api/webhook/telegram-alert/'.$this->token, [
            'update_id' => 999002,
            'message' => ['message_id' => 900, 'from' => ['id' => 555], 'chat' => ['id' => 888], 'text' => self::TEXT,
                'reply_to_message' => ['message_id' => 55, 'from' => ['is_bot' => true], 'text' => '✏️ แก้ข้อความ #123']],
        ])->assertOk()->assertExactJson(['ok' => true]);
        $decisions = array_values(array_filter($this->requests, fn ($r) => $r->url() === $this->url));
        $this->assertCount(1, $decisions);
        $this->assertSame($this->expectedJson(), $this->rows()[0]['check_answers']);
        $edits = array_values(array_filter($this->requests, fn ($r) => str_ends_with($r->url(), '/editMessageText')));
        $this->assertCount(2, $edits);
        $last = $edits[array_key_last($edits)];
        $this->assertStringContainsString('ตรวจข้อความ: ✅ ผ่าน', $last['text']);
        $nonce = $this->rows()[0]['nonce'];
        $this->requests = [$last];
        $this->assertPaint('✅ ผ่าน', true, nonce: $nonce);
        $this->assertInfo('clean');
    }

    public function test_check_does_not_reapply_bot_or_config_gates(): void
    {
        $this->seedWorld();
        DB::table('bots')->where('id', 26)->update(['status' => 'inactive']);
        DB::table('bot_settings')->where('bot_id', 26)->update(['follow_up_enabled' => false]);
        config(['customer_follow_up.bot_ids' => [], 'customer_follow_up.automatic_bot_ids' => []]);
        $before = $this->rows();
        $this->fakeHttp();
        $telegram = \Mockery::mock(FollowUpTelegramService::class);
        $telegram->shouldReceive('syncCard')->once()->with(777, \Mockery::type(CarbonImmutable::class))->andReturn('refreshed');
        $this->handle(telegram: $telegram);
        $this->assertWritten($before, $this->expectedJson(), 0.0128);
        $this->assertCount(1, $this->requests);
        $this->assertInfo('clean');
    }

    public function test_latest_locked_cost_is_added_and_locks_are_log_then_approval(): void
    {
        $this->seedWorld();
        $racer = null;
        $reads = [];
        DB::listen(function (QueryExecuted $query) use (&$reads): void {
            if (str_starts_with($query->sql, 'select') && DB::transactionLevel() > $this->baseTransactionLevel
                && preg_match('/from "(lead_recovery_logs|follow_up_approvals)"/', $query->sql, $match)) {
                $reads[] = [$match[1], $query->sql];
            }
        });
        $this->fakeHttp(during: function () use (&$racer): void {
            DB::table('lead_recovery_logs')->where('id', 123)->update(['model_cost' => 0.1]);
            $racer = $this->rows();
        });
        $this->handle();
        $this->assertSame(['lead_recovery_logs', 'follow_up_approvals'], array_column($reads, 0));
        if (DB::getDriverName() === 'pgsql') {
            foreach ($reads as [, $sql]) {
                $this->assertStringContainsString('for update', $sql);
            }
        }
        $this->assertWritten($racer, $this->expectedJson(), 0.1003);
    }

    public function test_transaction_write_exception_propagates_and_rolls_back_without_repaint(): void
    {
        $this->seedWorld();
        $before = $this->rows();
        $this->fakeHttp();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'update "lead_recovery_logs"')) {
                throw new RuntimeException(self::MARKER);
            }
        });
        $thrown = null;
        try {
            $this->handle();
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }
        $this->assertNotNull($thrown);
        $this->assertSame(self::MARKER, $thrown->getMessage());
        $this->assertSame($before, $this->rows());
        $this->assertCount(1, $this->requests);
    }

    public function test_queue_contract_serializes_only_its_two_integer_arguments(): void
    {
        $job = new CheckEditedFollowUp(777, 2);
        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(1, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertNull($job->queue);
        $this->assertSame(['approvalId' => 777, 'revision' => 2], $job->__serialize());
        $copy = unserialize(serialize($job));
        $this->assertSame(['approvalId' => 777, 'revision' => 2], $copy->__serialize());
    }
}
