<?php

namespace Tests\Feature\FollowUp;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\LeadRecoveryLog;
use App\Models\User;
use App\Services\FollowUp\FollowUpOutcomeService;
use App\Services\LeadRecoveryService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * การ์ด B4d — ตัวนับผลหลังส่งข้อความตามลูกค้า (D56-D59): FollowUpOutcomeService
 *
 * - ตอบกลับภายใน 72 ชม. นับจาก created_at ของข้อความลูกค้า ไม่ใช่เวลาที่มาเรียก
 * - ออเดอร์ภายใน 7 วัน นับจาก created_at ของออเดอร์ (เฉพาะ status completed)
 * - หนึ่งเหตุการณ์นับให้แถวเดียว: แถว sent ล่าสุดก่อนเหตุการณ์ (D56)
 * - outcome เป็น null จนกว่าจะตัดสิน แล้วไม่ถูกเขียนทับอีก (D58)
 * - markCustomerResponded มีผู้เขียนเดียว: recordReply ไม่มีสิทธิ์เขียน false และไม่แตะคอลัมน์ออเดอร์ (D57)
 * - ทุกเวลาเป็น Bangkok Y-m-d H:i:s ไม่มีการแปลง UTC
 */
class OutcomeTest extends TestCase
{
    use RefreshDatabase;

    /** จุดอ้างอิง T — แถวถูกส่ง ณ วันจันทร์ 16 พ.ย. 2026 10:00 กรุงเทพฯ */
    private const T = '2026-11-16 10:00:00';

    private const T_PLUS_1H = '2026-11-16 11:00:00';

    private const T_PLUS_1D = '2026-11-17 10:00:00';

    private const T_PLUS_72H = '2026-11-19 10:00:00';

    /** T + 72h + 600s — เพดานปิดหน้าต่างตอบกลับแบบพรีซีส */
    private const T_REPLY_CLOSE = '2026-11-19 10:10:00';

    private const T_PLUS_7D = '2026-11-23 10:00:00';

    /** T + 7d + 600s — เพดานปิดหน้าต่างออเดอร์แบบพรีซีส */
    private const T_ORDER_CLOSE = '2026-11-23 10:10:00';

    /** @var list<MessageLogged> */
    private array $logLines = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Http::preventStrayRequests();

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logLines[] = $event;
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Carbon::setTestNow();
        Mockery::close();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function at(string $localBangkok): CarbonImmutable
    {
        return CarbonImmutable::parse($localBangkok, 'Asia/Bangkok');
    }

    private function freezeAt(string $localBangkok): void
    {
        Carbon::setTestNow($this->at($localBangkok));
    }

    private function newBot(): Bot
    {
        $user = User::factory()->owner()->create();

        return Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'channel_access_token' => implode('', ['fake', 'Outcome', 'Line', 'Token', '42']),
        ]);
    }

    private function room(Bot $bot, ?string $externalCustomerId, string $channelType = 'line'): Conversation
    {
        // external_customer_id ในตารางเป็น NOT NULL — ห้องไร้ตัวตนลูกค้า = ค่าว่าง ('')
        return Conversation::factory()->create([
            'bot_id' => $bot->id,
            'channel_type' => $channelType,
            'external_customer_id' => $externalCustomerId ?? '',
        ]);
    }

    private function sentRow(Conversation $room, string $sentAt, array $overrides = []): LeadRecoveryLog
    {
        return LeadRecoveryLog::create(array_merge([
            'conversation_id' => $room->id,
            'bot_id' => $room->bot_id,
            'attempt_number' => 0,
            'attempt_count' => 0,
            'message_mode' => 'ai',
            'message_sent' => 'synthetic follow-up text',
            'sent_at' => $sentAt,
            'delivery_status' => 'sent',
            'customer_responded' => false,
            'created_at' => $sentAt,
            'updated_at' => $sentAt,
        ], $overrides));
    }

    private function addMessage(int $conversationId, string $at, string $sender = 'user', string $type = 'text'): int
    {
        return DB::table('messages')->insertGetId([
            'conversation_id' => $conversationId,
            'sender' => $sender,
            'content' => 'synthetic customer message',
            'type' => $type,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function addOrder(Conversation $room, string $at, string $status = 'completed'): int
    {
        return DB::table('orders')->insertGetId([
            'bot_id' => $room->bot_id,
            'conversation_id' => $room->id,
            'total_amount' => 100.00,
            'status' => $status,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function rowById(int $logId): object
    {
        return (array) DB::table('lead_recovery_logs')->where('id', $logId)->first();
    }

    private function snapshotRow(int $logId): array
    {
        return (array) DB::table('lead_recovery_logs')->where('id', $logId)->first();
    }

    /** outcome columns บนแถว อ่านแบบดิบเทียบสตริงเวลาแบบกรุงเทพฯ */
    private function outcomes(int $logId): object
    {
        return DB::table('lead_recovery_logs')
            ->where('id', $logId)
            ->first([
                'replied_within_72h',
                'response_window_closed_at',
                'ordered_within_7d',
                'order_id',
                'ordered_at',
                'order_window_closed_at',
                'customer_responded',
                'responded_at',
            ]);
    }

    private function markResponded(Conversation $room): void
    {
        app(LeadRecoveryService::class)->markCustomerResponded($room);
    }

    private function service(): FollowUpOutcomeService
    {
        return app(FollowUpOutcomeService::class);
    }

    // ------------------------------------------------------------------
    // Baseline
    // ------------------------------------------------------------------

    #[Test]
    public function test_baseline_reply_and_order_via_record_reply_mark_customer_responded_and_reconcile(): void
    {
        // (ก) recordReply — ตอบกลับ true, คอลัมน์ออเดอร์ทั้งสี่ยัง null
        $botA = $this->newBot();
        $roomA = $this->room($botA, 'Ua1b2c3d4e5f60718293a4b5c6d7e8f90');
        $rowA = $this->sentRow($roomA, self::T);
        $this->addMessage($roomA->id, self::T_PLUS_1H);

        $this->service()->recordReply($roomA, $this->at('2026-11-16 11:00:05'));

        $a = $this->outcomes($rowA->id);
        $this->assertTrue((bool) $a->replied_within_72h);
        $this->assertSame(self::T_PLUS_1H, (string) $a->responded_at);
        $this->assertSame('2026-11-16 11:00:05', (string) $a->response_window_closed_at);
        $this->assertSame(1, (int) $a->customer_responded);
        $this->assertNull($a->ordered_within_7d);
        $this->assertNull($a->order_id);
        $this->assertNull($a->ordered_at);
        $this->assertNull($a->order_window_closed_at);

        // (ข) markCustomerResponded — ผลเดียวกันผ่านทาง customer-message path
        $botB = $this->newBot();
        $roomB = $this->room($botB, 'Ub1b2c3d4e5f60718293a4b5c6d7e8f90');
        $rowB = $this->sentRow($roomB, self::T);
        $this->addMessage($roomB->id, self::T_PLUS_1H);

        $this->freezeAt('2026-11-16 11:00:05');
        $this->markResponded($roomB);

        $b = $this->outcomes($rowB->id);
        $this->assertTrue((bool) $b->replied_within_72h);
        $this->assertSame(self::T_PLUS_1H, (string) $b->responded_at);
        $this->assertSame('2026-11-16 11:00:05', (string) $b->response_window_closed_at);
        $this->assertSame(1, (int) $b->customer_responded);
        $this->assertNull($b->ordered_within_7d);
        $this->assertNull($b->order_id);
        $this->assertNull($b->ordered_at);
        $this->assertNull($b->order_window_closed_at);

        // (ค) reconcile — ตอบกลับ true พร้อมกันกับออเดอร์ true ใน call เดียว
        $botC = $this->newBot();
        $roomC = $this->room($botC, 'Uc1b2c3d4e5f60718293a4b5c6d7e8f90');
        $rowC = $this->sentRow($roomC, self::T);
        $this->addMessage($roomC->id, self::T_PLUS_1H);
        $orderId = $this->addOrder($roomC, self::T_PLUS_1D);

        $this->service()->reconcile($this->at('2026-11-16 11:05:00'));

        $c = $this->outcomes($rowC->id);
        $this->assertTrue((bool) $c->replied_within_72h);
        $this->assertSame(self::T_PLUS_1H, (string) $c->responded_at);
        $this->assertSame('2026-11-16 11:05:00', (string) $c->response_window_closed_at);
        $this->assertTrue((bool) $c->ordered_within_7d);
        $this->assertSame($orderId, (int) $c->order_id);
        $this->assertSame(self::T_PLUS_1D, (string) $c->ordered_at);
        $this->assertSame('2026-11-16 11:05:00', (string) $c->order_window_closed_at);
    }

    // ------------------------------------------------------------------
    // หน้าต่างตอบกลับ — ขอบเขตเวลา ผู้ส่ง ประเภทข้อความ
    // ------------------------------------------------------------------

    /** กรณีตอบกลับ แต่ละกรณี = baseline ที่เปลี่ยน "สิ่งเดียว" (คืออะไรดูชื่อ data set) */
    public static function replyWindowCases(): array
    {
        return [
            'message at exactly sent_at' => [self::T, 'user', 'text', false],
            'message at T+72h 0s exactly (inclusive end)' => [self::T_PLUS_72H, 'user', 'text', true],
            'message at T+72h 1s (past the end)' => ['2026-11-19 10:00:01', 'user', 'text', false],
            'sender bot instead of user' => [self::T_PLUS_1H, 'bot', 'text', false],
            'sender agent instead of user' => [self::T_PLUS_1H, 'agent', 'text', false],
            'type image instead of text' => [self::T_PLUS_1H, 'user', 'image', true],
            'type sticker instead of text' => [self::T_PLUS_1H, 'user', 'sticker', true],
        ];
    }

    #[Test]
    #[DataProvider('replyWindowCases')]
    public function test_reply_window_boundaries_senders_and_types_via_reconcile(
        string $messageAt,
        string $sender,
        string $type,
        bool $expectReply,
    ): void {
        $bot = $this->newBot();
        $room = $this->room($bot, 'Ud1b2c3d4e5f60718293a4b5c6d7e8f90');
        $row = $this->sentRow($room, self::T);
        $this->addMessage($room->id, $messageAt, $sender, $type);

        $this->service()->reconcile($this->at(self::T_REPLY_CLOSE));

        $o = $this->outcomes($row->id);

        if ($expectReply) {
            $this->assertTrue((bool) $o->replied_within_72h, 'ต้องนับเป็นการตอบกลับ: '.$messageAt);
            $this->assertSame($messageAt, (string) $o->responded_at);
            $this->assertSame(self::T_REPLY_CLOSE, (string) $o->response_window_closed_at);
            $this->assertSame(1, (int) $o->customer_responded);
        } else {
            $this->assertNotNull($o->replied_within_72h, 'ต้องปิดผลแล้ว (false) ไม่ใช่ค้าง null: '.$messageAt);
            $this->assertFalse((bool) $o->replied_within_72h);
            $this->assertSame(self::T_REPLY_CLOSE, (string) $o->response_window_closed_at);
            $this->assertSame(0, (int) $o->customer_responded);
            $this->assertNull($o->responded_at);
        }
    }

    #[Test]
    #[DataProvider('replyWindowCases')]
    public function test_reply_window_boundaries_senders_and_types_via_mark_customer_responded(
        string $messageAt,
        string $sender,
        string $type,
        bool $expectReply,
    ): void {
        $bot = $this->newBot();
        $room = $this->room($bot, 'Ue1b2c3d4e5f60718293a4b5c6d7e8f90');
        $row = $this->sentRow($room, self::T);
        $this->addMessage($room->id, $messageAt, $sender, $type);

        $this->freezeAt('2026-11-19 10:00:05');
        $this->markResponded($room);

        $o = $this->outcomes($row->id);

        if ($expectReply) {
            $this->assertTrue((bool) $o->replied_within_72h);
            $this->assertSame(1, (int) $o->customer_responded);
            $this->assertSame($messageAt, (string) $o->responded_at);
        } else {
            // markCustomerResponded ไม่มีสิทธิ์เขียน false — null คงอยู่ ลูกค้ายังไม่นับว่าตอบ
            $this->assertNull($o->replied_within_72h);
            $this->assertNull($o->response_window_closed_at);
            $this->assertSame(0, (int) $o->customer_responded);
            $this->assertNull($o->responded_at);
        }

        $this->assertNull($o->ordered_within_7d);
        $this->assertNull($o->order_id);
        $this->assertNull($o->ordered_at);
        $this->assertNull($o->order_window_closed_at);
    }

    // ------------------------------------------------------------------
    // ขอบเขต "ลูกค้า" — ห้อง/บอท/ช่องทาง (D56)
    // ------------------------------------------------------------------

    public static function customerScopeCases(): array
    {
        return [
            'reply in another room of the same customer' => ['sibling-room', true],
            'same external_customer_id on another bot' => ['other-bot', false],
            'another customer of the same bot' => ['other-customer', false],
            'same external_customer_id with another channel_type' => ['other-channel', false],
            'null-id room: its own message' => ['own-null-room', true],
            'null-id room: message of another null-id room' => ['other-null-room', false],
        ];
    }

    /** เตรียมบอท/ห้องตามกรณี คืน [แถว sent หลัก, ห้องที่ข้อความตอบกลับลง] */
    private function seedScopeCase(string $case): array
    {
        $bot = $this->newBot();
        $mainExternal = 'Uf1b2c3d4e5f60718293a4b5c6d7e8f90';

        [$mainRoom, $replyRoom] = match ($case) {
            'sibling-room' => [
                $this->room($bot, $mainExternal),
                $this->room($bot, $mainExternal),
            ],
            'other-bot' => [
                $this->room($bot, $mainExternal),
                $this->room($this->newBot(), $mainExternal),
            ],
            'other-customer' => [
                $this->room($bot, $mainExternal),
                $this->room($bot, 'U0f2b2c3d4e5f60718293a4b5c6d7e8f91'),
            ],
            'other-channel' => [
                $this->room($bot, $mainExternal),
                $this->room($bot, $mainExternal, 'facebook'),
            ],
            'own-null-room' => [
                $this->room($bot, null),
                null, // เติมด้านล่าง — ไร้ตัวตนลูกค้า = ขอบเขตเป็นห้องตัวเองห้องเดียว
            ],
            'other-null-room' => [
                $this->room($bot, null),
                null, // เติมด้านล่าง — ห้องที่สองต้องไม่มองเห็นกัน
            ],
        };

        $row = $this->sentRow($mainRoom, self::T);

        if ($replyRoom === null) {
            // own-null-room: ไร้ตัวตนลูกค้า = ข้อความต้องนับเฉพาะที่ลงห้องตัวเอง
            $replyRoom = $case === 'own-null-room' ? $mainRoom : $this->room($bot, null);
        }

        return [$row, $mainRoom, $replyRoom];
    }

    #[Test]
    #[DataProvider('customerScopeCases')]
    public function test_customer_scope_across_rooms_bots_and_channels_via_reconcile(string $case, bool $expectReply): void
    {
        [$row, , $replyRoom] = $this->seedScopeCase($case);
        $this->addMessage($replyRoom->id, self::T_PLUS_1H);

        $this->service()->reconcile($this->at('2026-11-16 11:05:00'));

        $o = $this->outcomes($row->id);

        if ($expectReply) {
            $this->assertTrue((bool) $o->replied_within_72h, $case);
            $this->assertSame(self::T_PLUS_1H, (string) $o->responded_at);
        } else {
            $this->assertNull($o->replied_within_72h, $case);
            $this->assertNull($o->responded_at);
        }
    }

    #[Test]
    #[DataProvider('customerScopeCases')]
    public function test_customer_scope_across_rooms_bots_and_channels_via_mark_customer_responded(string $case, bool $expectReply): void
    {
        [$row, , $replyRoom] = $this->seedScopeCase($case);
        $this->addMessage($replyRoom->id, self::T_PLUS_1H);

        $this->freezeAt('2026-11-16 11:05:00');
        // webhook จริงเรียกด้วยห้องที่ข้อความตอบกลับลง
        $this->markResponded($replyRoom);

        $o = $this->outcomes($row->id);

        if ($expectReply) {
            $this->assertTrue((bool) $o->replied_within_72h, $case);
            $this->assertSame(1, (int) $o->customer_responded);
            $this->assertSame(self::T_PLUS_1H, (string) $o->responded_at);
        } else {
            $this->assertNull($o->replied_within_72h, $case);
            $this->assertSame(0, (int) $o->customer_responded);
        }
    }

    // ------------------------------------------------------------------
    // ตัวกรองแถว — เฉพาะ delivery_status sent + sent_at ไม่ null เท่านั้น
    // ------------------------------------------------------------------

    public static function excludedRowCases(): array
    {
        return [
            'status send_failed instead of sent' => ['send_failed', self::T],
            'status retry instead of sent' => ['retry', self::T],
            'status sending instead of sent' => ['sending', self::T],
            'status approved instead of sent' => ['approved', self::T],
            'status cancelled instead of sent' => ['cancelled', self::T],
            'status expired instead of sent' => ['expired', self::T],
            'status pending instead of sent' => ['pending', self::T],
            'status sent but sent_at null' => ['sent', null],
        ];
    }

    #[Test]
    #[DataProvider('excludedRowCases')]
    public function test_non_sent_rows_and_null_sent_at_never_change(string $status, ?string $sentAt): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U01b2c3d4e5f60718293a4b5c6d7e8f90');
        $row = $this->sentRow($room, self::T, [
            'delivery_status' => $status,
            'sent_at' => $sentAt,
        ]);
        $this->addMessage($room->id, self::T_PLUS_1H);
        $before = $this->snapshotRow($row->id);

        $this->service()->reconcile($this->at('2026-11-16 11:05:00'));
        $this->freezeAt('2026-11-16 11:05:00');
        $this->markResponded($room);

        $this->assertSame($before, $this->snapshotRow($row->id), 'แถวที่ไม่ใช่ sent ต้องไม่เปลี่ยนแม้คอลัมน์เดียว');
    }

    // ------------------------------------------------------------------
    // เร็วสุดชนะ — responded_at / order แรกสุด
    // ------------------------------------------------------------------

    #[Test]
    public function test_earliest_reply_wins_and_earliest_order_wins(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U02b2c3d4e5f60718293a4b5c6d7e8f90');
        $row = $this->sentRow($room, self::T);

        // ใส่ข้อความหลังก่อน (T+2h) แล้วค่อยใส่ข้อความเร็วกว่า (T+1h)
        $this->addMessage($room->id, '2026-11-16 12:00:00');
        $this->addMessage($room->id, '2026-11-16 11:00:00');

        // ออเดอร์เช่นเดียวกัน + สามใบ created_at เท่ากัน ตัดสินด้วย id ต่ำสุด
        $this->addOrder($room, '2026-11-17 12:00:00');
        $earlyOrderId = $this->addOrder($room, '2026-11-17 11:00:00');
        $this->addOrder($room, '2026-11-17 11:00:00');
        $this->addOrder($room, '2026-11-17 11:00:00');

        $this->service()->reconcile($this->at('2026-11-16 13:00:00'));

        $o = $this->outcomes($row->id);
        $this->assertSame('2026-11-16 11:00:00', (string) $o->responded_at, 'responded_at ต้องเป็นข้อความแรกสุด');
        $this->assertTrue((bool) $o->ordered_within_7d);
        $this->assertSame($earlyOrderId, (int) $o->order_id, 'order ต้องเป็นใบแรกสุด (created_at แล้ว id ต่ำสุด)');
        $this->assertSame('2026-11-17 11:00:00', (string) $o->ordered_at);
        // สามใบ created_at เท่ากัน — ผู้ชนะต้องเป็น id ต่ำสุดของกลุ่ม
        $lowestTieId = (int) DB::table('orders')->where('created_at', '2026-11-17 11:00:00')->min('id');
        $this->assertSame($lowestTieId, (int) $o->order_id, 'created_at เท่ากันตัดสินด้วย id ต่ำสุด');
    }

    // ------------------------------------------------------------------
    // ค้างรอและปิดผล (reconcile เท่านั้นที่เขียน false)
    // ------------------------------------------------------------------

    #[Test]
    public function test_reply_pending_then_closes_false_and_record_reply_never_closes(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U03b2c3d4e5f60718293a4b5c6d7e8f90');
        $row = $this->sentRow($room, self::T);

        // 1 วินาทีก่อนเพดาน (T+72h+599s) — ยัง null
        $this->service()->reconcile($this->at('2026-11-19 10:09:59'));
        $o = $this->outcomes($row->id);
        $this->assertNull($o->replied_within_72h);
        $this->assertNull($o->response_window_closed_at);

        // ถึงเพดานพอดี (T+72h+600s) — false + closed_at = now
        $this->service()->reconcile($this->at(self::T_REPLY_CLOSE));
        $o = $this->outcomes($row->id);
        $this->assertNotNull($o->replied_within_72h);
        $this->assertFalse((bool) $o->replied_within_72h);
        $this->assertSame(self::T_REPLY_CLOSE, (string) $o->response_window_closed_at);
        $this->assertSame(0, (int) $o->customer_responded);
        $this->assertNull($o->responded_at);

        // recordReply หลังหน้าต่าง — ไม่เขียนอะไรเลย
        $before = $this->snapshotRow($row->id);
        $this->freezeAt('2026-11-19 20:00:00');
        $this->markResponded($room);
        $this->assertSame($before, $this->snapshotRow($row->id), 'recordReply ห้ามเขียน false หรือปิดหน้าต่าง');
    }

    #[Test]
    public function test_order_pending_then_closes_false(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U04b2c3d4e5f60718293a4b5c6d7e8f90');
        $row = $this->sentRow($room, self::T);

        $this->service()->reconcile($this->at('2026-11-23 10:09:59'));
        $o = $this->outcomes($row->id);
        $this->assertNull($o->ordered_within_7d);
        $this->assertNull($o->order_window_closed_at);
        $this->assertNull($o->order_id);
        $this->assertNull($o->ordered_at);

        $this->service()->reconcile($this->at(self::T_ORDER_CLOSE));
        $o = $this->outcomes($row->id);
        $this->assertNotNull($o->ordered_within_7d);
        $this->assertFalse((bool) $o->ordered_within_7d);
        $this->assertSame(self::T_ORDER_CLOSE, (string) $o->order_window_closed_at);
        $this->assertNull($o->order_id);
        $this->assertNull($o->ordered_at);
    }

    // ------------------------------------------------------------------
    // มือถือส่งช้า — หน้าต่างวัดจาก created_at ไม่ใช่เวลาที่มาเรียก (probe 2)
    // ------------------------------------------------------------------

    #[Test]
    public function test_late_call_after_72h_still_records_reply_sent_inside_its_own_window(): void
    {
        // (ก) markCustomerResponded ที่ T+72h+2s — ข้อความเข้าเมื่อ T+71:59:59 ต้องถูกนับ
        $bot = $this->newBot();
        $room = $this->room($bot, 'U05b2c3d4e5f60718293a4b5c6d7e8f90');
        $row = $this->sentRow($room, self::T);
        $this->addMessage($room->id, '2026-11-19 09:59:59');

        $this->freezeAt('2026-11-19 10:00:02');
        $this->markResponded($room);

        $o = $this->outcomes($row->id);
        $this->assertTrue((bool) $o->replied_within_72h, 'หน้าต่างวัดจาก created_at ไม่ใช่เวลาที่มาเรียก');
        $this->assertSame('2026-11-19 09:59:59', (string) $o->responded_at);
        $this->assertSame(1, (int) $o->customer_responded);

        // (ข) reconcile ที่เวลาเดียวกัน — ผลเดียวกัน
        $bot2 = $this->newBot();
        $room2 = $this->room($bot2, 'U06b2c3d4e5f60718293a4b5c6d7e8f90');
        $row2 = $this->sentRow($room2, self::T);
        $this->addMessage($room2->id, '2026-11-19 09:59:59');

        $this->service()->reconcile($this->at('2026-11-19 10:00:02'));

        $o2 = $this->outcomes($row2->id);
        $this->assertTrue((bool) $o2->replied_within_72h);
        $this->assertSame('2026-11-19 09:59:59', (string) $o2->responded_at);
    }

    // ------------------------------------------------------------------
    // ตัดสินแล้วไม่เขียนทับ (D58)
    // ------------------------------------------------------------------

    #[Test]
    public function test_reply_false_stays_false_when_a_message_lands_after_the_close(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U07b2c3d4e5f60718293a4b5c6d7e8f90');
        $row = $this->sentRow($room, self::T);

        $this->service()->reconcile($this->at(self::T_REPLY_CLOSE));
        $this->assertFalse((bool) $this->outcomes($row->id)->replied_within_72h);

        // ข้อความย้อนหลังที่อยู่ "ใน" หน้าต่างเดิม แต่แถวปิดไปแล้ว
        $this->addMessage($room->id, '2026-11-16 12:00:00');
        $before = $this->snapshotRow($row->id);

        $this->service()->reconcile($this->at('2026-11-20 10:00:00'));
        $this->freezeAt('2026-11-20 10:00:00');
        $this->markResponded($room);

        $this->assertSame($before, $this->snapshotRow($row->id), 'ปิด false แล้วห้ามเขียนทับเป็น true');
    }

    #[Test]
    public function test_reply_true_keeps_responded_at_when_an_earlier_message_lands_later(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U08b2c3d4e5f60718293a4b5c6d7e8f90');
        $row = $this->sentRow($room, self::T);
        $this->addMessage($room->id, '2026-11-16 12:00:00');

        $this->service()->recordReply($room, $this->at('2026-11-16 12:00:05'));
        $this->assertSame('2026-11-16 12:00:00', (string) $this->outcomes($row->id)->responded_at);

        // ข้อความที่เร็วกว่าถูกย้อนหลังเข้ามาทีหลัง — responded_at ต้องคงเดิม
        $this->addMessage($room->id, '2026-11-16 11:00:00');
        $before = $this->snapshotRow($row->id);

        $this->service()->reconcile($this->at('2026-11-16 13:00:00'));
        $this->freezeAt('2026-11-16 13:00:00');
        $this->markResponded($room);

        $this->assertSame($before, $this->snapshotRow($row->id), 'responded_at ตัดสินแล้วห้ามเขียนทับ');
    }

    #[Test]
    public function test_order_false_stays_false_when_an_order_lands_after_the_close(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U09b2c3d4e5f60718293a4b5c6d7e8f90');
        $row = $this->sentRow($room, self::T);

        $this->service()->reconcile($this->at(self::T_ORDER_CLOSE));
        $this->assertFalse((bool) $this->outcomes($row->id)->ordered_within_7d);

        $this->addOrder($room, '2026-11-17 11:00:00');
        $before = $this->snapshotRow($row->id);

        $this->service()->reconcile($this->at('2026-11-24 10:00:00'));
        $this->freezeAt('2026-11-24 10:00:00');
        $this->markResponded($room);

        $this->assertSame($before, $this->snapshotRow($row->id), 'ปิด ordered false แล้วห้ามเขียนทับ');
    }

    #[Test]
    public function test_order_true_keeps_order_when_an_earlier_order_lands_later(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U0ab2c3d4e5f60718293a4b5c6d7e8f90');
        $row = $this->sentRow($room, self::T);
        $orderId = $this->addOrder($room, '2026-11-17 12:00:00');

        $this->service()->reconcile($this->at('2026-11-17 13:00:00'));
        $this->assertSame($orderId, (int) $this->outcomes($row->id)->order_id);

        $this->addOrder($room, '2026-11-17 11:00:00');
        $before = $this->snapshotRow($row->id);

        $this->service()->reconcile($this->at('2026-11-18 10:00:00'));
        $this->freezeAt('2026-11-18 10:00:00');
        $this->markResponded($room);

        $this->assertSame($before, $this->snapshotRow($row->id), 'order_id ตัดสินแล้วห้ามเขียนทับ');
    }

    #[Test]
    public function test_reconcile_twice_with_two_different_now_values_leaves_rows_byte_equal(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U0bb2c3d4e5f60718293a4b5c6d7e8f90');
        $row = $this->sentRow($room, self::T);

        $this->service()->reconcile($this->at('2026-11-19 10:10:00'));
        $afterFirst = $this->snapshotRow($row->id);

        // รอบสอง เวลาต่างกัน — ผลตอบกลับตัดสินแล้ว ผลออเดอร์ยังค้างรอ = ไม่มีการเขียน
        $this->service()->reconcile($this->at('2026-11-19 10:15:00'));

        $this->assertSame($afterFirst, $this->snapshotRow($row->id), 'reconcile ซ้ำต้องไม่เขียนทับอะไร');
    }

    // ------------------------------------------------------------------
    // สองแถวของลูกค้าเดียว — หนึ่งเหตุการณ์นับให้แถวเดียว (D56, probe 1)
    // ------------------------------------------------------------------

    #[Test]
    public function test_two_rows_one_reply_counts_for_newest_row_only_then_older_closes_false(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U0cb2c3d4e5f60718293a4b5c6d7e8f90');
        $older = $this->sentRow($room, '2026-11-14 10:00:00'); // T-48h
        $newer = $this->sentRow($room, '2026-11-15 10:00:00'); // T-24h
        $this->addMessage($room->id, '2026-11-15 11:00:00');   // T-23h

        $this->freezeAt('2026-11-16 10:00:00');
        $this->markResponded($room);
        $this->markResponded($room);
        $this->markResponded($room);

        $newerO = $this->outcomes($newer->id);
        $olderO = $this->outcomes($older->id);
        $this->assertTrue((bool) $newerO->replied_within_72h, 'ข้อความนับให้แถวล่าสุด');
        $this->assertSame('2026-11-15 11:00:00', (string) $newerO->responded_at);
        $this->assertNull($olderO->replied_within_72h, 'ข้อความเดียวกันห้ามนับซ้ำให้แถวเก่า');

        // reconcile หลังหน้าต่าง + grace ของแถวเก่า → ปิด false
        $this->service()->reconcile($this->at('2026-11-17 10:10:00'));

        $olderO = $this->outcomes($older->id);
        $this->assertNotNull($olderO->replied_within_72h);
        $this->assertFalse((bool) $olderO->replied_within_72h);
        $this->assertSame(0, (int) $olderO->customer_responded);
        $this->assertNull($olderO->responded_at);
    }

    #[Test]
    public function test_two_rows_reply_between_the_two_rows_counts_for_the_older_row(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U0db2c3d4e5f60718293a4b5c6d7e8f90');
        $older = $this->sentRow($room, '2026-11-14 10:00:00');
        $newer = $this->sentRow($room, '2026-11-15 10:00:00');
        $this->addMessage($room->id, '2026-11-15 04:00:00'); // T-30h — ก่อนแถวใหม่ยัง

        $this->freezeAt('2026-11-16 10:00:00');
        $this->markResponded($room);

        $olderO = $this->outcomes($older->id);
        $newerO = $this->outcomes($newer->id);
        $this->assertTrue((bool) $olderO->replied_within_72h);
        $this->assertSame('2026-11-15 04:00:00', (string) $olderO->responded_at);
        $this->assertNull($newerO->replied_within_72h, 'แถวใหม่ห้ามโดนข้อความก่อนที่มันจะถูกส่ง');
    }

    #[Test]
    public function test_two_rows_reply_at_exact_newer_sent_at_counts_for_the_older_row(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U0eb2c3d4e5f60718293a4b5c6d7e8f90');
        $older = $this->sentRow($room, '2026-11-14 10:00:00');
        $newer = $this->sentRow($room, '2026-11-15 10:00:00');
        $this->addMessage($room->id, '2026-11-15 10:00:00'); // พอดี sent_at ของแถวใหม่

        $this->freezeAt('2026-11-16 10:00:00');
        $this->markResponded($room);

        $this->assertTrue((bool) $this->outcomes($older->id)->replied_within_72h, 'cap ปลายเปิดแบบรวม ≤ sent_at แถวถัดไป');
        $this->assertNull($this->outcomes($newer->id)->replied_within_72h);
    }

    #[Test]
    public function test_two_rows_one_order_counts_for_newest_row_only(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U0fb2c3d4e5f60718293a4b5c6d7e8f90');
        $older = $this->sentRow($room, '2026-11-14 10:00:00');
        $newer = $this->sentRow($room, '2026-11-15 10:00:00');
        $orderId = $this->addOrder($room, '2026-11-15 11:00:00'); // T-23h

        $this->service()->reconcile($this->at('2026-11-16 10:00:00'));

        $newerO = $this->outcomes($newer->id);
        $olderO = $this->outcomes($older->id);
        $this->assertTrue((bool) $newerO->ordered_within_7d);
        $this->assertSame($orderId, (int) $newerO->order_id);
        $this->assertNull($olderO->ordered_within_7d, 'ออเดอร์ใบเดียวห้ามนับซ้ำให้แถวเก่า');
        $this->assertNull($olderO->order_id);
    }

    #[Test]
    public function test_two_rows_order_before_the_newer_row_counts_for_the_older_row(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U10b2c3d4e5f60718293a4b5c6d7e8f90');
        $older = $this->sentRow($room, '2026-11-14 10:00:00');
        $newer = $this->sentRow($room, '2026-11-15 10:00:00');
        $orderId = $this->addOrder($room, '2026-11-15 04:00:00'); // T-30h

        $this->service()->reconcile($this->at('2026-11-16 10:00:00'));

        $olderO = $this->outcomes($older->id);
        $this->assertTrue((bool) $olderO->ordered_within_7d);
        $this->assertSame($orderId, (int) $olderO->order_id);
        $this->assertNull($this->outcomes($newer->id)->ordered_within_7d);
    }

    #[Test]
    public function test_two_rows_order_at_exact_newer_sent_at_counts_for_the_older_row(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U11b2c3d4e5f60718293a4b5c6d7e8f90');
        $older = $this->sentRow($room, '2026-11-14 10:00:00');
        $newer = $this->sentRow($room, '2026-11-15 10:00:00');
        $orderId = $this->addOrder($room, '2026-11-15 10:00:00'); // พอดี sent_at แถวใหม่

        $this->service()->reconcile($this->at('2026-11-16 10:00:00'));

        $olderO = $this->outcomes($older->id);
        $this->assertTrue((bool) $olderO->ordered_within_7d);
        $this->assertSame($orderId, (int) $olderO->order_id);
        $this->assertNull($this->outcomes($newer->id)->ordered_within_7d);
    }

    // ------------------------------------------------------------------
    // หน้าต่างออเดอร์ ขอบเขต สถานะ ขอบเขตลูกค้า
    // ------------------------------------------------------------------

    /** แต่ละกรณี = baseline ออเดอร์ (แถว sent ที่ T + ออเดอร์ completed ห้องเดียวกัน T+1d) เปลี่ยนสิ่งเดียว */
    public static function orderPlacementCases(): array
    {
        return [
            'order at T+1d (baseline)' => ['same-room', self::T_PLUS_1D, 'completed', true],
            'order at exactly sent_at' => ['same-room', self::T, 'completed', false],
            'order one second before sent_at' => ['same-room', '2026-11-16 09:59:59', 'completed', false],
            'order at T+7d 0s exactly (inclusive end)' => ['same-room', self::T_PLUS_7D, 'completed', true],
            'order at T+7d 1s (past the end)' => ['same-room', '2026-11-23 10:00:01', 'completed', false],
            'order status processing instead of completed' => ['same-room', self::T_PLUS_1D, 'processing', false],
            'order of another bot' => ['other-bot-room', self::T_PLUS_1D, 'completed', false],
            'order of another customer' => ['other-customer-room', self::T_PLUS_1D, 'completed', false],
            'order in another room of the same customer' => ['sibling-room', self::T_PLUS_1D, 'completed', true],
        ];
    }

    private function seedOrderCase(string $roomKind, string $orderAt, string $status): array
    {
        $bot = $this->newBot();
        $mainExternal = 'U12b2c3d4e5f60718293a4b5c6d7e8f90';

        $mainRoom = $this->room($bot, $mainExternal);
        $row = $this->sentRow($mainRoom, self::T);

        $orderRoom = match ($roomKind) {
            'sibling-room' => $this->room($bot, $mainExternal),
            'other-bot-room' => $this->room($this->newBot(), $mainExternal),
            'other-customer-room' => $this->room($bot, 'U13b2c3d4e5f60718293a4b5c6d7e8f90'),
            default => $mainRoom,
        };

        $orderId = $this->addOrder($orderRoom, $orderAt, $status);

        return [$row, $orderId];
    }

    #[Test]
    #[DataProvider('orderPlacementCases')]
    public function test_order_window_boundaries_status_and_customer_scope_via_reconcile(
        string $roomKind,
        string $orderAt,
        string $status,
        bool $expectOrder,
    ): void {
        [$row, $orderId] = $this->seedOrderCase($roomKind, $orderAt, $status);

        // หน้าต่างยังเปิด — กรณี "ใช่" ตัดสิน true ได้ทันที กรณี "ไม่ใช่" ยังค้าง null
        $this->service()->reconcile($this->at('2026-11-16 11:05:00'));
        $o = $this->outcomes($row->id);

        if ($expectOrder) {
            $this->assertTrue((bool) $o->ordered_within_7d, $roomKind.' / '.$orderAt);
            $this->assertSame($orderId, (int) $o->order_id);
            $this->assertSame($orderAt, (string) $o->ordered_at);
            $this->assertSame('2026-11-16 11:05:00', (string) $o->order_window_closed_at);
        } else {
            $this->assertNull($o->ordered_within_7d, $roomKind.' / '.$orderAt);
            $this->assertNull($o->order_id);
        }

        // ปิดหน้าต่างแล้ว reconcile รอบสอง — กรณี "ใช่" คงคอลัมน์ออเดอร์เดิมทั้งหมด
        // (ผลตอบกลับที่ยัง null ในรอบแรก ปิดได้ตามปกติในรอบสอง — คนละผลกัน)
        $this->service()->reconcile($this->at(self::T_ORDER_CLOSE));
        $o = $this->outcomes($row->id);

        if ($expectOrder) {
            $this->assertTrue((bool) $o->ordered_within_7d, 'ordered true ห้ามเขียนทับในรอบหลัง');
            $this->assertSame($orderId, (int) $o->order_id);
            $this->assertSame($orderAt, (string) $o->ordered_at);
            $this->assertSame('2026-11-16 11:05:00', (string) $o->order_window_closed_at, 'order_window_closed_at คงของรอบแรก');
        } else {
            $this->assertNotNull($o->ordered_within_7d);
            $this->assertFalse((bool) $o->ordered_within_7d, $roomKind.' / '.$orderAt);
            $this->assertSame(self::T_ORDER_CLOSE, (string) $o->order_window_closed_at);
            $this->assertNull($o->order_id);
            $this->assertNull($o->ordered_at);
        }
    }

    // ------------------------------------------------------------------
    // สองผลเป็นอิสระจากกัน
    // ------------------------------------------------------------------

    #[Test]
    public function test_reply_false_and_order_true_are_independent(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U14b2c3d4e5f60718293a4b5c6d7e8f90');
        $row = $this->sentRow($room, self::T);
        $orderId = $this->addOrder($room, '2026-11-19 18:00:00'); // T+80h — นอกหน้าต่าง 72h แต่ใน 7d

        $this->service()->reconcile($this->at('2026-11-19 19:00:00'));

        $o = $this->outcomes($row->id);
        $this->assertNotNull($o->replied_within_72h);
        $this->assertFalse((bool) $o->replied_within_72h);
        // ปิดรอบแรกใน call นี้ — closed_at = now ของ call ('2026-11-19 19:00:00')
        $this->assertSame('2026-11-19 19:00:00', (string) $o->response_window_closed_at);
        $this->assertTrue((bool) $o->ordered_within_7d);
        $this->assertSame($orderId, (int) $o->order_id);
    }

    // ------------------------------------------------------------------
    // recordReply — ขอบเขตสิทธิ์การเขียน (D57)
    // ------------------------------------------------------------------

    #[Test]
    public function test_record_reply_never_touches_order_columns_or_writes_false_on_a_row_past_both_windows(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U15b2c3d4e5f60718293a4b5c6d7e8f90');
        $row = $this->sentRow($room, '2026-11-12 10:00:00'); // 96 ชม. ก่อน T — ทั้งสองหน้าต่างปิดหมด
        $before = $this->snapshotRow($row->id);

        $this->service()->recordReply($room, $this->at(self::T));

        $after = $this->snapshotRow($row->id);
        $this->assertSame($before, $after, 'recordReply บนแถวที่ไม่มีตอบกลับ ห้ามเขียนแม้คอลัมน์เดียว');

        $o = $this->outcomes($row->id);
        $this->assertNull($o->replied_within_72h);
        $this->assertNull($o->ordered_within_7d);
        $this->assertNull($o->order_id);
        $this->assertNull($o->ordered_at);
        $this->assertNull($o->response_window_closed_at);
        $this->assertNull($o->order_window_closed_at);
    }

    // ------------------------------------------------------------------
    // customer-message path — markCustomerResponded (D57)
    // ------------------------------------------------------------------

    #[Test]
    public function test_mark_customer_responded_swallows_service_throws_and_logs_ids_only(): void
    {
        $canaryException = 'boom-canary-exception-secret';
        $canaryCustomer = 'U-canary-customer-secret-1234567890';

        $bot = $this->newBot();
        $room = $this->room($bot, 'U16b2c3d4e5f60718293a4b5c6d7e8f90');
        $this->sentRow($room, self::T);
        $this->addMessage($room->id, self::T_PLUS_1H);
        DB::table('conversations')->where('id', $room->id)->update(['external_customer_id' => $canaryCustomer]);

        $this->mock(FollowUpOutcomeService::class, function ($mock) use ($canaryException): void {
            $mock->shouldReceive('recordReply')->andThrow(new RuntimeException($canaryException));
        });

        $this->freezeAt('2026-11-16 11:00:05');

        // ต้องไม่โยนออกนอก method — path ตอบลูกค้าต้องไม่พังเพราะตัวนับผล
        $this->markResponded($room);

        $outcomeLines = array_values(array_filter(
            $this->logLines,
            fn (MessageLogged $line) => str_contains($line->message, 'outcome'),
        ));
        $this->assertGreaterThanOrEqual(1, count($outcomeLines), 'ต้องมี log line ของการกลืน exception');

        foreach ($this->logLines as $line) {
            $rendered = json_encode(array_merge([$line->message], $line->context), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString($canaryException, $rendered, 'ห้ามมีข้อความ exception หลุดลง log');
            $this->assertStringNotContainsString($canaryCustomer, $rendered, 'ห้ามมี customer id หลุดลง log');
        }

        $logged = array_values($outcomeLines)[0];
        $this->assertSame(['conversation_id'], array_keys($logged->context), 'log ต้องมี id อย่างเดียว');
        $this->assertSame($room->id, (int) $logged->context['conversation_id']);
    }

    #[Test]
    public function test_mark_customer_responded_costs_one_select_and_no_write_when_nothing_is_open(): void
    {
        $bot = $this->newBot();
        $room = $this->room($bot, 'U17b2c3d4e5f60718293a4b5c6d7e8f90');

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        // (ก) ไม่มีแถวใดเลย
        $this->freezeAt('2026-11-16 11:00:05');
        $this->markResponded($room);
        $this->assertSame(1, count($queries), 'ไม่มีแถวเปิด = ONE select');
        $this->assertStringStartsWith('select', strtolower($queries[0]->sql));

        // (ข) มีแถว sent แต่ตัดสิน replied แล้ว = ยังคง ONE select และไม่เขียน
        $row = $this->sentRow($room, self::T);
        DB::table('lead_recovery_logs')->where('id', $row->id)->update([
            'replied_within_72h' => true,
            'customer_responded' => true,
            'responded_at' => self::T_PLUS_1H,
            'response_window_closed_at' => self::T_PLUS_1H,
        ]);
        $before = $this->snapshotRow($row->id);

        $queries = [];
        $this->markResponded($room);

        $this->assertSame(1, count($queries), 'ไม่มีแถว replied null = ONE select');
        $this->assertStringStartsWith('select', strtolower($queries[0]->sql));
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(update|insert|delete)/i', $query->sql, 'ห้ามมีการเขียน');
        }
        $this->assertSame($before, $this->snapshotRow($row->id));
    }

    #[Test]
    public function test_constants_match_the_card_windows(): void
    {
        $this->assertSame(72, FollowUpOutcomeService::RESPONSE_WINDOW_HOURS);
        $this->assertSame(7, FollowUpOutcomeService::ORDER_WINDOW_DAYS);
        $this->assertSame(600, FollowUpOutcomeService::CLOSE_GRACE_SECONDS);
    }
}
