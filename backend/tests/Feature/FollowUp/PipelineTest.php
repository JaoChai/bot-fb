<?php

namespace Tests\Feature\FollowUp;

use App\Jobs\GenerateCustomerFollowUp;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\LeadRecoveryLog;
use App\Models\User;
use App\Services\FollowUp\FollowUpDraftService;
use App\Services\FollowUp\FollowUpPipeline;
use App\Services\FollowUp\FollowUpPrompts;
use App\Services\FollowUp\FollowUpReservationService;
use App\Services\FollowUp\FollowUpSalesProjector;
use App\Services\OpenRouterService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด B3c — FollowUpPipeline::prepare() และ job GenerateCustomerFollowUp
 * (plan brief B3, D18 ฉบับแก้ + D31-D36 ของ Lead)
 *
 * สัญญา:
 * - แถว candidate คนเดียวขึ้นเป็น "ร่างรอเจ้าของอนุมัติ" หรือจบด้วยเหตุที่บันทึกไว้
 * - ขั้นตอนตายตัว: claim (UPDATE เดียว) → recheck → context → sort → reserve →
 *   draft → check+persist ใน transaction เดียว — ไม่ส่งอะไรออกนอกระบบเลย
 * - ทุกเวลาเขียนเป็นสตริงกรุงเทพฯ ผ่าน query builder; ทุก HTTP โดน fake;
 *   writer ผ่าน OpenRouterService ที่โดน mock; $now ส่งเข้ามาจากเทส
 * - log ล้มเหลวของ pipeline มีแค่ log_id + step + reason — ไม่มีบริบท/ร่าง
 */
class PipelineTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 10:00:00';

    private string $url;

    /** @var list<array{step: string, body: array}> */
    private array $decisionRequests = [];

    /** รุ่นของ stub ปัจจุบัน — stub เก่าใช้เทียบว่าตัวเองหมดอายุหรือยัง */
    private int $fakeGeneration = 0;

    /** จำนวน request Decisions สะสมทั้งเทส (ไม่รีเซ็ตต่อรุ่น stub) */
    private int $decisionRequestTotal = 0;

    /** @var list<array{message: string, context: mixed}> */
    private array $logCalls = [];

    /** @var list<array{messages: list<array{role: string, content: string}>}> */
    private array $writerCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->url = 'https://openrouter-decisions'.'.test/api/alpha/decisions';
        config([
            'services.openrouter.api_key' => 'test'.'-key-'.'b3c',
            'customer_follow_up.decisions_url' => $this->url,
        ]);
    }

    // ------------------------------------------------------------------
    // Happy path G3 / G2 — ทุกฟิลด์ของ step 7 เทียบค่าจริง
    // ------------------------------------------------------------------

    #[Test]
    public function test_happy_path_g3_reaches_pending_with_every_field(): void
    {
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'U3A');
        $logId = $this->makeLog($room);
        $this->fakeDecisions();
        $this->fakeWriter();

        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $context = ['C: สนใจสินค้าครับ', 'C: ประเมินราคาให้หน่อยครับ'];
        $draft = 'ทางร้านขออนุญาตถามหน่อยครับ ยังสนใจรายการเดิมอยู่ไหมครับ';

        $this->assertSame('pending', $row->delivery_status);
        $this->assertNull($row->reason_code);
        $this->assertSame('G3', $row->group_code);
        $this->assertSame(0.9, (float) $row->group_confidence);
        $this->assertSame(0.1, (float) $row->declined_score);
        $this->assertSame(FollowUpPrompts::SORT_VERSION, $row->decision_version);
        $this->assertSame(
            ['group' => ['choice' => 'G3', 'confidence' => 0.9], 'declined' => ['noul' => 0.1]],
            json_decode((string) $row->sort_answers, true)
        );
        $this->assertSame($context, json_decode((string) $row->context_redacted, true));
        $this->assertIsInt($row->sort_latency_ms);
        $this->assertGreaterThanOrEqual(0, $row->sort_latency_ms);
        $this->assertIsInt($row->draft_latency_ms);
        $this->assertGreaterThanOrEqual(0, $row->draft_latency_ms);
        $this->assertIsInt($row->check_latency_ms);
        $this->assertGreaterThanOrEqual(0, $row->check_latency_ms);

        $this->assertSame($draft, $row->draft);
        $this->assertSame('openai/gpt-6-luna-writer', $row->draft_model);
        $checkAnswers = json_decode((string) $row->check_answers, true);
        $this->assertSame(
            array_fill_keys(array_keys(FollowUpPrompts::checkQuestions()), 0.1),
            $checkAnswers['scores']
        );
        $this->assertSame([], $checkAnswers['failed']);
        $this->assertFalse($checkAnswers['error']);
        $this->assertSame(FollowUpPrompts::CHECK_VERSION, $checkAnswers['version']);
        $this->assertEqualsWithDelta(0.0006, (float) $row->model_cost, 0.0000001);
        $this->assertSame(
            [
                'sort' => ['input_tokens' => 100, 'output_tokens' => 20, 'cost' => 0.0001],
                'draft' => ['input_tokens' => 50, 'output_tokens' => 12, 'cost' => 0.0002],
                'check' => ['input_tokens' => 30, 'output_tokens' => 8, 'cost' => 0.0003],
            ],
            json_decode((string) $row->usage_totals, true)
        );

        $approvals = DB::table('follow_up_approvals')->where('lead_recovery_log_id', $logId)->get();
        $this->assertCount(1, $approvals);
        $approval = $approvals[0];
        $this->assertSame('pending', $approval->status);
        $this->assertSame(1, $approval->revision);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $approval->nonce);
        $this->assertSame($draft, $approval->text);
        $this->assertSame($checkAnswers, json_decode((string) $approval->check_answers, true));
        $this->assertFalse((bool) $approval->check_overridden);
        $this->assertSame('new', $approval->telegram_delivery_state);
        $this->assertNull($approval->telegram_plugin_id);
        $this->assertNull($approval->telegram_chat_id);
        $this->assertNull($approval->telegram_message_id);
        $this->assertNull($approval->editor_telegram_user_id);
        // expires_at = drafted_at + 12 ชม. — เทียบเป็นสตริงฐานข้อมูล
        $expectedExpiry = CarbonImmutable::parse((string) $row->drafted_at, 'Asia/Bangkok')
            ->addHours(12)->format('Y-m-d H:i:s');
        $this->assertSame($expectedExpiry, (string) $approval->expires_at);
        // D38: เวลาสร้าง/แก้ของแถวอนุมัติ = สตริงกรุงเทพฯ ของรอบนี้เอง
        $this->assertSame('2026-11-16 10:00:00', (string) $approval->created_at);
        $this->assertSame('2026-11-16 10:00:00', (string) $approval->updated_at);

        $this->assertSame(2, count($this->decisionRequests));
        $this->assertSame(1, $this->usedSlots($bot->id, '2026-11-16'));

        // ทั้งสอง request Decisions ต้องพาบรรทัดบริบทตามที่เก็บพอดี
        foreach ($this->decisionRequests as $entry) {
            $this->assertSame($context, $entry['body']['state']['conversation']);
        }
        $checkRequest = $this->decisionRequests[1];
        $this->assertSame('check', $checkRequest['step']);
        $this->assertSame($draft, $checkRequest['body']['state']['draft']);
        $this->assertSame('G3', $checkRequest['body']['state']['group']);
        $sortRequest = $this->decisionRequests[0];
        $this->assertSame('sort', $sortRequest['step']);
        $this->assertSame(
            ['bought_from_this_shop_before' => false, 'transfer_summary_was_sent' => false],
            $sortRequest['body']['state']['known_facts']
        );

        // ตัวร่างได้บริบทชุดเดียวกับที่เก็บ — conversation ใน user message ต้องตรง context_redacted พอดี
        $this->assertCount(1, $this->writerCalls);
        $writerMessages = $this->writerCalls[0]['messages'];
        $this->assertCount(2, $writerMessages);
        $this->assertSame('user', $writerMessages[1]['role']);
        $writerUser = json_decode((string) $writerMessages[1]['content'], true);
        $this->assertSame('G3', $writerUser['group']);
        $this->assertSame($context, $writerUser['conversation']);
    }

    #[Test]
    public function test_happy_path_g2_reaches_pending(): void
    {
        $bot = $this->seedBot();
        $room = $this->makePaymentRoom($bot, 'U2A', $this->at('2026-11-16 11:00:00'));
        $logId = $this->makeLog($room, $this->at('2026-11-16 11:00:00'));
        // ห้องส่งสรุปยอดไปก่อนกรอบ projection — คอลัมน์ของ log ต้องเป็น true เอง
        DB::table('lead_recovery_logs')->where('id', $logId)->update(['transfer_summary_sent' => true]);
        // payment room 29 ชม. เงียบ → ผ่าน recheck; G2 ไม่เข้ากฎหน่วงการชำระ
        $this->fakeDecisions(sort: ['group' => ['choice' => 'G2', 'confidence' => 0.95], 'declined' => ['noul' => 0.05]]);
        $this->fakeWriter();

        $this->runPrepare($logId, $this->at('2026-11-16 11:00:00'));

        $row = $this->logRow($logId);
        $this->assertSame('pending', $row->delivery_status);
        $this->assertSame('G2', $row->group_code);
        $this->assertSame(1, $this->usedSlots($bot->id, '2026-11-16'));
        $sortRequest = $this->decisionRequests[0];
        $this->assertTrue($sortRequest['body']['state']['known_facts']['transfer_summary_was_sent']);
    }

    // ------------------------------------------------------------------
    // ไทม์มิ่งฝั่งชำระเงิน (plan line 151)
    // ------------------------------------------------------------------

    #[Test]
    public function test_payment_step_g3_before_sale_window_defers(): void
    {
        $bot = $this->seedBot();
        $room = $this->makePaymentRoom($bot, 'U3P', $this->at('2026-11-16 11:00:00'));
        $logId = $this->makeLog($room, $this->at('2026-11-16 11:00:00'));
        $this->fakeDecisions();
        $this->fakeWriter(null);

        $this->runPrepare($logId, $this->at('2026-11-16 11:00:00'));

        $row = $this->logRow($logId);
        $this->assertSame('deferred', $row->delivery_status);
        $this->assertSame('wait_for_sale_window', $row->reason_code);
        $this->assertSame('2026-11-17 06:00:00', (string) $row->next_attempt_at);
        $this->assertSame('G3', $row->group_code);
        $this->assertNotNull($row->sort_answers);
        $this->assertNull($row->draft);
        $this->assertSame(0, $this->usedSlots($bot->id, '2026-11-16'));
        $this->assertSame(0, DB::table('follow_up_approvals')->count());
        $this->assertSame(1, count($this->decisionRequests));
    }

    #[Test]
    public function test_payment_step_g3_rerun_at_sale_window_reaches_pending_once(): void
    {
        $bot = $this->seedBot();
        $room = $this->makePaymentRoom($bot, 'U3R', $this->at('2026-11-16 11:00:00'));
        $logId = $this->makeLog($room, $this->at('2026-11-16 11:00:00'));
        $this->fakeDecisions();
        $this->fakeWriter(null);

        $this->runPrepare($logId, $this->at('2026-11-16 11:00:00'));
        $this->assertSame('deferred', $this->logRow($logId)->delivery_status);

        // รอบสอง ณ เงียบครบ 24 ชม. แล้ว (08:30 > next_attempt_at 06:00, อยู่ในเวลาส่ง)
        $this->fakeDecisions();
        $this->fakeWriter();
        $this->runPrepare($logId, $this->at('2026-11-17 08:30:00'));

        $row = $this->logRow($logId);
        $this->assertSame('pending', $row->delivery_status);
        $this->assertSame(1, DB::table('follow_up_approvals')->where('lead_recovery_log_id', $logId)->count());
        $this->assertSame(1, $this->usedSlots($bot->id, '2026-11-17'));
        $this->assertSame(0, $this->usedSlots($bot->id, '2026-11-16'));
    }

    #[Test]
    public function test_payment_step_g2_still_pends_before_sale_window(): void
    {
        $bot = $this->seedBot();
        $room = $this->makePaymentRoom($bot, 'U2P', $this->at('2026-11-16 11:00:00'));
        $logId = $this->makeLog($room, $this->at('2026-11-16 11:00:00'));
        $this->fakeDecisions(sort: ['group' => ['choice' => 'G2', 'confidence' => 0.95], 'declined' => ['noul' => 0.05]]);
        $this->fakeWriter();

        $this->runPrepare($logId, $this->at('2026-11-16 11:00:00'));

        $this->assertSame('pending', $this->logRow($logId)->delivery_status);
    }

    #[Test]
    public function test_payment_step_false_g3_after_sale_window_pends(): void
    {
        $bot = $this->seedBot();
        $room = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => 'U3S',
        ]);
        $this->addMessage($room->id, 'user', '2026-11-15 10:00:00', 'สนใจสินค้าครับ');
        $this->addMessage($room->id, 'user', '2026-11-15 10:05:00', 'ประเมินราคาให้หน่อยครับ');
        FollowUpSalesProjector::refresh($room, $this->at('2026-11-16 11:00:00'));
        $logId = $this->makeLog($room, $this->at('2026-11-16 11:00:00'));
        $this->fakeDecisions();
        $this->fakeWriter();

        $this->runPrepare($logId, $this->at('2026-11-16 11:00:00'));

        $row = $this->logRow($logId);
        $this->assertSame('pending', $row->delivery_status);
        $this->assertSame('G3', $row->group_code);
    }

    // ------------------------------------------------------------------
    // การเลือกบริบท (D31) — ผ่าน contextLines ของ pipeline เดียวกับที่ prepare ใช้
    // ------------------------------------------------------------------

    #[Test]
    public function test_context_takes_newest_ten_oldest_first_and_respects_bounds(): void
    {
        $bot = $this->seedBot();
        $service = app(FollowUpReservationService::class);

        // 14 ข้อความ → 10 ใหม่สุดเรียงเก่าก่อน
        $room = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'U14A']);
        foreach (range(1, 14) as $i) {
            $this->addMessage($room->id, $i % 2 === 1 ? 'user' : 'bot', '2026-11-15 08:'.str_pad((string) (30 + $i), 2, '0', STR_PAD_LEFT).':00', 'ข้อความ '.$i);
        }
        FollowUpSalesProjector::refresh($room, $this->now());
        $log = $service->candidate($room, $this->now());

        $lines = app(FollowUpPipeline::class)->contextLines($log);

        $this->assertCount(10, $lines);
        $this->assertSame('C: ข้อความ 5', $lines[0]);
        $this->assertSame('B: ข้อความ 14', $lines[9]);
        foreach ($lines as $index => $line) {
            $this->assertSame('ข้อความ '.($index + 5), mb_substr((string) $line, 3));
        }

        // ข้อความเก่ากว่า sales_started_at ไม่อยู่ในบริบท
        $roomOld = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'U14B']);
        $this->addMessage($roomOld->id, 'user', '2026-11-15 08:55:00', 'สนใจสินค้าครับ');
        $this->addMessage($roomOld->id, 'user', '2026-11-15 08:59:00', 'ประเมินราคาให้หน่อยครับ');
        $this->addMessage($roomOld->id, 'bot', '2026-10-01 09:00:00', 'ยืนยันรายการหน้าร้านได้เลยครับ');
        $this->addMessage($roomOld->id, 'bot', '2026-11-15 09:00:00', 'รบกวนรอประเมินสักครู่ครับ');
        FollowUpSalesProjector::refresh($roomOld, $this->now());
        $logOld = $service->candidate($roomOld, $this->now());

        $linesOld = app(FollowUpPipeline::class)->contextLines($logOld);
        $this->assertSame(['C: สนใจสินค้าครับ', 'C: ประเมินราคาให้หน่อยครับ', 'B: รบกวนรอประเมินสักครู่ครับ'], $linesOld);

        // ข้อความที่ id เกิน observed_last_message_id ไม่อยู่ในบริบท
        $this->addMessage($roomOld->id, 'agent', '2026-11-15 09:30:00', 'เจ้าหน้าที่ดูแลให้เองครับ');
        $linesAfterLate = app(FollowUpPipeline::class)->contextLines($logOld);
        $this->assertSame($linesOld, $linesAfterLate);

        // ข้อความ non-text ปรากฏเป็นบรรทัด [non-text:<type>]
        $roomNonText = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'U14C']);
        $this->addMessage($roomNonText->id, 'user', '2026-11-15 08:55:00', 'สนใจสินค้าครับ');
        $this->addMessage($roomNonText->id, 'user', '2026-11-15 08:59:00', 'ประเมินราคาให้หน่อยครับ');
        DB::table('messages')->insert([
            'conversation_id' => $roomNonText->id,
            'sender' => 'user',
            'content' => '',
            'type' => 'image',
            'created_at' => '2026-11-15 09:00:00',
            'updated_at' => '2026-11-15 09:00:00',
        ]);
        FollowUpSalesProjector::refresh($roomNonText, $this->now());
        $logNonText = $service->candidate($roomNonText, $this->now());

        $linesNonText = app(FollowUpPipeline::class)->contextLines($logNonText);
        $this->assertSame(
            ['C: สนใจสินค้าครับ', 'C: ประเมินราคาให้หน่อยครับ', 'C: [non-text:image]'],
            $linesNonText
        );
    }

    #[Test]
    public function test_account_data_in_context_excludes_before_any_model_call(): void
    {
        $bot = $this->seedBot();
        $room = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'USEN']);
        $accountLine = 'รหัสลูกค้า foo'.'@'.'mail.com รหัส secret'.'value2026';
        // ข้อความนี้อยู่ในกรอบของ log (ตอน candidate) แต่หลุดกรอบ projection ล่าสุด
        // (refresh รอบ recheck ขยายขอบเขต 30 วันใหม่) — pipeline ต้องเจอเองก่อน model
        $this->addMessage($room->id, 'user', '2026-10-17 10:30:00', $accountLine);
        $this->addMessage($room->id, 'user', '2026-11-15 08:55:00', 'สนใจสินค้าครับ');
        $this->addMessage($room->id, 'user', '2026-11-15 08:59:00', 'ประเมินราคาให้หน่อยครับ');
        FollowUpSalesProjector::refresh($room, $this->now());
        $logId = $this->makeLog($room);
        $this->fakeDecisions();
        $this->fakeWriter(null);

        // 10:31 ของวันถัดไป: ขอบ projection ใหม่ = 2026-10-17 10:31 — ข้อความรหัสลับ (10:30) หลุดกรอบ signal
        $this->runPrepare($logId, $this->at('2026-11-17 10:31:00'));

        $row = $this->logRow($logId);
        $this->assertSame('excluded', $row->delivery_status);
        $this->assertSame('sensitive_account_data', $row->reason_code);
        $this->assertNull($row->group_code);
        $this->assertNull($row->context_redacted);
        Http::assertNothingSent();
        $this->assertSame(0, $this->usedSlots($bot->id, '2026-11-17'));
    }

    #[Test]
    public function test_empty_context_lines_return_empty_list(): void
    {
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'UEMP');

        // แถวเก่าที่ไม่มีขอบเขตการขาย (sales_started_at null) — ห้ามยิง query ไม่มีขอบ
        $silenceId = DB::table('messages')->where('conversation_id', $room->id)
            ->where('sender', 'user')->orderByDesc('id')->value('id');
        $observedId = DB::table('messages')->where('conversation_id', $room->id)
            ->orderByDesc('id')->value('id');
        $logId = DB::table('lead_recovery_logs')->insertGetId([
            'conversation_id' => $room->id,
            'bot_id' => $bot->id,
            'attempt_number' => 0,
            'message_mode' => 'ai',
            'delivery_status' => 'candidate',
            'silence_user_message_id' => $silenceId,
            'observed_last_message_id' => $observedId,
            'sales_started_at' => null,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        $lines = app(FollowUpPipeline::class)->contextLines(LeadRecoveryLog::query()->findOrFail($logId));

        $this->assertSame([], $lines);
    }

    // ------------------------------------------------------------------
    // การยืมงาน (D32) — claimable / non-claimable
    // ------------------------------------------------------------------

    #[Test]
    public function test_every_claimable_state_reaches_pending(): void
    {
        $cases = [
            'candidate_plain' => [],
            'sort_retry_due' => ['delivery_status' => 'sort_retry', 'next_attempt_at' => self::NOW, 'attempt_count' => 1, 'reason_code' => 'sort_failed'],
            'deferred_due' => ['delivery_status' => 'deferred', 'next_attempt_at' => self::NOW, 'reason_code' => 'wait_for_sale_window'],
            'sorting_stale' => ['delivery_status' => 'sorting', 'updated_at' => '2026-11-16 09:49:00'],
            'drafting_stale_boundary' => ['delivery_status' => 'drafting', 'updated_at' => '2026-11-16 09:50:00'],
        ];

        foreach ($cases as $name => $overrides) {
            $bot = $this->seedBot();
            $room = $this->makeRoom($bot, 'UC'.strtoupper((string) mb_substr(md5($name), 0, 6)));
            $logId = $this->makeLog($room);
            if ($name === 'drafting_stale_boundary') {
                // แถวนี้ถือ slot ของวันนี้อยู่แล้ว (worker ตายระหว่าง drafting)
                app(FollowUpReservationService::class)->reserveDraft(LeadRecoveryLog::query()->findOrFail($logId), $this->now());
            }
            if ($overrides !== []) {
                DB::table('lead_recovery_logs')->where('id', $logId)->update($overrides);
            }

            $this->fakeDecisions();
            $this->fakeWriter();
            $this->runPrepare($logId);

            $row = $this->logRow($logId);
            $this->assertSame('pending', $row->delivery_status, "state $name must be claimable and reach pending");
            $this->assertSame(1, DB::table('follow_up_approvals')->where('lead_recovery_log_id', $logId)->count(), "state $name must produce exactly one approval");
            $this->assertSame(1, $this->usedSlots($bot->id, '2026-11-16'), "state $name must count exactly one slot");
        }
    }

    #[Test]
    public function test_non_claimable_states_return_without_any_write_or_request(): void
    {
        $cases = [
            'sort_retry_not_due' => ['delivery_status' => 'sort_retry', 'next_attempt_at' => '2026-11-16 10:30:00', 'attempt_count' => 1, 'reason_code' => 'sort_failed'],
            'deferred_not_due' => ['delivery_status' => 'deferred', 'next_attempt_at' => '2026-11-17 06:00:00', 'reason_code' => 'wait_for_sale_window'],
            'sorting_fresh' => ['delivery_status' => 'sorting', 'updated_at' => '2026-11-16 09:50:01'],
            'drafting_fresh' => ['delivery_status' => 'drafting', 'updated_at' => '2026-11-16 09:50:01'],
            'pending' => ['delivery_status' => 'pending'],
            'excluded' => ['delivery_status' => 'excluded', 'reason_code' => 'group_g1'],
            'cancelled' => ['delivery_status' => 'cancelled', 'reason_code' => 'order_created'],
            'sent' => ['delivery_status' => 'sent'],
        ];

        foreach ($cases as $name => $overrides) {
            $bot = $this->seedBot();
            $room = $this->makeRoom($bot, 'UX'.strtoupper((string) mb_substr(md5($name), 0, 6)));
            $logId = $this->makeLog($room);
            DB::table('lead_recovery_logs')->where('id', $logId)->update($overrides);
            $before = $this->logRow($logId);

            $this->fakeDecisions();
            $this->fakeWriter(null);
            $this->runPrepare($logId);

            $this->assertRowUnchanged($before, $logId, "state $name must stay byte-identical");
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function test_unknown_log_id_returns_silently(): void
    {
        $this->seedBot();
        $this->fakeDecisions();
        $this->fakeWriter(null);

        $this->runPrepare(999999);

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('follow_up_approvals')->count());
        $this->assertSame(0, DB::table('lead_recovery_logs')->count());
    }

    #[Test]
    public function test_two_calls_for_same_candidate_produce_one_draft(): void
    {
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'UTW');
        $logId = $this->makeLog($room);
        $this->fakeDecisions();
        $this->fakeWriter();

        $this->runPrepare($logId);
        $this->assertSame('pending', $this->logRow($logId)->delivery_status);

        // ครั้งที่สอง: แถว pending ยืมไม่ได้ — ไม่มี request ไม่มีร่างใหม่
        $this->decisionRequests = [];
        $this->fakeDecisions();
        $this->fakeWriter(null);
        $this->runPrepare($logId);

        Http::assertNothingSent();
        $this->assertSame(1, DB::table('follow_up_approvals')->where('lead_recovery_log_id', $logId)->count());
        $this->assertSame(1, $this->usedSlots($bot->id, '2026-11-16'));
    }

    // ------------------------------------------------------------------
    // Recheck (D34)
    // ------------------------------------------------------------------

    #[Test]
    public function test_recheck_cancel_reasons_cancel_with_that_reason(): void
    {
        $cases = [
            'new_customer_message' => 'customer',
            'new_bot_message' => 'bot',
            'order_created' => 'order',
            'passed_slip' => 'slip',
        ];

        foreach ($cases as $reason => $fixture) {
            $bot = $this->seedBot();
            $room = $this->makeRoom($bot, 'UR'.strtoupper((string) mb_substr(md5($reason), 0, 6)));
            $logId = $this->makeLog($room);

            match ($fixture) {
                'customer' => $this->addMessage($room->id, 'user', '2026-11-15 09:30:00', 'กลับมาคุยต่อครับ'),
                'bot' => $this->addMessage($room->id, 'bot', '2026-11-15 09:30:00', 'ตอบกลับลูกค้าครับ'),
                'order' => DB::table('orders')->insert([
                    'bot_id' => $bot->id,
                    'conversation_id' => $room->id,
                    'total_amount' => 500,
                    'status' => 'completed',
                    'created_at' => '2026-11-15 09:00:00',
                    'updated_at' => '2026-11-15 09:00:00',
                ]),
                'slip' => DB::table('slip_verifications')->insert([
                    'bot_id' => $bot->id,
                    'conversation_id' => $room->id,
                    'trans_ref' => 'ref'.'-'.md5($reason),
                    'amount' => 500,
                    'status' => 'passed',
                    'created_at' => '2026-11-15 09:00:00',
                    'updated_at' => '2026-11-15 09:00:00',
                ]),
            };

            $this->fakeDecisions();
            $this->fakeWriter(null);
            $this->runPrepare($logId);

            $row = $this->logRow($logId);
            $this->assertSame('cancelled', $row->delivery_status, "reason $reason must cancel");
            $this->assertSame($reason, $row->reason_code);
            $this->assertSame(0, (int) $row->attempt_count);
            $this->assertNull($row->next_attempt_at);
            Http::assertNothingSent();
            $this->assertSame(0, DB::table('follow_up_approvals')->count());
        }
    }

    #[Test]
    public function test_recheck_transient_reason_returns_claimed_from_status(): void
    {
        // outside_hours: claimed จาก candidate → กลับ candidate
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'UOH');
        $logId = $this->makeLog($room);
        $this->fakeDecisions();
        $this->fakeWriter(null);
        $this->runPrepare($logId, $this->at('2026-11-16 23:00:00'));

        $row = $this->logRow($logId);
        $this->assertSame('candidate', $row->delivery_status);
        $this->assertSame('outside_hours', $row->reason_code);
        $this->assertSame(0, (int) $row->attempt_count);
        $this->assertNull($row->next_attempt_at);
        Http::assertNothingSent();

        // handover_switched_on: claimed จาก candidate → กลับ candidate
        $bot2 = $this->seedBot();
        $room2 = $this->makeRoom($bot2, 'UHD');
        $logId2 = $this->makeLog($room2);
        DB::table('conversations')->where('id', $room2->id)->update(['is_handover' => true]);
        $this->fakeDecisions();
        $this->fakeWriter(null);
        $this->runPrepare($logId2);

        $row2 = $this->logRow($logId2);
        $this->assertSame('candidate', $row2->delivery_status);
        $this->assertSame('handover_switched_on', $row2->reason_code);
        Http::assertNothingSent();

        // claimed จาก sorting (แถว stale) เหตุชั่วคราว → กลับ candidate
        $bot3 = $this->seedBot();
        $room3 = $this->makeRoom($bot3, 'USS');
        $logId3 = $this->makeLog($room3);
        DB::table('lead_recovery_logs')->where('id', $logId3)->update([
            'delivery_status' => 'sorting',
            'updated_at' => '2026-11-16 09:45:00',
        ]);
        $this->fakeDecisions();
        $this->fakeWriter(null);
        $this->runPrepare($logId3, $this->at('2026-11-16 23:00:00'));

        $row3 = $this->logRow($logId3);
        $this->assertSame('candidate', $row3->delivery_status);
        $this->assertSame('outside_hours', $row3->reason_code);
        $this->assertSame(0, (int) $row3->attempt_count);
    }

    // ------------------------------------------------------------------
    // ผล sort (D33 — กลุ่มมาก่อน declined, คะแนนเก็บทุกกรณี)
    // ------------------------------------------------------------------

    public static function sortOutcomeProvider(): array
    {
        return [
            'G1 excludes with group_g1' => [['group' => ['choice' => 'G1', 'confidence' => 0.9], 'declined' => ['noul' => 0.1]], 'group_g1'],
            'G4 excludes with group_g4' => [['group' => ['choice' => 'G4', 'confidence' => 0.9], 'declined' => ['noul' => 0.1]], 'group_g4'],
            'G3 with declined 0.5 excludes with declined' => [['group' => ['choice' => 'G3', 'confidence' => 0.9], 'declined' => ['noul' => 0.5]], 'declined'],
            'G1 with declined 0.9 still excludes with group_g1' => [['group' => ['choice' => 'G1', 'confidence' => 0.9], 'declined' => ['noul' => 0.9]], 'group_g1'],
        ];
    }

    #[Test]
    #[DataProvider('sortOutcomeProvider')]
    public function test_sort_rules_store_scores_and_exclude_without_writer(array $sortAnswer, string $reason): void
    {
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'US'.strtoupper((string) mb_substr(md5($reason), 0, 6)));
        $logId = $this->makeLog($room);
        $this->fakeDecisions(sort: $sortAnswer);
        $this->fakeWriter(null);

        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $this->assertSame('excluded', $row->delivery_status);
        $this->assertSame($reason, $row->reason_code);
        $choice = $sortAnswer['group']['choice'];
        $this->assertSame($choice, $row->group_code);
        $this->assertSame((float) $sortAnswer['group']['confidence'], (float) $row->group_confidence);
        $this->assertSame((float) $sortAnswer['declined']['noul'], (float) $row->declined_score);
        $this->assertSame(FollowUpPrompts::SORT_VERSION, $row->decision_version);
        $this->assertSame($sortAnswer, json_decode((string) $row->sort_answers, true));
        $this->assertNull($row->draft);
        $this->assertNull($row->next_attempt_at);
        $this->assertSame(0, $row->attempt_count);
        $this->assertSame(0, $this->usedSlots($bot->id, '2026-11-16'));
        $this->assertSame(0, DB::table('follow_up_approvals')->count());
        $this->assertSame(1, count($this->decisionRequests));
    }

    #[Test]
    public function test_declined_just_below_threshold_continues_to_pending(): void
    {
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'US49');
        $logId = $this->makeLog($room);
        $this->fakeDecisions(sort: ['group' => ['choice' => 'G3', 'confidence' => 0.9], 'declined' => ['noul' => 0.4999]]);
        $this->fakeWriter();

        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $this->assertSame('pending', $row->delivery_status);
        $this->assertSame(0.4999, (float) $row->declined_score);
        $this->assertNull($row->reason_code);
    }

    // ------------------------------------------------------------------
    // กฎความล้มเหลว (D33) — sort ล้ม 3 รอบ
    // ------------------------------------------------------------------

    #[Test]
    public function test_sort_failure_three_runs_then_excluded(): void
    {
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'UF1');
        $logId = $this->makeLog($room);
        $this->fakeDecisions(sortStatus: 500);
        $this->fakeWriter(null);

        $this->runPrepare($logId, $this->now());
        $row = $this->logRow($logId);
        $this->assertSame('sort_retry', $row->delivery_status);
        $this->assertSame('sort_failed', $row->reason_code);
        $this->assertSame(1, $row->attempt_count);
        $this->assertSame('2026-11-16 10:30:00', (string) $row->next_attempt_at);
        $this->assertNull($row->group_code);

        $this->fakeDecisions(sortStatus: 500);
        $this->runPrepare($logId, $this->at('2026-11-16 10:30:00'));
        $row = $this->logRow($logId);
        $this->assertSame('sort_retry', $row->delivery_status);
        $this->assertSame(2, $row->attempt_count);
        $this->assertSame('2026-11-16 11:00:00', (string) $row->next_attempt_at);
        $this->assertNull($row->group_code);

        $this->fakeDecisions(sortStatus: 500);
        $this->runPrepare($logId, $this->at('2026-11-16 11:00:00'));
        $row = $this->logRow($logId);
        $this->assertSame('excluded', $row->delivery_status);
        $this->assertSame('sort_failed', $row->reason_code);
        $this->assertSame(3, $row->attempt_count);
        $this->assertNull($row->group_code);
        $this->assertNull($row->draft);

        $this->assertSame(3, $this->decisionRequestTotal);
        $this->assertSame(0, $this->usedSlots($bot->id, '2026-11-16'));
    }

    #[Test]
    public function test_draft_failure_releases_slot_then_later_run_pends_once(): void
    {
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'UDF');
        $logId = $this->makeLog($room);
        // รอบแรก: sort ได้ (มี cost) แต่ writer คืนของใช้ไม่ได้
        $this->fakeDecisions();
        $this->fakeWriter(text: '   ');
        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $this->assertSame('sort_retry', $row->delivery_status);
        $this->assertSame('draft_failed', $row->reason_code);
        $this->assertSame(1, $row->attempt_count);
        $this->assertSame('2026-11-16 10:30:00', (string) $row->next_attempt_at);
        $this->assertNull($row->draft);
        $this->assertNull($row->drafted_at);
        $this->assertNull($row->draft_day);
        $this->assertNull($row->draft_reserved_at);
        $this->assertSame(0, DB::table('follow_up_approvals')->count());
        $this->assertSame(1, count($this->decisionRequests));
        $this->assertSame(0, $this->usedSlots($bot->id, '2026-11-16'));

        // รอบสองสำเร็จ — slot ถูกนับครั้งเดียว
        $this->fakeDecisions();
        $this->fakeWriter();
        $this->runPrepare($logId, $this->at('2026-11-16 10:30:00'));

        $row = $this->logRow($logId);
        $this->assertSame('pending', $row->delivery_status);
        $this->assertNull($row->reason_code);
        $this->assertSame(1, DB::table('follow_up_approvals')->where('lead_recovery_log_id', $logId)->count());
        $this->assertSame(1, $this->usedSlots($bot->id, '2026-11-16'));
        // model_cost รวมค่าเดิมของรอบแรก + รอบสอง = 0.0001 + (0.0001+0.0002+0.0003)
        $this->assertEqualsWithDelta(0.0007, (float) $row->model_cost, 0.0000001);
    }

    #[Test]
    public function test_sort_failure_after_draft_failure_keeps_earlier_cost_and_usage(): void
    {
        // model_cost สะสมทุกเส้นทาง; usage_totals คงของรอบล่าสุดที่มีขั้นรายงาน —
        // รอบ sort ล้ม (ไม่มีขั้นไหนรายงาน usage) ต้องไม่ล้างทั้งสองค่า
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'UCSF');
        $logId = $this->makeLog($room);
        // รอบแรก: sort ผ่าน (cost 0.0001 + usage) แต่ร่างล้ม
        $this->fakeDecisions();
        $this->fakeWriter(text: '   ');
        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $this->assertSame('sort_retry', $row->delivery_status);
        $this->assertSame('draft_failed', $row->reason_code);
        $this->assertEqualsWithDelta(0.0001, (float) $row->model_cost, 0.0000001);
        $this->assertSame(
            ['sort' => ['input_tokens' => 100, 'output_tokens' => 20, 'cost' => 0.0001]],
            json_decode((string) $row->usage_totals, true)
        );

        // รอบสอง: sort ล้ม 500 — cost คงสะสม, usage_totals ไม่ถูกล้าง
        $this->fakeDecisions(sortStatus: 500);
        $this->runPrepare($logId, $this->at('2026-11-16 10:30:00'));

        $row = $this->logRow($logId);
        $this->assertSame('sort_retry', $row->delivery_status);
        $this->assertSame('sort_failed', $row->reason_code);
        $this->assertSame(2, $row->attempt_count);
        $this->assertEqualsWithDelta(0.0001, (float) $row->model_cost, 0.0000001);
        $this->assertSame(
            ['sort' => ['input_tokens' => 100, 'output_tokens' => 20, 'cost' => 0.0001]],
            json_decode((string) $row->usage_totals, true)
        );
    }

    // ------------------------------------------------------------------
    // งบร่างรายวัน (D36)
    // ------------------------------------------------------------------

    #[Test]
    public function test_cap_full_before_prepare_stops_at_recheck(): void
    {
        $bot = $this->seedBot();
        $service = app(FollowUpReservationService::class);
        foreach (range(1, 10) as $i) {
            $fillerRoom = $this->makeRoom($bot, 'UCa'.$i);
            $fillerLog = $service->candidate($fillerRoom, $this->now());
            $this->assertTrue($service->reserveDraft($fillerLog, $this->now()), "filler $i must reserve");
        }
        $room = $this->makeRoom($bot, 'UCaM');
        $logId = $this->makeLog($room);
        $this->fakeDecisions();
        $this->fakeWriter(null);

        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $this->assertSame('candidate', $row->delivery_status);
        $this->assertSame('condition_not_met', $row->reason_code);
        Http::assertNothingSent();
    }

    #[Test]
    public function test_cap_filling_between_recheck_and_reserve_defers_to_next_day(): void
    {
        $bot = $this->seedBot();
        $service = app(FollowUpReservationService::class);
        foreach (range(1, 9) as $i) {
            $fillerRoom = $this->makeRoom($bot, 'UCb'.$i);
            $fillerLog = $service->candidate($fillerRoom, $this->now());
            $this->assertTrue($service->reserveDraft($fillerLog, $this->now()), "filler $i must reserve");
        }
        $room = $this->makeRoom($bot, 'UCbM');
        $logId = $this->makeLog($room);

        // ระหว่าง recheck กับ reserve — callback ของ sort response เติม slot ที่ 10
        $this->fakeDecisions(onSort: function () use ($bot, $service): void {
            $lateRoom = $this->makeRoom($bot, 'UCbX');
            $lateLog = $service->candidate($lateRoom, $this->now());
            $this->assertTrue($service->reserveDraft($lateLog, $this->now()), 'the tenth reservation inside the callback must succeed');
        });
        $this->fakeWriter(null);

        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $this->assertSame('deferred', $row->delivery_status);
        $this->assertSame('daily_cap', $row->reason_code);
        $this->assertSame('2026-11-17 08:00:00', (string) $row->next_attempt_at);
        $this->assertSame('G3', $row->group_code);
        $this->assertNotNull($row->sort_answers);
        $this->assertNull($row->draft);
        $this->assertSame(10, $this->usedSlots($bot->id, '2026-11-16'));
        $this->assertSame(1, count($this->decisionRequests));
    }

    // ------------------------------------------------------------------
    // Check error / warned draft — ยังเป็น pending ให้เจ้าของตัดสิน
    // ------------------------------------------------------------------

    #[Test]
    public function test_check_error_still_becomes_pending(): void
    {
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'UCE');
        $logId = $this->makeLog($room);
        $this->fakeDecisions(checkStatus: 500);
        $this->fakeWriter();

        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $this->assertSame('pending', $row->delivery_status);
        $checkAnswers = json_decode((string) $row->check_answers, true);
        $this->assertTrue($checkAnswers['error']);
        $this->assertSame(['check_error'], $checkAnswers['failed']);
        $this->assertSame(FollowUpPrompts::CHECK_VERSION, $checkAnswers['version']);
        $this->assertSame(
            array_fill_keys(array_keys(FollowUpPrompts::checkQuestions()), null),
            $checkAnswers['scores']
        );
        $this->assertSame(1, DB::table('follow_up_approvals')->where('lead_recovery_log_id', $logId)->count());
        $this->assertSame(1, $this->usedSlots($bot->id, '2026-11-16'));
        $this->assertSame(2, count($this->decisionRequests));
    }

    #[Test]
    public function test_warned_draft_still_becomes_pending_with_failed_key(): void
    {
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'UWD');
        $logId = $this->makeLog($room);
        $check = array_fill_keys(array_keys(FollowUpPrompts::checkQuestions()), ['noul' => 0.1]);
        $check['promise'] = ['noul' => 0.9];
        $this->fakeDecisions(check: $check);
        $this->fakeWriter();

        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $this->assertSame('pending', $row->delivery_status);
        $checkAnswers = json_decode((string) $row->check_answers, true);
        $this->assertFalse($checkAnswers['error']);
        $this->assertSame(['promise'], $checkAnswers['failed']);
        $this->assertSame(1, DB::table('follow_up_approvals')->where('lead_recovery_log_id', $logId)->count());
        $this->assertSame(1, $this->usedSlots($bot->id, '2026-11-16'));
    }

    // ------------------------------------------------------------------
    // Claim lost + stale drafting ที่ถือ slot
    // ------------------------------------------------------------------

    #[Test]
    public function test_claim_lost_writes_nothing_and_warns_once(): void
    {
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'UCL');
        $logId = $this->makeLog($room);
        $this->fakeDecisions();
        // callback ของ writer เปลี่ยน claim_token ระหว่าง draft กับ persist
        $this->fakeWriter(onCall: function () use ($logId): void {
            DB::table('lead_recovery_logs')->where('id', $logId)->update([
                'claim_token' => '00000000-0000-0000-0000-000000000000',
            ]);
        });
        $this->startLogCapture();

        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $this->assertSame('drafting', $row->delivery_status);
        $this->assertNull($row->draft);
        $this->assertSame(0, DB::table('follow_up_approvals')->count());
        $warnings = array_values(array_filter(
            $this->logCalls,
            fn (array $call): bool => str_starts_with($call['message'], 'Follow-up pipeline')
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame(['log_id', 'step', 'reason'], array_keys($warnings[0]['context']));
        $this->assertSame('claim_lost', $warnings[0]['context']['reason']);
        // slot ยังถืออยู่ (รอ stale-claim rule เก็บภายหลัง)
        $this->assertSame(1, $this->usedSlots($bot->id, '2026-11-16'));
    }

    #[Test]
    public function test_stale_drafting_row_holding_slot_reaches_pending_once(): void
    {
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'USD');
        $logId = $this->makeLog($room);
        $service = app(FollowUpReservationService::class);
        $this->assertTrue($service->reserveDraft(LeadRecoveryLog::query()->findOrFail($logId), $this->now()));
        DB::table('lead_recovery_logs')->where('id', $logId)->update([
            'delivery_status' => 'drafting',
            'updated_at' => '2026-11-16 09:45:00',
        ]);
        $this->assertSame(1, $this->usedSlots($bot->id, '2026-11-16'));

        $this->fakeDecisions();
        $this->fakeWriter();
        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $this->assertSame('pending', $row->delivery_status);
        $this->assertSame(1, DB::table('follow_up_approvals')->where('lead_recovery_log_id', $logId)->count());
        $this->assertSame(1, $this->usedSlots($bot->id, '2026-11-16'));
    }

    #[Test]
    public function test_stale_drafting_row_releases_held_slot_before_its_final_end(): void
    {
        // D37: แถวที่ยังถือ slot ค้างของวันนี้ (worker ตายก่อนได้ร่าง) ต้องคืน slot ก่อนเสมอ
        // ไม่ว่ารอบนี้จะจบด้วยเหตุใด — ไม่งั้นโควตาร่างรายวันหายไปจนถึงเที่ยงคืน

        // (a) จบ cancelled — ลูกค้ากลับมาคุยเอง
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'URSA');
        $logId = $this->makeLog($room);
        $this->holdStaleDraftingRow($logId);
        $this->addMessage($room->id, 'user', '2026-11-15 09:30:00', 'ลูกค้ากลับมาคุยเองครับ');
        $this->fakeDecisions();
        $this->fakeWriter(null);
        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $this->assertSame('cancelled', $row->delivery_status);
        $this->assertSame('new_customer_message', $row->reason_code);
        $this->assertSame(0, $this->usedSlots($bot->id, '2026-11-16'));
        $this->assertNull($row->draft_day);
        $this->assertNull($row->draft_reserved_at);
        $this->assertNull($row->claim_token);
        $this->assertSame(0, DB::table('follow_up_approvals')->count());

        // (b) จบ excluded/group_g1 — sort ตัดกลุ่ม
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'URSB');
        $logId = $this->makeLog($room);
        $this->holdStaleDraftingRow($logId);
        $this->fakeDecisions(sort: ['group' => ['choice' => 'G1', 'confidence' => 0.9], 'declined' => ['noul' => 0.1]]);
        $this->fakeWriter(null);
        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $this->assertSame('excluded', $row->delivery_status);
        $this->assertSame('group_g1', $row->reason_code);
        $this->assertSame(0, $this->usedSlots($bot->id, '2026-11-16'));
        $this->assertNull($row->draft_day);
        $this->assertNull($row->draft_reserved_at);
        $this->assertNull($row->claim_token);

        // (c) จบ excluded/sort_failed — ล้มครบ 3 ครั้ง (attempt_count เริ่มที่ 2)
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'URSC');
        $logId = $this->makeLog($room);
        DB::table('lead_recovery_logs')->where('id', $logId)->update([
            'attempt_count' => 2,
            'reason_code' => 'sort_failed',
        ]);
        $this->holdStaleDraftingRow($logId);
        $this->fakeDecisions(sortStatus: 500);
        $this->fakeWriter(null);
        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $this->assertSame('excluded', $row->delivery_status);
        $this->assertSame('sort_failed', $row->reason_code);
        $this->assertSame(3, $row->attempt_count);
        $this->assertSame(0, $this->usedSlots($bot->id, '2026-11-16'));
        $this->assertNull($row->draft_day);
        $this->assertNull($row->draft_reserved_at);
        $this->assertNull($row->claim_token);

        // (d) แถว sorting stale ที่ไม่มีการจอง — ไม่กระทบ เดินจบถึง pending ตามเดิม
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'URSD');
        $logId = $this->makeLog($room);
        DB::table('lead_recovery_logs')->where('id', $logId)->update([
            'delivery_status' => 'sorting',
            'updated_at' => '2026-11-16 09:45:00',
        ]);
        $this->fakeDecisions();
        $this->fakeWriter();
        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $this->assertSame('pending', $row->delivery_status);
        $this->assertSame(1, $this->usedSlots($bot->id, '2026-11-16'));
    }

    /** จอง slot ให้แถวแล้วทิ้งสถานะ drafting เกินเวลา — แถวถือ slot ของวันนี้ค้างไว้ */
    private function holdStaleDraftingRow(int $logId): void
    {
        app(FollowUpReservationService::class)->reserveDraft(LeadRecoveryLog::query()->findOrFail($logId), $this->now());
        DB::table('lead_recovery_logs')->where('id', $logId)->update([
            'delivery_status' => 'drafting',
            'updated_at' => '2026-11-16 09:45:00',
        ]);
    }

    // ------------------------------------------------------------------
    // ค่าใช้จ่ายและ usage (D31 เสริม)
    // ------------------------------------------------------------------

    #[Test]
    public function test_null_costs_store_null_cost_and_numeric_only_usage(): void
    {
        $bot = $this->seedBot();
        $room = $this->makeRoom($bot, 'UCN');
        $logId = $this->makeLog($room);
        $this->fakeDecisions(
            sortUsage: ['input_tokens' => 11, 'output_tokens' => 3, 'cost' => null],
            checkUsage: ['input_tokens' => 7, 'output_tokens' => 2, 'cost' => null],
        );
        $this->fakeWriter(cost: null);

        $this->runPrepare($logId);

        $row = $this->logRow($logId);
        $this->assertSame('pending', $row->delivery_status);
        $this->assertNull($row->model_cost);
        $this->assertSame(
            [
                'sort' => ['input_tokens' => 11, 'output_tokens' => 3],
                'draft' => ['input_tokens' => 50, 'output_tokens' => 12],
                'check' => ['input_tokens' => 7, 'output_tokens' => 2],
            ],
            json_decode((string) $row->usage_totals, true)
        );
    }

    // ------------------------------------------------------------------
    // Log ไม่พาข้อมูลหลุด (canary)
    // ------------------------------------------------------------------

    #[Test]
    public function test_logs_never_hold_canary_and_pipeline_context_has_three_keys_only(): void
    {
        $bot = $this->seedBot();
        $room = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'UCAN']);
        $this->addMessage($room->id, 'user', '2026-11-15 08:55:00', 'ถามราคา canary'.'-msg-2026 ครับ');
        $this->addMessage($room->id, 'user', '2026-11-15 08:59:00', 'ประเมินราคาให้หน่อยครับ');
        FollowUpSalesProjector::refresh($room, $this->now());
        $logId = $this->makeLog($room);
        $this->fakeDecisions();
        $this->fakeWriter(text: 'ยังสนใจอยู่ไหมครับ canary'.'-draft-2026');
        $this->startLogCapture();

        $this->runPrepare($logId);

        $this->assertSame('pending', $this->logRow($logId)->delivery_status);
        foreach ($this->logCalls as $call) {
            $serialized = $call['message'].json_encode($call['context']);
            $this->assertStringNotContainsString('canary-msg-2026', $serialized);
            $this->assertStringNotContainsString('canary-draft-2026', $serialized);
            if (str_starts_with($call['message'], 'Follow-up pipeline')) {
                $this->assertSame(['log_id', 'step', 'reason'], array_keys($call['context']));
            }
        }
    }

    // ------------------------------------------------------------------
    // เวลาเขียนเป็นสตริงกรุงเทพฯ แม้ $now มาจาก UTC
    // ------------------------------------------------------------------

    #[Test]
    public function test_times_are_written_as_bangkok_wall_clock_strings(): void
    {
        $this->assertSame('Asia/Bangkok', config('app.timezone'), 'pre-condition: production app time zone');
        $bot = $this->seedBot();

        // deferred: next_attempt_at + updated_at เป็นเวลากรุงเทพฯ
        $room = $this->makePaymentRoom($bot, 'UTZ1', $this->at('2026-11-16 11:00:00'));
        $logId = $this->makeLog($room, $this->at('2026-11-16 11:00:00'));
        $this->fakeDecisions();
        $this->fakeWriter(null);
        $utcNow = CarbonImmutable::parse('2026-11-16 03:00:00', 'UTC');
        $this->runPrepare($logId, $utcNow);

        $row = $this->logRow($logId);
        $this->assertSame('2026-11-16 10:00:00', (string) $row->updated_at);
        $this->assertSame('2026-11-17 06:00:00', (string) $row->next_attempt_at);

        // pending: expires_at + updated_at เป็นเวลากรุงเทพฯ (payment room G2)
        $room2 = $this->makePaymentRoom($bot, 'UTZ2', $this->at('2026-11-16 11:00:00'));
        $logId2 = $this->makeLog($room2, $this->at('2026-11-16 11:00:00'));
        $this->fakeDecisions(sort: ['group' => ['choice' => 'G2', 'confidence' => 0.95], 'declined' => ['noul' => 0.05]]);
        $this->fakeWriter();
        $this->runPrepare($logId2, $utcNow);

        $row2 = $this->logRow($logId2);
        $this->assertSame('pending', $row2->delivery_status, "reason={$row2->reason_code} group={$row2->group_code} next={$row2->next_attempt_at}");
        $this->assertSame('2026-11-16 10:00:00', (string) $row2->updated_at);
        $approval = DB::table('follow_up_approvals')->where('lead_recovery_log_id', $logId2)->first();
        $this->assertSame('2026-11-16 22:00:00', (string) $approval->expires_at);
        // D38: เวลาสร้าง/แก้ของแถวอนุมัติเป็นสตริงกรุงเทพฯ แม้ $now มาจาก UTC
        $this->assertSame('2026-11-16 10:00:00', (string) $approval->created_at);
        $this->assertSame('2026-11-16 10:00:00', (string) $approval->updated_at);
        $expectedExpiry = CarbonImmutable::parse((string) $row2->drafted_at, 'Asia/Bangkok')
            ->addHours(12)->format('Y-m-d H:i:s');
        $this->assertSame($expectedExpiry, (string) $approval->expires_at);
    }

    // ------------------------------------------------------------------
    // Job — tries/timeout/serialise/handle
    // ------------------------------------------------------------------

    #[Test]
    public function test_job_contract(): void
    {
        $job = new GenerateCustomerFollowUp(7);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(1, $job->tries);
        $this->assertSame(180, $job->timeout);

        // payload การ์ดคือ id เท่านั้น — tries/timeout/logId ครบ และไม่มีข้อมูลอื่นใน property อื่น
        $serialised = serialize($job);
        $restored = unserialize($serialised);
        $this->assertSame(7, $restored->logId);
        $this->assertSame(
            ['tries' => 1, 'timeout' => 180, 'logId' => 7],
            array_intersect_key(get_object_vars($job), ['tries' => true, 'timeout' => true, 'logId' => true])
        );
        // payload การ์ดคือ id เท่านั้น — จาก id 7 เป็น id 8 มีแค่ logId เปลี่ยน
        $otherVars = get_object_vars(new GenerateCustomerFollowUp(8));
        $jobVars = get_object_vars($job);
        $differences = [];
        foreach ($otherVars as $prop => $value) {
            if ($jobVars[$prop] !== $value) {
                $differences[] = $prop;
            }
        }
        $this->assertSame(['logId'], $differences);
        foreach (['conversation_id', 'claim_token', 'context', 'draft'] as $forbidden) {
            $this->assertStringNotContainsString($serialised, $forbidden);
        }

        // handle เรียก prepare ครั้งเดียวด้วย id นั้น
        $pipeline = $this->mock(FollowUpPipeline::class);
        $pipeline->shouldReceive('prepare')->once()->with(7);
        (new GenerateCustomerFollowUp(7))->handle($pipeline);
    }

    // ------------------------------------------------------------------
    // Fixtures and helpers
    // ------------------------------------------------------------------

    private function seedBot(): Bot
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-6-luna-writer',
        ]);
        config([
            'customer_follow_up.bot_ids' => [$bot->id],
            'customer_follow_up.admin_web_url' => 'https://admin.example.test',
            'services.telegram_alert.secret' => 'webhook-secret-test',
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

        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งเตือน',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'tok-'.md5($bot->id), 'chat_id' => '999', 'authorized_user_ids' => ['555']],
        ]);

        return $bot;
    }

    /** ห้องเงียบ 25 ชม. (ข้อความลูกค้าสองข้อความ + projection สด) พร้อมแถว log */
    private function makeRoom(Bot $bot, string $externalCustomerId, ?CarbonImmutable $refreshAt = null): Conversation
    {
        $room = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => $externalCustomerId,
        ]);
        $this->addMessage($room->id, 'user', '2026-11-15 08:55:00', 'สนใจสินค้าครับ');
        $this->addMessage($room->id, 'user', '2026-11-15 08:59:00', 'ประเมินราคาให้หน่อยครับ');
        FollowUpSalesProjector::refresh($room, $refreshAt ?? $this->now());

        return $room;
    }

    /** ห้องสเตปชำระเงินจริง: บอทแจ้งคิว Support (เงียบ 5 ชม. ก่อน $now พอดี) — projector เห็น payment_step */
    private function makePaymentRoom(Bot $bot, string $externalCustomerId, CarbonImmutable $now): Conversation
    {
        $room = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => $externalCustomerId,
        ]);
        $this->addMessage($room->id, 'user', $now->subHours(5)->subMinutes(5)->format('Y-m-d H:i:s'), 'สนใจสินค้าครับ');
        $this->addMessage($room->id, 'user', $now->subHours(5)->subMinutes(2)->format('Y-m-d H:i:s'), 'ประเมินราคาให้หน่อยครับ');
        $this->addMessage($room->id, 'bot', $now->subHours(5)->format('Y-m-d H:i:s'), '[แจ้งเตือน Support] ทีมงานรับเรื่องไว้แล้ว รบกวนรอสักครู่นะครับ');
        FollowUpSalesProjector::refresh($room, $now);

        return $room;
    }

    private function makeLog(Conversation $room, ?CarbonImmutable $now = null): int
    {
        $log = app(FollowUpReservationService::class)->candidate($room, $now ?? $this->now());

        return $log->id;
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

    /**
     * fake Decisions API — ยิงกี่ครั้งก็ได้: ครั้งแรกคำตอบ sort, ถัดไปคำตอบ check
     *
     * @param  array<string, mixed>|null  $sort
     * @param  array<string, mixed>|null  $check
     */
    private function fakeDecisions(
        ?array $sort = null,
        ?array $check = null,
        int $sortStatus = 200,
        int $checkStatus = 200,
        ?array $sortUsage = null,
        ?array $checkUsage = null,
        ?\Closure $onSort = null,
    ): void {
        $sortAnswer = $sort ?? ['group' => ['choice' => 'G3', 'confidence' => 0.9], 'declined' => ['noul' => 0.1]];
        $checkAnswer = $check ?? array_fill_keys(array_keys(FollowUpPrompts::checkQuestions()), ['noul' => 0.1]);
        $sortUsage = $sortUsage ?? ['input_tokens' => 100, 'output_tokens' => 20, 'cost' => 0.0001];
        $checkUsage = $checkUsage ?? ['input_tokens' => 30, 'output_tokens' => 8, 'cost' => 0.0003];
        $this->decisionRequests = [];

        // Http::fake() เติม stub ต่อท้ายของเก่า ไม่ทับ — stub รุ่นเก๋าต้องปล่อยผ่านให้รุ่นใหม่
        $this->fakeGeneration++;
        $generation = $this->fakeGeneration;

        Http::fake(function (Request $request) use ($sortAnswer, $checkAnswer, $sortStatus, $checkStatus, $sortUsage, $checkUsage, $onSort, $generation): mixed {
            if ($generation !== $this->fakeGeneration) {
                return null; // stub รุ่นเก่า — ให้ stub รุ่นล่าสุดตอบ
            }
            $body = $request->data();
            $isSort = isset($body['questions']['group']);
            $this->decisionRequests[] = ['step' => $isSort ? 'sort' : 'check', 'body' => $body];
            $this->decisionRequestTotal++;

            if ($isSort) {
                if ($onSort !== null) {
                    $onSort();
                }

                return $sortStatus === 200
                    ? Http::response(['answers' => $sortAnswer, 'usage' => $sortUsage])
                    : Http::response([], $sortStatus);
            }

            return $checkStatus === 200
                ? Http::response(['answers' => $checkAnswer, 'usage' => $checkUsage])
                : Http::response([], $checkStatus);
        });
    }

    /**
     * mock OpenRouterService (writer) — ผูกเข้า container ให้ FollowUpDraftService ใช้
     * payload ลอกรูปร่างเดียวกับ OpenRouterService::parseResponse จริง (usage เป็น
     * prompt_tokens/completion_tokens และส่ง key อื่นมาด้วย เพื่อพิสูจน์ว่า pipeline
     * ไม่คัดลอก key อื่นเข้า usage_totals) — จับ messages ทุกครั้งไว้ใน writerCalls
     *
     * @return MockInterface&OpenRouterService
     */
    private function fakeWriter(
        ?string $text = 'ทางร้านขออนุญาตถามหน่อยครับ ยังสนใจรายการเดิมอยู่ไหมครับ',
        ?float $cost = 0.0002,
        ?\Closure $onCall = null,
    ): MockInterface {
        $mock = $this->mock(OpenRouterService::class);
        $expectation = $mock->shouldReceive('chat');
        $this->writerCalls = [];

        if ($text === null) {
            $expectation->never();

            return $mock;
        }

        $payload = static fn (): array => [
            'content' => $text,
            'reasoning' => null,
            'model' => 'openai/gpt-6-luna-writer',
            'usage' => [
                'prompt_tokens' => 50,
                'completion_tokens' => 12,
                'total_tokens' => 62,
                'cached_tokens' => 0,
                'reasoning_tokens' => 0,
                'cost' => $cost,
            ],
            'id' => 'chatcmpl-'.'fake-b3c',
            'finish_reason' => 'stop',
        ];

        $expectation->once()->andReturnUsing(function (array $messages) use ($onCall, $payload): array {
            $this->writerCalls[] = ['messages' => $messages];
            if ($onCall !== null) {
                $onCall();
            }

            return $payload();
        });

        return $mock;
    }

    private function startLogCapture(): void
    {
        $this->logCalls = [];
        foreach (['warning', 'error', 'notice', 'info'] as $level) {
            Log::shouldReceive($level)
                ->zeroOrMoreTimes()
                ->andReturnUsing(function (string $message, array $context = []) use ($level): void {
                    $this->logCalls[] = ['level' => $level, 'message' => $message, 'context' => $context];
                });
        }
    }

    private function runPrepare(int $logId, ?CarbonImmutable $now = null): void
    {
        app(FollowUpPipeline::class)->prepare($logId, $now ?? $this->now());
    }

    private function now(): CarbonImmutable
    {
        return $this->at(self::NOW);
    }

    private function at(string $localBangkok): CarbonImmutable
    {
        return CarbonImmutable::parse($localBangkok, 'Asia/Bangkok');
    }

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

    private function assertRowUnchanged(object $before, int $logId, string $message): void
    {
        $this->assertSame((array) $before, (array) $this->logRow($logId), $message);
    }
}
