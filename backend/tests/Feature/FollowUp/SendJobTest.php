<?php

namespace Tests\Feature\FollowUp;

use App\Jobs\ProcessCustomerFollowUps;
use App\Jobs\SendCustomerFollowUp;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\User;
use App\Services\FollowUp\FollowUpReservationService;
use App\Services\FollowUp\FollowUpSalesProjector;
use App\Services\FollowUp\FollowUpSendService;
use App\Services\FollowUp\FollowUpTelegramService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * การ์ด B6e — job SendCustomerFollowUp: ส่งข้อความที่เจ้านายกดอนุมัติ (D101-D105)
 *
 * ครอบคลุม:
 * - ผลของ sender แต่ละชนิดผ่าน handle: sent/already_sent = ระบายการ์ด ✅,
 *   retry = คิวงานเดิมซ้ำเลื่อนเวลา, อื่น ๆ = จบเงียบ
 * - เส้นขอบ: refreshCard พัง = กลืน + warning แค่ชื่อ class, send พัง = โยนต่อ
 * - แท็ปอนุมัติผ่าน route จริง = คิวงานหลัง editMessageText ของแท็ปเสมอ
 * - แท็ป → ลูกค้า บนคิว sync ครบทุก request ตามลำดับจริง
 * - สแกน (step E) เก็บงานค้างบนคิว sync: sending ค้าง 10 นาที / approved ที่โควตาปัดกลับ
 *
 * ไม่มีการเรียกจริงออกนอกเครื่อง — Http::fake ทั้งหมด คิวทดสอบเป็น sync ตาม phpunit.xml
 */
class SendJobTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 10:00:00';

    private const LINE_QUOTA_URL = 'https://api.line.me/v2/bot/message/quota';

    private const LINE_CONSUMPTION_URL = 'https://api.line.me/v2/bot/message/quota/consumption';

    private const LINE_PUSH_URL = 'https://api.line.me/v2/bot/message/push';

    private const RECIPIENT = 'Ub6e000112233445566778899aabbccdd';

    private const TEXT = "สวัสดีครับพี่ เมื่อวานที่คุยเรื่องบัญชีโฆษณาไว้\nมีจุดไหนให้ช่วยดูเพิ่มไหมครับ";

    private const EDITED_TEXT = "ข้อความที่เจ้านายแก้เองแล้ว\nยังสนใจอยู่ไหมครับ";

    private const CLEAN_CHECK = '{"scores":{"safe_to_send":0.9},"failed":[],"error":false,"version":1}';

    private const FLAGGED_CHECK = '{"scores":{"safe_to_send":0.9},"failed":["pressure"],"error":false,"version":1}';

    private const NONCE = 'abcd1234abcd1234';

    private const CB_ID = 'cb-canary-b6e';

    private const PLUGIN_CHAT = '888';

    private const PERSON_ID = 555;

    private const UPDATE_ID = 999001;

    private const REQUEST_ID = 'req-b6e-1';

    /** ข้อความลับที่ห้ามโผล่ใน log — พิสูจน์ว่า warning ไม่พา exception message */
    private const LEAK_MARKER = 'leak-canary-b6e-secret';

    /** คีย์ยิงครั้งแรกของแถวกู้คืน — push รอบสองต้องใช้คีย์เดิม (รูปร่าง UUID ตามที่ขนส่งยอมรับ) */
    private const RECOVERY_KEY = '9f8a7c6d'.'-5e4b-4a3c'.'-9d2e-1b0a7c6d5e4f';

    /** queue driver ที่ไม่มีจริง — dispatch ต้องพังแบบกลืนได้ (บททดสอบ warning เฉพาะ class) */
    private const BROKEN_DRIVER = 'b6e-missing-connector';

    /** @var list<string> url ที่โดนยิง ตามจังหวะ */
    private array $hits = [];

    /** @var list<array{0: string, 1: string}> [retry key, body] ของ push ทุกครั้ง */
    private array $pushes = [];

    /** @var list<Illuminate\Http\Client\Request> ทุก request ตามจังหวะ */
    private array $httpRequests = [];

    /** @var list<MessageLogged> */
    private array $logEvents = [];

    /** คิวงานที่ถูก push ณ จังหวะแท็ปยิง editMessageText ⏳ — ต้องว่างเสมอ */
    private ?array $queuedAtCardEdit = null;

    /** @var list<int> id ที่ผ่าน send จริง */
    private array $sendIds = [];

    /** @var list<int> id ที่ผ่าน refreshCard จริง */
    private array $repaintIds = [];

    private string $webhookSecret = 'webhook-secret-'.'b6e';

    /** token ของ plugin ในโลกแท็ปปัจจุบัน — route เรียกด้วย token นี้ */
    private ?string $worldToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->freeze(self::NOW);
        $this->logEvents = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logEvents[] = $event;
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // ผลของ sender ผ่าน handle
    // ------------------------------------------------------------------

    #[Test]
    public function test_accepted_send_exact_requests_in_order_and_rows(): void
    {
        $seed = $this->seedReady();
        $this->fakeHttp();

        $this->handleJob($seed['approvalId']);

        $methods = array_map(fn (object $request): string => $this->methodOf($request), $this->httpRequests);
        $this->assertSame(['quota', 'consumption', 'push', 'editMessageText'], $methods, 'LINE quota, consumption, push เดียว แล้วระบายการ์ดครั้งเดียว');
        $this->assertSame(1, $this->hits[self::LINE_PUSH_URL] ?? 0, 'ยิง push ครั้งเดียว');
        $this->assertNotSame('', $this->pushes[0][0], 'push ต้องพา retry key');
        $this->assertStringEndsWith('✅ ส่งให้ลูกค้าแล้ว', $this->editTexts()[0]);

        $log = $this->logRow($seed);
        $this->assertSame('sent', $log->delivery_status);
        $this->assertSame('sent', $this->approvalRow($seed)->status);
        $message = DB::table('messages')->where('id', (int) $log->message_id)->first();
        $this->assertNotNull($message);
        $this->assertSame(self::TEXT, $message->content, 'แถวประวัติบอทต้องเท่ากับข้อความบนบัตรทุกไบต์');
    }

    #[Test]
    public function test_push_500_queues_exactly_one_retry_with_delay_60_and_no_telegram(): void
    {
        $seed = $this->seedReady();
        Queue::fake();
        $this->fakeHttp([['{}', 500]]);

        $this->handleJob($seed['approvalId']);

        $log = $this->logRow($seed);
        $this->assertSame('retry', $log->delivery_status);
        $this->assertSame('line_retryable', $log->reason_code);
        $this->assertSame('2026-11-16 10:01:00', $log->next_attempt_at, 'รอ 60 วินาที');
        $this->assertSame('sending', $this->approvalRow($seed)->status);
        $this->assertSame([], $this->telegramRequests(), 'retry ห้ามระบายการ์ด');

        Queue::assertPushed(SendCustomerFollowUp::class, 1);
        $entry = Queue::pushedJobs()[SendCustomerFollowUp::class][0];
        $this->assertSame($seed['approvalId'], $entry['job']->approvalId);
        $this->assertSame(60, $entry['job']->delay, 'เลื่อนพอดี 60 วินาที');
    }

    #[Test]
    public function test_push_timeout_queues_exactly_one_retry_with_delay_60(): void
    {
        $seed = $this->seedReady();
        Queue::fake();
        $this->fakeHttp(['timeout']);

        $this->handleJob($seed['approvalId']);

        $log = $this->logRow($seed);
        $this->assertSame('retry', $log->delivery_status);
        $this->assertSame('2026-11-16 10:01:00', $log->next_attempt_at);

        Queue::assertPushed(SendCustomerFollowUp::class, 1);
        $entry = Queue::pushedJobs()[SendCustomerFollowUp::class][0];
        $this->assertSame($seed['approvalId'], $entry['job']->approvalId);
        $this->assertSame(60, $entry['job']->delay);
    }

    #[Test]
    public function test_second_retry_queues_delay_300(): void
    {
        $seed = $this->seedReady();
        Queue::fake();
        $this->fakeHttp([['{}', 500]]);

        $this->handleJob($seed['approvalId']);
        $this->assertSame(60, Queue::pushedJobs()[SendCustomerFollowUp::class][0]['job']->delay);

        $this->freeze('2026-11-16 10:01:00');
        Queue::fake();
        $this->handleJob($seed['approvalId']);

        $log = $this->logRow($seed);
        $this->assertSame('retry', $log->delivery_status);
        $this->assertSame(2, (int) $log->attempt_number);
        $this->assertSame('2026-11-16 10:06:00', $log->next_attempt_at, 'รอบสองรอ 300 วินาที');

        Queue::assertPushed(SendCustomerFollowUp::class, 1);
        $entry = Queue::pushedJobs()[SendCustomerFollowUp::class][0];
        $this->assertSame($seed['approvalId'], $entry['job']->approvalId);
        $this->assertSame(300, $entry['job']->delay);
    }

    #[Test]
    public function test_push_400_ends_cancelled_with_repaint_and_nothing_queued(): void
    {
        $seed = $this->seedReady();
        Queue::fake();
        $this->fakeHttp([['{}', 400]]);

        $this->handleJob($seed['approvalId']);

        $log = $this->logRow($seed);
        $this->assertSame('send_failed', $log->delivery_status);
        $this->assertSame('line_rejected_400', $log->reason_code);
        $this->assertSame('cancelled', $this->approvalRow($seed)->status);
        $this->assertSame(1, $this->hits[self::LINE_PUSH_URL] ?? 0);

        $edits = $this->editTexts();
        $this->assertCount(1, $edits, 'ระบายการ์ดครั้งเดียว');
        $this->assertStringEndsWith('🚫 ยกเลิก ไม่ได้ส่ง (LINE ไม่รับข้อความ)', $edits[0]);

        Queue::assertPushed(SendCustomerFollowUp::class, 0);
    }

    #[Test]
    public function test_quota_below_reserve_blocks_without_push_telegram_or_queue(): void
    {
        $seed = $this->seedReady();
        config(['customer_follow_up.quota_reserve_messages' => 250]);
        Queue::fake();
        $this->fakeHttp();

        $this->handleJob($seed['approvalId']);

        $log = $this->logRow($seed);
        $this->assertSame('approved', $log->delivery_status);
        $this->assertSame('below_reserve', $log->reason_code);
        $this->assertSame('approved', $this->approvalRow($seed)->status);
        $this->assertSame(0, $this->hits[self::LINE_PUSH_URL] ?? 0, 'โควตาไม่พอห้ามยิง');
        $this->assertSame([], $this->telegramRequests(), 'แถวคืน approved คงการ์ด ⏳ เดิม (D102)');

        Queue::assertPushed(SendCustomerFollowUp::class, 0);
    }

    #[Test]
    public function test_customer_message_after_the_tap_cancels_with_repaint(): void
    {
        $seed = $this->seedReady();
        $this->addMessage($seed['room']->id, 'user', '2026-11-16 09:30:00', 'ตกลงเอาครับ');
        Queue::fake();
        $this->fakeHttp();

        $this->handleJob($seed['approvalId']);

        $log = $this->logRow($seed);
        $this->assertSame('cancelled', $log->delivery_status);
        $this->assertSame('new_customer_message', $log->reason_code);
        $this->assertSame('cancelled', $this->approvalRow($seed)->status);
        $this->assertSame(0, $this->hits[self::LINE_PUSH_URL] ?? 0);

        $edits = $this->editTexts();
        $this->assertCount(1, $edits);
        $this->assertStringEndsWith('🚫 ยกเลิก ไม่ได้ส่ง (ลูกค้าพิมพ์เข้ามาแล้ว)', $edits[0]);

        Queue::assertPushed(SendCustomerFollowUp::class, 0);
    }

    #[Test]
    public function test_approved_row_past_expiry_closes_expired_with_repaint(): void
    {
        $seed = $this->seedReady([], ['expires_at' => '2026-11-16 09:59:59']);
        Queue::fake();
        $this->fakeHttp();

        $this->handleJob($seed['approvalId']);

        $log = $this->logRow($seed);
        $this->assertSame('expired', $log->delivery_status);
        $this->assertSame('expired_before_send', $log->reason_code);
        $this->assertSame('expired', $this->approvalRow($seed)->status);
        $this->assertSame(0, $this->hits[self::LINE_PUSH_URL] ?? 0);

        $edits = $this->editTexts();
        $this->assertCount(1, $edits);
        $this->assertStringEndsWith('⌛ หมดอายุ ไม่ได้ส่ง', $edits[0]);

        Queue::assertPushed(SendCustomerFollowUp::class, 0);
    }

    #[Test]
    public function test_third_push_failure_ends_acceptance_unknown_with_repaint(): void
    {
        $seed = $this->seedReady([
            'delivery_status' => 'retry',
            'next_attempt_at' => '2026-11-16 09:59:00',
            'updated_at' => '2026-11-16 09:52:00',
            'first_push_at' => '2026-11-16 09:52:00',
            'message_sent' => self::TEXT,
            'attempt_number' => 2,
            'retry_key' => self::RECOVERY_KEY,
        ], ['status' => 'sending']);
        Queue::fake();
        $this->fakeHttp([['{}', 500]]);

        $this->handleJob($seed['approvalId']);

        $log = $this->logRow($seed);
        $this->assertSame('send_failed', $log->delivery_status);
        $this->assertSame('acceptance_unknown_attempts', $log->reason_code);
        $this->assertSame(3, (int) $log->attempt_number);
        $this->assertSame('cancelled', $this->approvalRow($seed)->status);
        $this->assertSame(1, $this->hits[self::LINE_PUSH_URL] ?? 0);

        $edits = $this->editTexts();
        $this->assertCount(1, $edits);
        $this->assertStringEndsWith('⚠️ ส่งแล้วแต่ยืนยันผลไม่ได้ โปรดเปิดห้องตรวจ', $edits[0]);

        Queue::assertPushed(SendCustomerFollowUp::class, 0);
    }

    #[Test]
    public function test_already_sent_row_repaints_once_without_line(): void
    {
        $seed = $this->seedReady([
            'delivery_status' => 'sent',
            'sent_at' => '2026-11-16 09:52:00',
            'message_sent' => self::TEXT,
            'first_push_at' => '2026-11-16 09:52:00',
        ], ['status' => 'sent']);
        Queue::fake();
        $this->fakeHttp();
        $this->spyTelegram();

        $this->handleJob($seed['approvalId']);

        $this->assertSame(0, $this->hits[self::LINE_PUSH_URL] ?? 0, 'แถวยิงแล้วห้ามยิงซ้ำ');
        $this->assertArrayNotHasKey(self::LINE_QUOTA_URL, $this->hits);
        $this->assertSame([$seed['approvalId']], $this->repaintIds, 'already_sent ต้องระบายการ์ดคืนครั้งเดียว');
        $edits = $this->editTexts();
        $this->assertCount(1, $edits);
        $this->assertStringEndsWith('✅ ส่งให้ลูกค้าแล้ว', $edits[0]);

        Queue::assertPushed(SendCustomerFollowUp::class, 0);
    }

    #[Test]
    public function test_pending_skipped_and_missing_rows_do_nothing(): void
    {
        // คู่ pending: claim ปฏิเสธ ทุกคอลัมน์คงเดิมทุกไบต์
        $pendingSeed = $this->seedReady(['updated_at' => '2026-11-16 09:40:00'], ['status' => 'pending', 'updated_at' => '2026-11-16 09:41:00']);
        $pendingLogBefore = (array) $this->logRow($pendingSeed);
        $pendingApprovalBefore = (array) $this->approvalRow($pendingSeed);
        // คู่ skipped: owner gate ตีกลับก่อน claim
        $skippedSeed = $this->seedReady(['delivery_status' => 'skipped', 'owner_action' => 'skipped', 'updated_at' => '2026-11-16 09:42:00'], ['status' => 'skipped', 'updated_at' => '2026-11-16 09:43:00']);
        $skippedLogBefore = (array) $this->logRow($skippedSeed);
        $skippedApprovalBefore = (array) $this->approvalRow($skippedSeed);
        Queue::fake();
        $this->fakeHttp();
        $this->spyTelegram();

        $this->handleJob($pendingSeed['approvalId']);
        $this->handleJob($skippedSeed['approvalId']);
        $this->handleJob(424242);

        $this->assertSame([], $this->httpRequests, 'ทั้งสามแถวห้ามยิงอะไร');
        $this->assertSame([], $this->repaintIds, 'pending/skipped/missing ห้ามระบายการ์ด');
        Queue::assertPushed(SendCustomerFollowUp::class, 0);
        $this->assertSame($pendingLogBefore, (array) $this->logRow($pendingSeed));
        $this->assertSame($pendingApprovalBefore, (array) $this->approvalRow($pendingSeed));
        $this->assertSame($skippedLogBefore, (array) $this->logRow($skippedSeed));
        $this->assertSame($skippedApprovalBefore, (array) $this->approvalRow($skippedSeed));
    }

    #[Test]
    public function test_send_and_repaint_receive_exactly_the_job_id(): void
    {
        $seed = $this->seedReady();
        Queue::fake();
        $this->fakeHttp();
        $this->spySend();
        $this->spyTelegram();

        $this->handleJob($seed['approvalId']);

        $this->assertSame([$seed['approvalId']], $this->sendIds, 'send รับ id ของ job นี้พอดี');
        $this->assertSame([$seed['approvalId']], $this->repaintIds, 'refreshCard รับ id ของ job นี้พอดี');
    }

    // ------------------------------------------------------------------
    // เส้นขอบของ job
    // ------------------------------------------------------------------

    #[Test]
    public function test_repaint_exception_is_swallowed_with_class_only_warning(): void
    {
        $seed = $this->seedReady();
        Queue::fake();
        $this->fakeHttp();
        $this->mock(FollowUpTelegramService::class, function (MockInterface $mock) use ($seed): void {
            $mock->shouldReceive('refreshCard')
                ->once()
                ->with($seed['approvalId'])
                ->andThrow(new RuntimeException(self::LEAK_MARKER));
        });

        $this->handleJob($seed['approvalId']);

        $this->assertSame('sent', $this->logRow($seed)->delivery_status, 'การ์ดพัง = ผลส่งยัง sent');
        $warnings = array_values(array_filter($this->logEvents, fn (MessageLogged $event): bool => $event->level === 'warning'));
        $this->assertCount(1, $warnings, 'warning เดียวต่อ repaint ที่พัง');
        $this->assertSame('Customer follow-up card repaint failed', $warnings[0]->message);
        $this->assertSame([
            'approval_id' => $seed['approvalId'],
            'exception_class' => RuntimeException::class,
        ], $warnings[0]->context);
        foreach ($this->logEvents as $event) {
            $text = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString(self::LEAK_MARKER, $text, 'ห้ามพา message ของ exception ลง log');
        }

        Queue::assertPushed(SendCustomerFollowUp::class, 0);
    }

    #[Test]
    public function test_send_exception_propagates_without_repaint_or_queue(): void
    {
        $seed = $this->seedReady();
        Queue::fake();
        $this->fakeHttp();
        $this->spyTelegram();
        $this->mock(FollowUpSendService::class, function (MockInterface $mock) use ($seed): void {
            $mock->shouldReceive('send')
                ->once()
                ->with($seed['approvalId'])
                ->andThrow(new RuntimeException(self::LEAK_MARKER));
        });

        try {
            $this->handleJob($seed['approvalId']);
            $this->fail('exception จาก send ต้องโยนออกจาก job');
        } catch (RuntimeException $failure) {
            $this->assertSame(self::LEAK_MARKER, $failure->getMessage());
        }

        $this->assertSame([], $this->repaintIds, 'send พัง = ห้ามระบายการ์ด');
        Queue::assertPushed(SendCustomerFollowUp::class, 0);
    }

    #[Test]
    public function test_undelivered_card_send_happens_without_telegram_request(): void
    {
        $seed = $this->seedReady([], ['telegram_delivery_state' => 'new']);
        Queue::fake();
        $this->fakeHttp();
        $this->spyTelegram();

        $this->handleJob($seed['approvalId']);

        $this->assertSame('sent', $this->logRow($seed)->delivery_status, 'การ์ดไม่ถูกส่ง = ส่งหาลูกค้าได้ปกติ');
        $this->assertSame([$seed['approvalId']], $this->repaintIds);
        $this->assertSame([], $this->telegramRequests(), 'การ์ดไม่เคยถูกส่ง = ไม่ยิงหา Telegram');
        Queue::assertPushed(SendCustomerFollowUp::class, 0);
    }

    /** B2: เรียกบริการแจ้งอัตโนมัติตรง ๆ (ยังไม่มี caller จริง — B3b จะเพิ่ม) */
    #[Test]
    public function test_auto_notice_method_contract_is_separate_from_repaint(): void
    {
        // (1) แถว synthetic sent/automatic: แจ้งเข้าห้องเตือนเท่านั้น — ไม่แตะ LINE ไม่แตะการ์ด
        $auto = $this->seedReady([
            'delivery_status' => 'sent',
            'owner_action' => 'automatic',
            'owner_action_at' => '2026-11-16 09:52:00',
            'owner_telegram_user_id' => null,
            'message_sent' => self::TEXT,
            'sent_at' => '2026-11-16 09:52:00',
            'first_push_at' => '2026-11-16 09:52:00',
            'attempt_number' => 1,
            'updated_at' => '2026-11-16 09:52:00',
            // แถว automatic จริงต้องมีกลุ่ม+ความมั่นใจ (กฎ A1) — ขาด = notice ต้อง blocked
            'group_code' => 'G3',
            'group_confidence' => 0.86,
        ], [
            'status' => 'sent',
            'telegram_delivery_state' => 'new',
            'telegram_chat_id' => null,
            'telegram_message_id' => null,
        ]);
        // log.message_id อ้าง FK messages — สร้างข้อความบอทที่ LINE ยอมรับแล้วจริง
        $autoMessageId = $this->addMessage($auto['room']->id, 'bot', '2026-11-16 09:52:00', self::TEXT);
        DB::table('lead_recovery_logs')->where('id', $auto['logId'])->update(['message_id' => $autoMessageId]);
        $autoLogBefore = (array) $this->logRow($auto);
        $this->fakeHttp();

        $noticeResult = app(FollowUpTelegramService::class)->publishAutomaticNotice($auto['approvalId'], $this->now());

        $this->assertSame('sent', $noticeResult);
        $this->assertSame(0, $this->hits[self::LINE_PUSH_URL] ?? 0, 'การแจ้งห้ามยิง push LINE');
        $this->assertArrayNotHasKey(self::LINE_QUOTA_URL, $this->hits, 'การแจ้งไม่ใช่งาน LINE — ไม่ตรวจโควตา');
        $this->assertArrayNotHasKey(self::LINE_CONSUMPTION_URL, $this->hits);
        $noticeRequests = $this->telegramRequests();
        $this->assertCount(1, $noticeRequests, 'ยิง Telegram ครั้งเดียว ไม่มี editMessageText');
        $this->assertSame('sendMessage', $this->methodOf($noticeRequests[0]));
        $this->assertSame(self::PLUGIN_CHAT, $noticeRequests[0]['chat_id'], 'ปลายทางคือ chat ของ plugin เท่านั้น');
        $markup = json_decode((string) ($noticeRequests[0]['reply_markup'] ?? ''), true);
        $this->assertTrue($markup === null || array_filter($markup['inline_keyboard'] ?? []) === [], 'ข้อความแจ้งไม่มีปุ่ม');
        $this->assertSame($autoLogBefore, (array) $this->logRow($auto), 'ฝั่ง LINE บน log คงเดิมทุกไบต์');
        $this->assertSame('sent', DB::table('follow_up_approvals')->where('id', $auto['approvalId'])->value('telegram_delivery_state'));

        // (2) แถวเจ้านายกดเอง (approve mode): repaint ยังเท่าเดิมทุกไบต์
        $human = $this->seedReady([
            'delivery_status' => 'sent',
            'sent_at' => '2026-11-16 09:52:00',
            'message_sent' => self::TEXT,
            'first_push_at' => '2026-11-16 09:52:00',
        ], ['status' => 'sent']);
        $human['bot']->update(['name' => 'Line - Adsvance']);
        $humanBot = $human['bot']->refresh();
        app(FollowUpTelegramService::class)->refreshCard($human['approvalId'], $this->now());

        $humanLog = $this->logRow($human);
        $cardText = "🔔 <b>ตามลูกค้าที่เงียบไป</b> · #{$humanLog->id}\n"
            ."บอท: {$humanBot->name}\n"
            .'ลูกค้า #'.$human['room']->id."\n"
            ."กลุ่ม: ไม่ระบุ\n"
            ."ลูกค้าพิมพ์ล่าสุด: -\n"
            ."บอทตอบล่าสุด: -\n"
            ."\n"
            ."<b>ร่างข้อความ</b>\n"
            .self::TEXT."\n"
            ."\n"
            ."ตรวจข้อความ: ✅ ผ่าน\n"
            ."หมดอายุ: 16/11 12:00 (12 ชั่วโมง)\n"
            .'เปิดห้อง: https://admin.example.test/chat?botId='.$humanBot->id.'&amp;conversationId='.$human['room']->id;
        $this->assertCount(1, $this->editTexts(), 'การแจ้งเป็น sendMessage — ไม่โผล่ใน editTexts');
        $this->assertSame($cardText."\n\n".'✅ ส่งให้ลูกค้าแล้ว', $this->editTexts()[0], 'repaint ของแถวอนุมัติโดยคนต้องเท่าเดิมทุกไบต์');
    }

    #[Test]
    public function test_contract_tries_timeout_default_queue_and_payload(): void
    {
        $jobA = new SendCustomerFollowUp(7);
        $jobB = new SendCustomerFollowUp(9);

        $this->assertSame(1, $jobA->tries, 'ครั้งเดียว — พลาดให้สแกนเก็บงาน');
        $this->assertSame(60, $jobA->timeout);
        $this->assertNull($jobA->queue, 'คิวปริยายของระบบ');
        $this->assertNull($jobA->connection, 'connection ปริยายของระบบ');

        $payloadA = get_object_vars(unserialize(serialize($jobA)));
        $payloadB = get_object_vars(unserialize(serialize($jobB)));
        $this->assertSame(7, $payloadA['approvalId']);
        $this->assertSame(9, $payloadB['approvalId']);
        unset($payloadA['approvalId'], $payloadB['approvalId']);
        $this->assertSame(array_keys($payloadA), array_keys($payloadB), 'payload ต่างกันเฉพาะ approvalId');
        $this->assertSame($payloadA, $payloadB);
    }

    // ------------------------------------------------------------------
    // แท็ปผ่าน route จริง — คิวงานหลัง editMessageText ของแท็ปเสมอ
    // ------------------------------------------------------------------

    #[Test]
    public function test_approving_taps_queue_one_job_each_after_the_edit(): void
    {
        // (a) fs บนร่างสะอาด
        $clean = $this->seedTelegramWorld();
        Queue::fake();
        $this->fakeHttp();
        $response = $this->tap($this->baseCallback(data: $this->cbData('fs', $clean['approval_id'])));
        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame([$clean['approval_id']], $this->pushedSendIds(), 'fs คิวงานหนึ่งตัวของบัตรนี้');
        $this->assertSame([], $this->queuedAtCardEdit ?? ['sentinel'], 'ณ จังหวะแก้การ์ด ⏳ ยังไม่มีงานในคิว — คิวงานหลังแก้การ์ดเสมอ');
        $edits = $this->editTexts();
        $this->assertCount(1, $edits);
        $this->assertStringEndsWith('⏳ กดส่งแล้ว กำลังส่งให้ลูกค้า', $edits[0]);
        $this->assertSame('approved', DB::table('follow_up_approvals')->where('id', $clean['approval_id'])->value('status'));

        // (b) fs บนร่างติดธง (เจ้านายยอมรับเอง) — คิวงานเหมือนกัน
        $flagged = $this->seedTelegramWorld(['check_answers' => self::FLAGGED_CHECK]);
        Queue::fake();
        $response = $this->tap($this->baseCallback(data: $this->cbData('fs', $flagged['approval_id'])));
        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame([$flagged['approval_id']], $this->pushedSendIds());
        $this->assertSame('approved', DB::table('follow_up_approvals')->where('id', $flagged['approval_id'])->value('status'));

        // (c) fc บนบัตรที่แก้แล้ว (ยังไม่ผ่านตรวจ) — คิวงานเหมือนกัน
        $edited = $this->seedTelegramWorld([
            'revision' => 2,
            'approval_text' => self::EDITED_TEXT,
            'check_answers' => self::FLAGGED_CHECK,
        ]);
        Queue::fake();
        $response = $this->tap($this->baseCallback(data: $this->cbData('fc', $edited['approval_id'], 2)));
        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame([$edited['approval_id']], $this->pushedSendIds());
        $this->assertSame('approved', DB::table('follow_up_approvals')->where('id', $edited['approval_id'])->value('status'));
    }

    #[Test]
    public function test_non_approving_taps_and_edit_reply_queue_nothing(): void
    {
        // fk
        $world = $this->seedTelegramWorld();
        Queue::fake();
        $this->fakeHttp();
        $this->tap($this->baseCallback(data: $this->cbData('fk', $world['approval_id'])))->assertOk();
        $this->assertSame([], $this->pushedSendIds(), 'fk ไม่คิวงาน');

        // fe + ข้อความตอบกลับแก้ร่าง — ยังไม่คิวงาน
        $edit = $this->seedTelegramWorld();
        Queue::fake();
        $this->tap($this->baseCallback(data: $this->cbData('fe', $edit['approval_id'])))->assertOk();
        $this->assertSame([], $this->pushedSendIds(), 'fe ไม่คิวงาน');
        $promptId = (int) DB::table('follow_up_approvals')->where('id', $edit['approval_id'])->value('edit_prompt_message_id');
        $this->assertGreaterThan(0, $promptId, 'fe ต้องส่งคำใบ้แก้ร่างและจำ message id');
        $this->fireEditReply($promptId, self::EDITED_TEXT, self::UPDATE_ID + 1)->assertOk();
        $this->assertSame([], $this->pushedSendIds(), 'การตอบกลับแก้ร่างไม่คิวงาน');
        // fe เก็บสถานะ pending, บันทึกร่างใหม่แล้วขึ้น revision (รอบตัดสินใหม่)
        $editRow = DB::table('follow_up_approvals')->where('id', $edit['approval_id'])->first();
        $this->assertSame('pending', (string) $editRow->status, 'แก้ร่างแล้วอยู่รอบตัดสินใหม่');
        $this->assertSame(self::EDITED_TEXT, (string) $editRow->text, 'ร่างใหม่ถูกบันทึก');
        $this->assertSame(2, (int) $editRow->revision, 'revision ขยับเป็น 2');

        // fs บนบัตรหมดอายุ
        $expired = $this->seedTelegramWorld(['expires_at' => '2026-11-16 09:59:59']);
        Queue::fake();
        $this->tap($this->baseCallback(data: $this->cbData('fs', $expired['approval_id'])))->assertOk();
        $this->assertSame([], $this->pushedSendIds(), 'บัตรหมดอายุไม่คิวงาน');

        // fs revision เก่า (stale)
        $stale = $this->seedTelegramWorld();
        Queue::fake();
        $this->tap($this->baseCallback(data: $this->cbData('fs', $stale['approval_id'], 9)))->assertOk();
        $this->assertSame([], $this->pushedSendIds(), 'revision ไม่ตรงไม่คิวงาน');

        // คนนอกกด fs
        $outsider = $this->seedTelegramWorld();
        Queue::fake();
        $this->tap($this->baseCallback(['from' => ['id' => 777, 'first_name' => 'คนแปลกหน้า']], $this->cbData('fs', $outsider['approval_id'])))->assertOk();
        $this->assertSame([], $this->pushedSendIds(), 'คนนอก allowlist ไม่คิวงาน');

        // fs นอกเวลาส่ง
        $this->freeze('2026-11-16 22:00:00');
        $night = $this->seedTelegramWorld();
        Queue::fake();
        $this->tap($this->baseCallback(data: $this->cbData('fs', $night['approval_id'])))->assertOk();
        $this->assertSame([], $this->pushedSendIds(), 'นอกเวลาส่งไม่คิวงาน');
        $this->freeze(self::NOW);

        // ลูกค้าพิมพ์เข้ามาก่อนแท็ป
        $customer = $this->seedTelegramWorld();
        $this->addMessage($customer['room']->id, 'user', '2026-11-16 09:30:00', 'ตกลงเอาครับ');
        Queue::fake();
        $this->tap($this->baseCallback(data: $this->cbData('fs', $customer['approval_id'])))->assertOk();
        $this->assertSame([], $this->pushedSendIds(), 'ลูกค้าพิมพ์เข้ามาแล้วไม่คิวงาน');

        // fs ซ้ำบนบัตร approved แล้ว
        $repeat = $this->seedTelegramWorld();
        Queue::fake();
        $this->tap($this->baseCallback(data: $this->cbData('fs', $repeat['approval_id'])))->assertOk();
        $this->assertCount(1, $this->pushedSendIds(), 'แท็ปแรกคิวหนึ่งงาน');
        Queue::fake();
        $this->tap($this->baseCallback(data: $this->cbData('fs', $repeat['approval_id'])), self::UPDATE_ID + 2)->assertOk();
        $this->assertSame([], $this->pushedSendIds(), 'แท็ปซ้ำบนบัตร approved ไม่คิวงานเพิ่ม');
    }

    #[Test]
    public function test_lost_race_approved_tap_never_queues_a_send_job(): void
    {
        $seed = $this->seedTelegramWorld();
        Queue::fake();
        $this->fakeHttp();
        $beforeLog = (array) DB::table('lead_recovery_logs')->where('id', $seed['log_id'])->first();
        $beforeApproval = (array) DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->first();
        $selects = 0;
        DB::listen(function (QueryExecuted $query) use (&$selects, $seed): void {
            // After the first snapshot was read, another writer wins before the locked read.
            if (str_starts_with($query->sql, 'select * from "follow_up_approvals" where "id" = ?')
                && ++$selects === 1) {
                DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->update([
                    'status' => 'approved',
                    'updated_at' => '2026-11-16 09:59:59',
                ]);
            }
        });

        try {
            $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])))
                ->assertOk()->assertExactJson(['ok' => true]);
        } finally {
            app('events')->forget(QueryExecuted::class);
        }

        $this->assertGreaterThanOrEqual(2, $selects, 'the transaction must re-read the changed approval');
        $this->assertSame(['answerCallbackQuery', 'editMessageText'], array_map($this->methodOf(...), $this->httpRequests));
        $this->assertSame('กดส่งไปแล้ว กำลังส่งให้ลูกค้า', $this->httpRequests[0]['text']);
        $this->assertSame([], $this->pushedSendIds(), 'a losing writer must not enqueue another send');
        $this->assertSame($beforeLog, (array) DB::table('lead_recovery_logs')->where('id', $seed['log_id'])->first());
        $this->assertSame(array_replace($beforeApproval, [
            'status' => 'approved',
            'updated_at' => '2026-11-16 09:59:59',
        ]), (array) DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->first());
    }

    #[Test]
    public function test_dispatch_throw_is_swallowed_with_class_only_warning(): void
    {
        $seed = $this->seedTelegramWorld();
        config(['queue.default' => self::BROKEN_DRIVER]);
        $this->fakeHttp();

        $response = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame('approved', DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->value('status'), 'dispatch พัง = แถวยัง approved');
        $answers = array_values(array_filter($this->httpRequests, fn (object $request): bool => $this->methodOf($request) === 'answerCallbackQuery'));
        $this->assertCount(1, $answers, 'คำตอบแท็ปต้องออกก่อน dispatch เสมอ');
        $this->assertSame('รับคำสั่งแล้ว กำลังส่งให้ลูกค้า', (string) $answers[0]['text'], 'คำตอบไม่เปลี่ยน');
        $warnings = array_values(array_filter($this->logEvents, fn (MessageLogged $event): bool => $event->level === 'warning'));
        $this->assertCount(1, $warnings, 'warning เดียวต่อ dispatch ที่พัง');
        $this->assertSame([
            'approval_id' => $seed['approval_id'],
            'exception_class' => \InvalidArgumentException::class,
        ], $warnings[0]->context);
        foreach ($this->logEvents as $event) {
            $text = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString(self::BROKEN_DRIVER, $text, 'ห้ามพา message ของ exception ลง log');
        }
    }

    #[Test]
    public function test_sync_tap_reaches_the_customer_in_the_exact_order(): void
    {
        $seed = $this->seedTelegramWorld();
        $this->fakeHttp();

        $response = $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])));

        $response->assertOk()->assertExactJson(['ok' => true]);
        $methods = array_map(fn (object $request): string => $this->methodOf($request), $this->httpRequests);
        $this->assertSame(
            ['answerCallbackQuery', 'editMessageText', 'quota', 'consumption', 'push', 'editMessageText'],
            $methods,
            'ตอบแท็ป → การ์ด ⏳ → โควตา → เผื่อโควตา → push เดียว → การ์ด ✅'
        );
        $this->assertSame(1, $this->hits[self::LINE_PUSH_URL] ?? 0, 'ยิงหาลูกค้าครั้งเดียว');
        $edits = $this->editTexts();
        $this->assertStringEndsWith('⏳ กดส่งแล้ว กำลังส่งให้ลูกค้า', $edits[0]);
        $this->assertStringEndsWith('✅ ส่งให้ลูกค้าแล้ว', $edits[1]);

        $log = DB::table('lead_recovery_logs')->where('id', $seed['log_id'])->first();
        $this->assertSame('sent', $log->delivery_status);
        $this->assertSame('sent', DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->value('status'));
        $message = DB::table('messages')->where('id', (int) $log->message_id)->first();
        $this->assertNotNull($message);
        $this->assertSame(self::TEXT, $message->content);
    }

    // ------------------------------------------------------------------
    // สแกน (step E) เก็บงานค้าง — คิว sync รันทันที
    // ------------------------------------------------------------------

    #[Test]
    public function test_scan_finishes_a_stale_sending_row_with_same_key_and_repaint(): void
    {
        $seed = $this->seedReady([
            'delivery_status' => 'sending',
            'updated_at' => '2026-11-16 09:49:00',
            'first_push_at' => '2026-11-16 09:49:00',
            'message_sent' => self::TEXT,
            'attempt_number' => 1,
            'retry_key' => self::RECOVERY_KEY,
        ], ['status' => 'sending']);
        $this->fakeHttp([['{}', 200, ['x-line-request-id' => 'req-b6e-2']]]);

        $this->runScan();

        $log = $this->logRow($seed);
        $this->assertSame('sent', $log->delivery_status, 'สแกนเดียวจบแถวที่ worker ตายทิ้ง');
        $this->assertSame(self::RECOVERY_KEY, $log->retry_key, 'ยิงรอบสองด้วยคีย์เดิม');
        $this->assertSame('req-b6e-2', $log->line_request_id);
        $this->assertSame('sent', $this->approvalRow($seed)->status);
        $this->assertSame(1, $this->hits[self::LINE_PUSH_URL] ?? 0);
        $edits = $this->editTexts();
        $this->assertCount(1, $edits);
        $this->assertStringEndsWith('✅ ส่งให้ลูกค้าแล้ว', $edits[0]);
        $this->assertSame(1, $this->infoSendJobsQueued(), 'info line แจ้ง send_jobs_queued 1');
    }

    #[Test]
    public function test_scan_sends_quota_refused_row_when_quota_allows(): void
    {
        $seed = $this->seedReady([
            'delivery_status' => 'approved',
            'updated_at' => '2026-11-16 09:57:00',
        ]);
        config(['customer_follow_up.quota_reserve_messages' => 250]);
        $this->fakeHttp();

        $this->runScan();

        $log = $this->logRow($seed);
        $this->assertSame('approved', $log->delivery_status, 'โควตาไม่พอ = คืนที่ approved รอรอบหน้า');
        $this->assertSame('2026-11-16 10:00:00', $log->updated_at);
        $this->assertSame('approved', $this->approvalRow($seed)->status);
        $this->assertSame(0, $this->hits[self::LINE_PUSH_URL] ?? 0);
        $this->assertSame([], $this->telegramRequests(), 'แถวคืน approved คงการ์ด ⏳ เดิม (D102)');
        $this->assertSame(1, $this->infoSendJobsQueued());

        // อีก 15 นาที โควตาว่าง → สแกนส่งต่อให้เอง
        $this->freeze('2026-11-16 10:15:00');
        config(['customer_follow_up.quota_reserve_messages' => 100]);
        $this->runScan();

        $log = $this->logRow($seed);
        $this->assertSame('sent', $log->delivery_status);
        $this->assertSame('sent', $this->approvalRow($seed)->status);
        $this->assertSame(1, $this->hits[self::LINE_PUSH_URL] ?? 0);
        $edits = $this->editTexts();
        $this->assertCount(1, $edits);
        $this->assertStringEndsWith('✅ ส่งให้ลูกค้าแล้ว', $edits[0]);
    }

    // B6e run B: caller-boundary regressions; transport remains faked.

    #[Test]
    public function test_repaint_uses_fresh_terminal_state_not_the_sender_result(): void
    {
        $seed = $this->seedReady();
        Queue::fake();
        $this->fakeHttp();
        $real = app(FollowUpSendService::class);
        $this->mock(FollowUpSendService::class, function (MockInterface $mock) use ($real, $seed): void {
            $mock->shouldReceive('send')->once()->with($seed['approvalId'])->andReturnUsing(function (int $id) use ($real): string {
                $this->assertSame('sent', $real->send($id));

                // A caller must read durable state, not infer it from a result string.
                return 'blocked';
            });
        });
        $this->spyTelegram();

        $this->handleJob($seed['approvalId']);

        $this->assertSame([$seed['approvalId']], $this->repaintIds);
        $this->assertSame('sent', $this->approvalRow($seed)->status);
        $this->assertCount(1, $this->editTexts());
        $this->assertSame([], $this->pushedSendIds());
    }

    #[Test]
    public function test_retry_reaching_its_due_second_is_delayed_at_least_one_second(): void
    {
        $seed = $this->seedReady();
        Queue::fake();
        $this->fakeHttp([['{}', 500]]);
        $real = app(FollowUpSendService::class);
        $this->mock(FollowUpSendService::class, function (MockInterface $mock) use ($real, $seed): void {
            $mock->shouldReceive('send')->once()->with($seed['approvalId'])->andReturnUsing(function (int $id) use ($real): string {
                $result = $real->send($id);
                $this->freeze('2026-11-16 10:01:00');

                return $result;
            });
        });
        $this->spyTelegram();

        $this->handleJob($seed['approvalId']);

        $this->assertSame([$seed['approvalId']], $this->pushedSendIds());
        $this->assertSame(1, Queue::pushedJobs()[SendCustomerFollowUp::class][0]['job']->delay);
        $this->assertSame([], $this->repaintIds);
        $this->assertSame(['quota', 'consumption', 'push'], array_map($this->methodOf(...), $this->httpRequests));
    }

    #[Test]
    public function test_telegram_http_failure_leaves_sent_rows_and_two_scans_do_not_repaint(): void
    {
        $seed = $this->seedReady();
        Queue::fake();
        // Only the repaint transport fails; LINE and the durable send stay real.
        $this->fakeHttp(telegramEditStatus: 500);
        $this->spyTelegram();

        $this->handleJob($seed['approvalId']);

        $this->assertSame('sent', $this->logRow($seed)->delivery_status);
        $this->assertSame('sent', $this->approvalRow($seed)->status);
        $this->assertSame([$seed['approvalId']], $this->repaintIds);
        $this->assertSame([], $this->pushedSendIds());
        $warnings = array_values(array_filter($this->logEvents, fn (MessageLogged $event): bool => $event->level === 'warning'));
        $this->assertSame([], $warnings, 'a returned failed result does not throw or log a job warning');
        $beforeLog = (array) $this->logRow($seed);
        $beforeApproval = (array) $this->approvalRow($seed);
        Http::assertSentCount(4);

        $this->freeze('2026-11-16 10:15:00');
        $this->runScan();
        $this->freeze('2026-11-16 10:30:00');
        $this->runScan();

        Http::assertSentCount(4);
        $this->assertSame([$seed['approvalId']], $this->repaintIds, 'sent rows have no step E recovery actor');
        $this->assertSame([], $this->pushedSendIds());
        $this->assertSame($beforeLog, (array) $this->logRow($seed));
        $this->assertSame($beforeApproval, (array) $this->approvalRow($seed));
    }

    #[Test]
    public function test_sync_500_tap_pushes_once_and_does_not_loop(): void
    {
        $seed = $this->seedTelegramWorld();
        $this->fakeHttp([['{}', 500]]);
        $this->spySend(maximumCalls: 2);
        $this->spyTelegram();

        $this->tap($this->baseCallback(data: $this->cbData('fs', $seed['approval_id'])))->assertOk();

        $this->assertSame([$seed['approval_id'], $seed['approval_id']], $this->sendIds, 'sync executes the delayed job once; claim refuses the future attempt');
        $this->assertSame([$seed['approval_id']], $this->repaintIds, 'only the tap paints the waiting card');
        $this->assertSame(['answerCallbackQuery', 'editMessageText', 'quota', 'consumption', 'push'], array_map($this->methodOf(...), $this->httpRequests));
        $log = DB::table('lead_recovery_logs')->where('id', $seed['log_id'])->first();
        $this->assertSame('retry', $log->delivery_status);
        $this->assertSame(1, (int) $log->attempt_number);
        $this->assertSame('2026-11-16 10:01:00', $log->next_attempt_at);
        $this->assertSame('sending', DB::table('follow_up_approvals')->where('id', $seed['approval_id'])->value('status'));
    }

    #[Test]
    public function test_closed_bot_scan_finishes_pushed_retry_as_acceptance_unknown(): void
    {
        $seed = $this->seedReady([
            'delivery_status' => 'retry',
            'next_attempt_at' => '2026-11-16 10:00:00',
            'updated_at' => '2026-11-16 09:52:00',
            'first_push_at' => '2026-11-16 09:52:00',
            'message_sent' => self::TEXT,
            'attempt_number' => 1,
            'retry_key' => self::RECOVERY_KEY,
        ], ['status' => 'sending']);
        DB::table('bot_settings')->where('bot_id', $seed['bot']->id)->update(['follow_up_enabled' => false]);
        $this->fakeHttp();
        $this->spySend();
        $this->spyTelegram();

        $this->runScan();

        $this->assertSame([$seed['approvalId']], $this->sendIds);
        $this->assertSame([$seed['approvalId']], $this->repaintIds);
        $this->assertSame('send_failed', $this->logRow($seed)->delivery_status);
        $this->assertSame('acceptance_unknown_gate_changed', $this->logRow($seed)->reason_code);
        $this->assertSame('cancelled', $this->approvalRow($seed)->status);
        $this->assertSame(['editMessageText'], array_map($this->methodOf(...), $this->httpRequests));
        $this->assertStringEndsWith('⚠️ ส่งแล้วแต่ยืนยันผลไม่ได้ โปรดเปิดห้องตรวจ', $this->editTexts()[0]);
        $this->assertSame(1, $this->infoSendJobsQueued());
    }

    #[Test]
    public function test_future_retry_claim_refusal_never_queues_or_repaints(): void
    {
        $seed = $this->seedReady([
            'delivery_status' => 'retry',
            'next_attempt_at' => '2026-11-16 10:01:00',
            'updated_at' => '2026-11-16 09:59:00',
        ], ['status' => 'sending', 'updated_at' => '2026-11-16 09:58:00']);
        $beforeLog = (array) $this->logRow($seed);
        $beforeApproval = (array) $this->approvalRow($seed);
        Queue::fake();
        $this->fakeHttp();
        $this->spySend();
        $this->spyTelegram();

        $this->handleJob($seed['approvalId']);

        $this->assertSame([$seed['approvalId']], $this->sendIds);
        $this->assertSame([], $this->repaintIds);
        $this->assertSame([], $this->pushedSendIds());
        $this->assertSame([], $this->httpRequests);
        $this->assertSame($beforeLog, (array) $this->logRow($seed));
        $this->assertSame($beforeApproval, (array) $this->approvalRow($seed));
    }

    #[Test]
    public function test_retry_result_with_null_next_attempt_never_queues(): void
    {
        $seed = $this->seedReady(['next_attempt_at' => null, 'updated_at' => '2026-11-16 09:50:00']);
        $beforeLog = (array) $this->logRow($seed);
        $beforeApproval = (array) $this->approvalRow($seed);
        Queue::fake();
        $this->fakeHttp();
        $this->spyTelegram();
        // Explicit job contract edge: the real sender never returns retry without a due instant.
        $this->mock(FollowUpSendService::class, function (MockInterface $mock) use ($seed): void {
            $mock->shouldReceive('send')->once()->with($seed['approvalId'])->andReturn('retry');
        });

        $this->handleJob($seed['approvalId']);

        $this->assertSame([], $this->pushedSendIds());
        $this->assertSame([], $this->repaintIds);
        $this->assertSame([], $this->httpRequests);
        $this->assertSame($beforeLog, (array) $this->logRow($seed));
        $this->assertSame($beforeApproval, (array) $this->approvalRow($seed));
    }

    #[Test]
    public function test_retry_result_due_now_queues_one_job_with_delay_one(): void
    {
        $seed = $this->seedReady(['next_attempt_at' => self::NOW, 'updated_at' => '2026-11-16 09:50:00']);
        Queue::fake();
        $this->fakeHttp();
        $this->spyTelegram();
        $this->mock(FollowUpSendService::class, function (MockInterface $mock) use ($seed): void {
            $mock->shouldReceive('send')->once()->with($seed['approvalId'])->andReturn('retry');
        });

        $this->handleJob($seed['approvalId']);

        $this->assertSame([$seed['approvalId']], $this->pushedSendIds());
        $this->assertSame(1, Queue::pushedJobs()[SendCustomerFollowUp::class][0]['job']->delay);
        $this->assertSame([], $this->repaintIds);
        $this->assertSame([], $this->httpRequests);
    }

    #[Test]
    #[DataProvider('retryInstantProvider')]
    public function test_retry_delay_uses_signed_remaining_seconds(string $nextAttemptAt, int $expectedDelay): void
    {
        $seed = $this->seedReady([
            'delivery_status' => 'retry',
            'next_attempt_at' => $nextAttemptAt,
            'updated_at' => '2026-11-16 09:50:00',
        ], ['status' => 'sending']);
        $beforeLog = (array) $this->logRow($seed);
        $beforeApproval = (array) $this->approvalRow($seed);
        Queue::fake();
        $this->fakeHttp();
        $this->spyTelegram();
        // Isolate the job's clock edge: a sender pause can leave its retry instant overdue.
        $this->mock(FollowUpSendService::class, function (MockInterface $mock) use ($seed): void {
            $mock->shouldReceive('send')->once()->with($seed['approvalId'])->andReturn('retry');
        });

        $this->handleJob($seed['approvalId']);

        $this->assertSame([$seed['approvalId']], $this->pushedSendIds());
        $this->assertSame($expectedDelay, Queue::pushedJobs()[SendCustomerFollowUp::class][0]['job']->delay);
        $this->assertSame([], $this->repaintIds);
        $this->assertSame([], $this->httpRequests);
        $this->assertSame($beforeLog, (array) $this->logRow($seed));
        $this->assertSame($beforeApproval, (array) $this->approvalRow($seed));
    }

    public static function retryInstantProvider(): array
    {
        return [
            'one minute overdue' => ['2026-11-16 09:59:00', 1],
            'one hour overdue' => ['2026-11-16 09:00:00', 1],
            'one minute remaining' => ['2026-11-16 10:01:00', 60],
        ];
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /** คู่ log approved + บัตร approved ผ่านทุก gate (รูปแบบเดียวกับ SendTest) พร้อมการ์ดบน Telegram */
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
        config(['services.telegram_alert.secret' => $this->webhookSecret]);
        config(['customer_follow_up.quota_reserve_messages' => 100]);

        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        $plugin = FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งเตือน',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'tok-'.'b6e-'.md5((string) $bot->id), 'chat_id' => self::PLUGIN_CHAT, 'authorized_user_ids' => [(string) self::PERSON_ID]],
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
        ]);

        $this->addMessage($room->id, 'user', '2026-11-15 08:55:00', 'สนใจ Nolimit Personal ครับ');
        $this->addMessage($room->id, 'bot', '2026-11-15 08:56:00', 'ต้องการกี่ตัวครับ');
        $this->addMessage($room->id, 'user', '2026-11-15 08:57:00', 'เอา 2 ตัวครับ');
        $this->addMessage($room->id, 'bot', '2026-11-15 08:58:00', 'ได้ครับ กำลังเตรียมสินค้าให้ครับ');
        $anchorId = $this->addMessage($room->id, 'user', '2026-11-15 08:59:00', 'ประเมินราคาให้หน่อยครับ');

        FollowUpSalesProjector::refresh($room, $this->now());

        $logId = $this->insertLog($room, $anchorId, $logOverrides);
        $approvalId = $this->insertApproval($logId, $plugin->id, $approvalOverrides);

        return ['bot' => $bot, 'room' => $room, 'anchorId' => $anchorId, 'logId' => $logId, 'approvalId' => $approvalId];
    }

    private function insertLog(Conversation $room, int $anchorId, array $overrides = []): int
    {
        return (int) DB::table('lead_recovery_logs')->insertGetId(array_merge([
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
            'check_answers' => self::CLEAN_CHECK,
            'owner_action' => 'sent',
            'owner_action_at' => '2026-11-16 09:55:00',
            'owner_telegram_user_id' => (string) self::PERSON_ID,
            'check_overridden' => false,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ], $overrides));
    }

    private function insertApproval(int $logId, int $pluginId, array $overrides = []): int
    {
        return (int) DB::table('follow_up_approvals')->insertGetId(array_merge([
            'lead_recovery_log_id' => $logId,
            'status' => 'approved',
            'revision' => 1,
            'nonce' => self::NONCE,
            'text' => self::TEXT,
            'check_answers' => self::CLEAN_CHECK,
            'check_overridden' => false,
            'expires_at' => '2026-11-16 12:00:00',
            'telegram_plugin_id' => $pluginId,
            'telegram_delivery_state' => 'sent',
            'telegram_chat_id' => self::PLUGIN_CHAT,
            'telegram_message_id' => 77,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ], $overrides));
    }

    /** โลกแท็ป (รูปแบบเดียวกับ TelegramApprovalTest): บัตร pending พร้อมการ์ดบน Telegram */
    private function seedTelegramWorld(array $options = []): array
    {
        $get = fn (string $key, mixed $default = null) => array_key_exists($key, $options) ? $options[$key] : $default;

        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'name' => 'Line - Adsvance',
            'primary_chat_model' => 'openai/gpt-6-luna',
            'channel_access_token' => $this->token(),
        ]);
        $this->worldToken = 'tg-world-'.'b6e-'.md5((string) $bot->id);

        config(['customer_follow_up.bot_ids' => [$bot->id]]);
        config(['customer_follow_up.automatic_bot_ids' => []]);
        config(['customer_follow_up.admin_web_url' => 'https://admin.example.test']);
        config(['services.telegram_alert.secret' => $this->webhookSecret]);
        config(['customer_follow_up.quota_reserve_messages' => 100]);

        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        $plugin = FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งเตือน',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => [
                'access_token' => $this->worldToken,
                'chat_id' => self::PLUGIN_CHAT,
                'authorized_user_ids' => [(string) self::PERSON_ID],
            ],
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

        $profile = CustomerProfile::factory()->create([
            'channel_type' => 'line',
            'display_name' => 'สมชาย <ร้าน A&B>',
        ]);
        $room = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $profile->id,
            'external_customer_id' => self::RECIPIENT,
            'status' => 'active',
        ]);

        $this->addMessage($room->id, 'user', '2026-11-15 08:55:00', 'สนใจ Nolimit Personal ครับ');
        $this->addMessage($room->id, 'bot', '2026-11-15 08:56:00', 'ต้องการกี่ตัวครับ');
        $this->addMessage($room->id, 'user', '2026-11-15 08:57:00', 'เอา 2 ตัวครับ');
        $this->addMessage($room->id, 'bot', '2026-11-15 08:58:00', 'ได้ครับ กำลังเตรียมสินค้าให้ครับ');
        $anchorId = $this->addMessage($room->id, 'user', '2026-11-15 08:59:00', 'ประเมินราคาให้หน่อยครับ');

        FollowUpSalesProjector::refresh($room, $this->now());

        $logId = (int) DB::table('lead_recovery_logs')->insertGetId([
            'conversation_id' => $room->id,
            'bot_id' => $bot->id,
            'attempt_number' => 0,
            'attempt_count' => 0,
            'message_mode' => 'ai',
            'delivery_status' => 'pending',
            'silence_user_message_id' => $anchorId,
            'observed_last_message_id' => $anchorId,
            'customer_key' => hash('sha256', $bot->id.'|line|'.$room->external_customer_id),
            'draft' => self::TEXT,
            'drafted_at' => '2026-11-15 09:30:00',
            'draft_day' => '2026-11-15',
            'draft_model' => 'openai/gpt-6-luna',
            'check_answers' => $get('check_answers', self::CLEAN_CHECK),
            'created_at' => '2026-11-15 09:35:00',
            'updated_at' => '2026-11-15 09:35:00',
        ]);

        $approvalId = (int) DB::table('follow_up_approvals')->insertGetId([
            'lead_recovery_log_id' => $logId,
            'status' => 'pending',
            'revision' => $get('revision', 1),
            'nonce' => self::NONCE,
            'text' => $get('approval_text', self::TEXT),
            'check_answers' => $get('check_answers', self::CLEAN_CHECK),
            'check_overridden' => false,
            'expires_at' => $get('expires_at', '2026-11-16 22:00:00'),
            'telegram_plugin_id' => $plugin->id,
            'telegram_chat_id' => self::PLUGIN_CHAT,
            'telegram_message_id' => 77,
            'telegram_delivery_state' => 'sent',
            'last_update_id' => null,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return [
            'bot' => $bot->refresh(),
            'room' => $room->refresh(),
            'plugin_id' => (int) $plugin->id,
            'log_id' => $logId,
            'approval_id' => $approvalId,
            'anchor_id' => $anchorId,
        ];
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

    // ------------------------------------------------------------------
    // HTTP fake + spies + helpers
    // ------------------------------------------------------------------

    /**
     * fake ครบทั้ง LINE (quota/consumption/push ตามลำดับคำตอบ $push) และ Telegram
     * (ok:true, sendMessage คืน message id 55 ขึ้นไปเมื่อต้องจำ) — จับทุก request ไว้ตรวจ
     *
     * @param  list<array{0: string, 1: int, 2?: array}|string>  $push
     */
    private function fakeHttp(array $push = [], int $telegramEditStatus = 200): void
    {
        $push = $push === [] ? [['{}', 200, ['x-line-request-id' => self::REQUEST_ID]]] : $push;
        $pushIndex = 0;
        $this->hits = [];
        $this->pushes = [];
        $this->httpRequests = [];
        $this->queuedAtCardEdit = null;
        $nextMessageId = 55;

        Http::fake(function ($request) use ($push, &$pushIndex, &$nextMessageId, $telegramEditStatus) {
            $url = (string) $request->url();
            $this->httpRequests[] = $request;
            $this->hits[$url] = ($this->hits[$url] ?? 0) + 1;

            if ($url === self::LINE_QUOTA_URL) {
                return Http::response(json_encode(['type' => 'limited', 'value' => 200]), 200);
            }
            if ($url === self::LINE_CONSUMPTION_URL) {
                return Http::response(json_encode(['totalUsage' => 10]), 200);
            }
            if ($url === self::LINE_PUSH_URL) {
                $entry = $push[min($pushIndex, count($push) - 1)];
                $pushIndex++;
                $this->pushes[] = [
                    $request->hasHeader('X-Line-Retry-Key') ? $request->header('X-Line-Retry-Key')[0] : '',
                    $request->body(),
                ];

                if ($entry === 'timeout') {
                    throw new ConnectionException('cURL error 28 '.'b6e');
                }

                return Http::response($entry[0], $entry[1], $entry[2] ?? []);
            }

            if ($this->methodOf($request) === 'sendMessage') {
                return Http::response(json_encode(['ok' => true, 'result' => ['message_id' => $nextMessageId++]]), 200);
            }
            if ($this->methodOf($request) === 'editMessageText') {
                $text = (string) $request['text'];
                if (str_contains($text, '⏳ กดส่งแล้ว')) {
                    $this->queuedAtCardEdit = Queue::pushedJobs()[SendCustomerFollowUp::class] ?? [];
                }
                if ($telegramEditStatus !== 200) {
                    return Http::response(['ok' => false], $telegramEditStatus);
                }
            }

            return Http::response(json_encode(['ok' => true, 'result' => true]), 200);
        });
    }

    /** spy ตัวส่ง — จับ id ที่ผ่านแล้วส่งต่อให้ของจริง */
    private function spySend(?int $maximumCalls = null): void
    {
        $real = app(FollowUpSendService::class);
        $this->mock(FollowUpSendService::class, function (MockInterface $mock) use ($real, $maximumCalls): void {
            $mock->shouldReceive('send')->andReturnUsing(function (int $approvalId, ?CarbonImmutable $now = null) use ($real, $maximumCalls): string {
                $this->sendIds[] = $approvalId;
                if ($maximumCalls !== null && count($this->sendIds) > $maximumCalls) {
                    throw new RuntimeException('unexpected send loop');
                }

                return $real->send($approvalId, $now);
            });
        });
    }

    /** spy ตัวการ์ด — จับ id ที่ผ่านแล้วส่งต่อให้ของจริง */
    private function spyTelegram(): void
    {
        $real = app(FollowUpTelegramService::class);
        $this->mock(FollowUpTelegramService::class, function (MockInterface $mock) use ($real): void {
            $mock->shouldReceive('refreshCard')->andReturnUsing(function (int $approvalId, ?CarbonImmutable $now = null) use ($real): string {
                $this->repaintIds[] = $approvalId;

                return $real->refreshCard($approvalId, $now);
            });
        });
    }

    /** รัน job ผ่าน container (เจอ mock ที่ผูกไว้เอง) */
    private function handleJob(int $approvalId): void
    {
        (new SendCustomerFollowUp($approvalId))->handle(
            app(FollowUpSendService::class),
            app(FollowUpTelegramService::class),
        );
    }

    /** @return list<int> approval id ของงาน SendCustomerFollowUp ที่ถูก push ตามจังหวะ */
    private function pushedSendIds(): array
    {
        return array_map(
            fn (array $pushed): int => $pushed['job']->approvalId,
            Queue::pushedJobs()[SendCustomerFollowUp::class] ?? [],
        );
    }

    private function runScan(): void
    {
        (new ProcessCustomerFollowUps)->handle(
            app(FollowUpReservationService::class),
            app(FollowUpTelegramService::class),
        );
    }

    /** ค่า send_jobs_queued ของ info line สแกนล่าสุด (หรือ -1 เมื่อไม่มี) */
    private function infoSendJobsQueued(): int
    {
        foreach (array_reverse($this->logEvents) as $event) {
            if ($event->level === 'info' && $event->message === 'Follow-up scan') {
                return (int) ($event->context['send_jobs_queued'] ?? -1);
            }
        }

        return -1;
    }

    /** @return list<object> request ที่ยิงหา Telegram */
    private function telegramRequests(): array
    {
        return array_values(array_filter(
            $this->httpRequests,
            fn (object $request): bool => str_contains((string) parse_url((string) $request->url(), PHP_URL_HOST), 'telegram.org'),
        ));
    }

    /** @return list<string> ข้อความของ editMessageText ทุกครั้ง ตามจังหวะ */
    private function editTexts(): array
    {
        return array_map(
            fn (object $request): string => (string) $request['text'],
            array_values(array_filter(
                $this->telegramRequests(),
                fn (object $request): bool => $this->methodOf($request) === 'editMessageText',
            )),
        );
    }

    private function methodOf(object $request): string
    {
        $path = (string) parse_url((string) $request->url(), PHP_URL_PATH);

        return substr($path, (int) strrpos($path, '/') + 1);
    }

    private function tap(array $callback, ?int $updateId = self::UPDATE_ID): TestResponse
    {
        $body = ['callback_query' => $callback];
        if ($updateId !== null) {
            $body['update_id'] = $updateId;
        }

        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => $this->webhookSecret])
            ->postJson('/api/webhook/telegram-alert/'.($this->worldToken ?? ''), $body);
    }

    private function fireEditReply(int $promptMessageId, string $text, int $updateId): TestResponse
    {
        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => $this->webhookSecret])
            ->postJson('/api/webhook/telegram-alert/'.($this->worldToken ?? ''), [
                'message' => [
                    'message_id' => 900,
                    'from' => ['id' => self::PERSON_ID, 'first_name' => 'เจ้านาย'],
                    'chat' => ['id' => (int) self::PLUGIN_CHAT, 'type' => 'private'],
                    'date' => 1700000001,
                    'reply_to_message' => [
                        'message_id' => $promptMessageId,
                        'date' => 1700000000,
                        'chat' => ['id' => (int) self::PLUGIN_CHAT, 'type' => 'private'],
                        'from' => ['id' => 777000, 'is_bot' => true, 'first_name' => 'Luna Bot'],
                        'text' => "✏️ แก้ข้อความ #123\nตอบกลับ (Reply) ข้อความนี้ด้วยข้อความใหม่ที่จะส่งให้ลูกค้า",
                    ],
                    'text' => $text,
                ],
                'update_id' => $updateId,
            ]);
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
            'message' => ['message_id' => 77, 'chat' => ['id' => (int) self::PLUGIN_CHAT]],
            'data' => $data,
        ], $overrides);
    }

    private function logRow(array $seed): object
    {
        return DB::table('lead_recovery_logs')->where('id', $seed['logId'])->first();
    }

    private function approvalRow(array $seed): object
    {
        return DB::table('follow_up_approvals')->where('id', $seed['approvalId'])->first();
    }

    private function token(): string
    {
        return implode('', ['fake', 'Line', 'Token', 'B6e', '777']);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW, 'Asia/Bangkok');
    }

    private function freeze(string $when): void
    {
        Carbon::setTestNow(CarbonImmutable::parse($when, 'Asia/Bangkok'));
    }
}
