<?php

namespace Tests\Feature\FollowUp;

use App\Jobs\GenerateCustomerFollowUp;
use App\Jobs\ProcessCustomerFollowUps;
use App\Jobs\SendCustomerFollowUp;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\User;
use App\Services\FollowUp\FollowUpCapabilities;
use App\Services\FollowUp\FollowUpPipeline;
use App\Services\FollowUp\FollowUpPrompts;
use App\Services\FollowUp\FollowUpReservationService;
use App\Services\FollowUp\FollowUpSalesProjector;
use App\Services\FollowUp\FollowUpSendService;
use App\Services\FollowUp\FollowUpTelegramService;
use App\Services\OpenRouterService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionFunction;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * การ์ด B6d — job ProcessCustomerFollowUps (สแกนทุก 15 นาที) และ
 * GenerateCustomerFollowUp ที่เสนอการ์ดทันทีเมื่อร่างพร้อม (D95-D99)
 *
 * สัญญาของการสแกน (D97): บอทที่ปิด = SELECT เท่านั้น ไม่เขียน ไม่คิว ไม่ HTTP
 * ไม่ log เหนือ debug; บอทที่เปิด = เติม projection ห้องที่มีข้อความใน 30 วัน (D96)
 * → สร้าง candidate (เก่าสุดก่อน ไม่เกิน 100) → เข้าคิวร่างแถวที่ prepare จะเก็บ
 * (ไม่เกิน 100, รอไม่เกิน 7 วัน) → เสนอการ์ดค้าง (state new/sending, ไม่หมดอายุ,
 * ไม่เกิน 5 ใบ) — ห้องไหนล้มไม่หยุดสแกน มี warning บรรทัดเดียวโดยไม่มี message
 */
class ScanJobTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 10:00:00';

    /** ข้อความลับที่ห้ามโผล่ใน log — พิสูจน์ว่า warning ไม่พา exception message */
    private const FAILURE_MARKER = 'leak-marker-'.'b6d';

    /** @var list<array{url: string, data: array}> */
    private array $httpRequests = [];

    /** @var list<int> Approval ids offered by the real jobs, in call order. */
    private array $publishedIds = [];

    /** @var list<string> */
    private array $queries = [];

    /** @var list<array{level: string, message: string, context: array}> */
    private array $logCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->setClock(self::NOW);
        self::$sortAnswerOverride = null;
        self::$sortStatusOverride = 200;
        config([
            'customer_follow_up.admin_web_url' => 'https://admin.example.test',
            'customer_follow_up.decisions_url' => 'https://openrouter-decisions'.'.test/api/alpha/decisions',
            'services.openrouter.api_key' => 'test-key-'.'b6d',
            'services.telegram_alert.secret' => 'webhook-secret-'.'b6d',
        ]);

        // Observe the caller boundary without replacing publish's DB/HTTP effects.
        $telegram = app(FollowUpTelegramService::class);
        $this->mock(FollowUpTelegramService::class, function (MockInterface $mock) use ($telegram): void {
            $mock->shouldReceive('publish')->andReturnUsing(function (int $id, ?CarbonImmutable $now = null) use ($telegram): string {
                $this->publishedIds[] = $id;

                return $telegram->publish($id, $now);
            });
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Gate (D97) — บอทปิดทุกกรณี = ไม่มีอะไรเกิดขึ้น, เงื่อนไขหาย = เปิดจริง
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('gateClosingConditions')]
    public function test_closed_gate_scan_touches_nothing_until_condition_lifted(Closure $close, Closure $lift): void
    {
        $bot = $this->seedOpenBot();
        $close();
        $room = $this->makeSilentPaymentRoom($bot, 'UGATE', $this->clock());
        $this->fakeModelApis();
        Queue::fake();
        $this->startLogCapture();

        $this->captureQueries();
        $this->runScan();

        $this->assertScanTouchedNothing('gate');
        $this->assertSame(
            [],
            array_values(array_filter($this->logCalls, fn (array $call): bool => in_array($call['level'], ['notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true))),
            'a closed bot must not log above debug'
        );

        $lift();
        $this->captureQueries();
        $this->runScan();

        $rows = DB::table('lead_recovery_logs')->where('bot_id', $bot->id)->where('delivery_status', 'candidate')->get();
        $this->assertCount(1, $rows, 'lifting the closing condition must create the candidate row');
        Queue::assertPushed(GenerateCustomerFollowUp::class, 1);
    }

    /** @return array<string, array{Closure, Closure}> */
    public static function gateClosingConditions(): array
    {
        $botId = static function (): int {
            return (int) DB::table('bots')->orderBy('id')->value('id');
        };

        return [
            'bot id not in the list' => [
                'close' => function (): void {
                    config(['customer_follow_up.bot_ids' => [999999]]);
                },
                'lift' => function () use ($botId): void {
                    config(['customer_follow_up.bot_ids' => [$botId()]]);
                },
            ],
            'bots row missing' => [
                'close' => function (): void {
                    config(['customer_follow_up.bot_ids' => [999999]]);
                },
                'lift' => function () use ($botId): void {
                    config(['customer_follow_up.bot_ids' => [$botId()]]);
                },
            ],
            'bot status not active' => [
                'close' => function () use ($botId): void {
                    DB::table('bots')->where('id', $botId())->update(['status' => 'paused']);
                },
                'lift' => function () use ($botId): void {
                    DB::table('bots')->where('id', $botId())->update(['status' => 'active']);
                },
            ],
            'bot channel not line' => [
                'close' => function () use ($botId): void {
                    DB::table('bots')->where('id', $botId())->update(['channel_type' => 'telegram']);
                },
                'lift' => function () use ($botId): void {
                    DB::table('bots')->where('id', $botId())->update(['channel_type' => 'line']);
                },
            ],
            'no bot_settings row' => [
                'close' => function () use ($botId): void {
                    DB::table('bot_settings')->where('bot_id', $botId())->delete();
                },
                'lift' => function () use ($botId): void {
                    DB::table('bot_settings')->insert([
                        'bot_id' => $botId(),
                        'follow_up_enabled' => true,
                        'follow_up_mode' => 'approve',
                        'follow_up_started_at' => '2026-10-17 10:00:00',
                        'follow_up_activation_message_id' => 0,
                        'follow_up_activated_at' => '2026-10-17 10:00:00',
                        'created_at' => '2026-11-16 10:00:00',
                        'updated_at' => '2026-11-16 10:00:00',
                    ]);
                },
            ],
            'switch off' => [
                'close' => function () use ($botId): void {
                    DB::table('bot_settings')->where('bot_id', $botId())->update(['follow_up_enabled' => false]);
                },
                'lift' => function () use ($botId): void {
                    DB::table('bot_settings')->where('bot_id', $botId())->update(['follow_up_enabled' => true]);
                },
            ],
            'activation message id null' => [
                'close' => function () use ($botId): void {
                    DB::table('bot_settings')->where('bot_id', $botId())->update(['follow_up_activation_message_id' => null]);
                },
                'lift' => function () use ($botId): void {
                    DB::table('bot_settings')->where('bot_id', $botId())->update(['follow_up_activation_message_id' => 0]);
                },
            ],
            'follow_up_activated_at null' => [
                'close' => function () use ($botId): void {
                    DB::table('bot_settings')->where('bot_id', $botId())->update(['follow_up_activated_at' => null]);
                },
                'lift' => function () use ($botId): void {
                    DB::table('bot_settings')->where('bot_id', $botId())->update(['follow_up_activated_at' => '2026-10-17 10:00:00']);
                },
            ],
            '07:59:59 before opening' => [
                'close' => function (): void {
                    self::setClockStatic('2026-11-16 07:59:59');
                },
                'lift' => function (): void {
                    self::setClockStatic('2026-11-16 08:00:00');
                },
            ],
            '22:00:00 closed' => [
                'close' => function (): void {
                    self::setClockStatic('2026-11-16 22:00:00');
                },
                'lift' => function (): void {
                    self::setClockStatic('2026-11-16 21:59:59');
                },
            ],
            'telegram plugin missing' => [
                'close' => function (): void {
                    DB::table('flow_plugins')->where('type', 'telegram')->update(['enabled' => false]);
                },
                'lift' => function (): void {
                    DB::table('flow_plugins')->where('type', 'telegram')->update(['enabled' => true]);
                },
            ],
            'telegram allowlist empty' => [
                'close' => function (): void {
                    DB::table('flow_plugins')->where('type', 'telegram')->update([
                        'config' => json_encode(['access_token' => 'tok-'.'gate', 'chat_id' => '999', 'authorized_user_ids' => []]),
                    ]);
                },
                'lift' => function (): void {
                    DB::table('flow_plugins')->where('type', 'telegram')->update([
                        'config' => json_encode(['access_token' => 'tok-'.'gate', 'chat_id' => '999', 'authorized_user_ids' => ['555']]),
                    ]);
                },
            ],
            'webhook secret missing' => [
                'close' => function (): void {
                    config(['services.telegram_alert.secret' => '']);
                },
                'lift' => function (): void {
                    config(['services.telegram_alert.secret' => 'webhook-secret-'.'b6d']);
                },
            ],
            'writer missing' => [
                'close' => function () use ($botId): void {
                    DB::table('bots')->where('id', $botId())->update(['primary_chat_model' => null]);
                },
                'lift' => function () use ($botId): void {
                    DB::table('bots')->where('id', $botId())->update(['primary_chat_model' => 'openai/gpt-6-luna-writer']);
                },
            ],
            'admin url missing' => [
                'close' => function (): void {
                    config(['customer_follow_up.admin_web_url' => '']);
                },
                'lift' => function (): void {
                    config(['customer_follow_up.admin_web_url' => 'https://admin.example.test']);
                },
            ],
        ];
    }

    #[Test]
    public function test_scan_opens_exactly_at_08_00_and_still_at_21_59_59(): void
    {
        foreach (['2026-11-16 08:00:00', '2026-11-16 21:59:59'] as $clock) {
            config(['customer_follow_up.bot_ids' => []]);
            $bot = $this->seedOpenBot();
            $this->setClock($clock);
            $room = $this->makeSilentPaymentRoom($bot, 'UOPEN', $this->clock());
            $this->fakeModelApis();
            Queue::fake();

            $this->runScan();

            $this->assertSame(1, DB::table('lead_recovery_logs')->where('bot_id', $bot->id)->count(), "clock {$clock}");
            Queue::assertPushed(GenerateCustomerFollowUp::class, 1);
            $this->assertSame($room->id, (int) DB::table('lead_recovery_logs')->where('bot_id', $bot->id)->value('conversation_id'), "clock {$clock}");
        }
    }

    #[Test]
    public function test_two_bots_in_list_only_the_open_one_is_touched(): void
    {
        $open = $this->seedOpenBot();
        $closed = $this->seedOpenBot();
        DB::table('bot_settings')->where('bot_id', $closed->id)->update(['follow_up_enabled' => false]);
        config(['customer_follow_up.bot_ids' => [$open->id, $closed->id]]);

        $openRoom = $this->makeSilentPaymentRoom($open, 'UOPEN1', $this->clock());
        $closedRoom = $this->makeSilentPaymentRoom($closed, 'UCLOSED1', $this->clock());
        $this->fakeModelApis();
        Queue::fake();

        $this->runScan();

        $this->assertSame(1, DB::table('follow_up_sales_signals')->where('conversation_id', $openRoom->id)->count());
        $this->assertSame(1, DB::table('lead_recovery_logs')->where('bot_id', $open->id)->count());
        Queue::assertPushed(GenerateCustomerFollowUp::class, 1);
        $this->assertSame(
            0,
            DB::table('follow_up_sales_signals')->where('conversation_id', $closedRoom->id)->count(),
            'a closed bot must not be refreshed'
        );
        $this->assertSame(0, DB::table('lead_recovery_logs')->where('bot_id', $closed->id)->count());
    }

    #[Test]
    public function test_non_line_bot_is_also_blocked_by_capabilities_line_only(): void
    {
        $bot = $this->seedOpenBot();
        $this->makeSilentSaleRoom($bot, 'UNONLINE');
        $bot->update(['channel_type' => 'telegram']);
        $this->assertSame('line_only', FollowUpCapabilities::forBot($bot->fresh(), $this->clock())['blocked_reason']);
        $this->fakeModelApis();
        Queue::fake();
        $this->captureQueries();

        $this->runScan();

        $this->assertScanTouchedNothing('non-line capabilities');
        $this->assertSame([], $this->publishedIds);

        $bot->update(['channel_type' => 'line']);
        $this->assertNull(FollowUpCapabilities::forBot($bot->fresh(), $this->clock())['blocked_reason']);
        $this->runScan();
        $this->assertSame(1, DB::table('lead_recovery_logs')->where('bot_id', $bot->id)->count());
        Queue::assertPushed(GenerateCustomerFollowUp::class, 1);
    }

    // ------------------------------------------------------------------
    // Refresh set (D96) — ขอบ 30 วันและเงื่อนไขห้อง
    // ------------------------------------------------------------------

    #[Test]
    public function test_refresh_window_and_room_filters_decide_who_gets_a_signal_row(): void
    {
        $bot = $this->seedOpenBot();
        $this->fakeModelApis();
        Queue::fake();

        $boundaryRoom = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'UB30']);
        $this->addMessage($boundaryRoom->id, 'user', '2026-10-17 10:00:00', 'สนใจสินค้าครับ');
        $this->addMessage($boundaryRoom->id, 'user', '2026-10-17 10:00:00', 'ประเมินราคาให้หน่อยครับ');

        $beyondRoom = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'UB31']);
        $this->addMessage($beyondRoom->id, 'user', '2026-10-17 09:59:58', 'สนใจสินค้าครับ');
        $this->addMessage($beyondRoom->id, 'user', '2026-10-17 09:59:59', 'ประเมินราคาให้หน่อยครับ');

        $handoverRoom = $this->makeSilentSaleRoom($bot, 'UB32');
        DB::table('conversations')->where('id', $handoverRoom->id)->update(['is_handover' => true]);
        $inactiveRoom = $this->makeSilentSaleRoom($bot, 'UB33');
        DB::table('conversations')->where('id', $inactiveRoom->id)->update(['status' => 'closed']);
        $deletedRoom = $this->makeSilentSaleRoom($bot, 'UB34');
        DB::table('conversations')->where('id', $deletedRoom->id)->update(['deleted_at' => '2026-11-16 09:00:00']);
        $anonymousRoom = $this->makeSilentSaleRoom($bot, '');
        $webRoom = $this->makeSilentSaleRoom($bot, 'UB36');
        DB::table('conversations')->where('id', $webRoom->id)->update(['channel_type' => 'facebook']);
        $otherBot = $this->seedOpenBot();
        $otherRoom = $this->makeSilentSaleRoom($otherBot, 'UB37');
        // ห้องของบอทอื่นอยู่นอกลิสต์สแกนรอบนี้ — ล็อกลิสต์ไว้ที่บอทหลักบอทเดียว
        config(['customer_follow_up.bot_ids' => [$bot->id]]);

        // ห้องที่ถูกเว้นต้องมีแถว signal เดิมค้างไว้และต้องคงเดิม byte-equal หลังสแกน
        $excluded = [$beyondRoom, $handoverRoom, $inactiveRoom, $deletedRoom, $anonymousRoom, $webRoom, $otherRoom];
        foreach ($excluded as $room) {
            DB::table('follow_up_sales_signals')->insert([
                'conversation_id' => $room->id,
                'sales_started_at' => '2026-11-01 00:00:00',
                'customer_message_count' => 99,
                'projected_at' => '2026-11-01 00:00:00',
                'projection_version' => 1,
            ]);
        }
        $staleBefore = [];
        foreach ($excluded as $room) {
            $staleBefore[$room->id] = DB::table('follow_up_sales_signals')->where('conversation_id', $room->id)->first();
        }

        $this->runScan();

        $boundary = DB::table('follow_up_sales_signals')->where('conversation_id', $boundaryRoom->id)->first();
        $this->assertNotNull($boundary, 'a room whose newest message is exactly 30 days old must be refreshed');
        $this->assertSame(self::NOW, (string) $boundary->projected_at);
        $this->assertSame(2, (int) $boundary->customer_message_count);

        foreach ($excluded as $room) {
            $after = DB::table('follow_up_sales_signals')->where('conversation_id', $room->id)->first();
            $this->assertSame((array) $staleBefore[$room->id], (array) $after, "room {$room->id} left out must stay byte-equal");
        }

        // control: ยกเงื่อนไขปิดทีละข้อ → ห้องนั้นถูกเติมและได้ candidate จริง
        DB::table('conversations')->where('id', $handoverRoom->id)->update(['is_handover' => false]);
        DB::table('conversations')->where('id', $inactiveRoom->id)->update(['status' => 'active']);
        DB::table('conversations')->where('id', $deletedRoom->id)->update(['deleted_at' => null]);
        DB::table('conversations')->where('id', $anonymousRoom->id)->update(['external_customer_id' => 'UB35']);
        DB::table('conversations')->where('id', $webRoom->id)->update(['channel_type' => 'line']);
        DB::table('messages')->where('conversation_id', $beyondRoom->id)
            ->update(['created_at' => '2026-10-17 10:00:00']);

        $this->runScan();

        foreach ([$handoverRoom, $inactiveRoom, $deletedRoom, $anonymousRoom, $webRoom, $beyondRoom] as $room) {
            $signal = DB::table('follow_up_sales_signals')->where('conversation_id', $room->id)->first();
            $this->assertSame(self::NOW, (string) $signal->projected_at, "room {$room->id} must be refreshed after lifting its filter");
            $this->assertSame(1, DB::table('lead_recovery_logs')->where('conversation_id', $room->id)->count(), "room {$room->id} must gain a candidate row");
        }
        $otherAfter = DB::table('follow_up_sales_signals')->where('conversation_id', $otherRoom->id)->first();
        $this->assertSame((array) $staleBefore[$otherRoom->id], (array) $otherAfter, 'the other bot room stays untouched');
    }

    // ------------------------------------------------------------------
    // ห้องเงียบจริง → candidate + เข้าคิว
    // ------------------------------------------------------------------

    #[Test]
    public function test_silent_payment_and_sale_rooms_get_candidates_and_jobs(): void
    {
        $bot = $this->seedOpenBot();
        $paymentRoom = $this->makeSilentPaymentRoom($bot, 'UPAY', $this->clock());
        $saleRoom = $this->makeSilentSaleRoom($bot, 'USALE');
        $youngPaymentRoom = $this->makeSilentPaymentRoom($bot, 'UPAY2', $this->clock());
        $youngSaleRoom = $this->makeSilentSaleRoom($bot, 'USALE2');
        // เงียบไม่ครบ: ห้องจ่ายเงินเลื่อนข้อความบอท (anchor ของ payment) ไปที่ 2:59:59 ก่อนขอบ,
        // ห้องขายเลื่อนข้อความลูกค้าสุดท้ายไปที่ 23:59:59 ก่อนขอบ
        $youngPaymentAnchor = DB::table('messages')->where('conversation_id', $youngPaymentRoom->id)->where('sender', 'bot')->max('id');
        DB::table('messages')->where('id', $youngPaymentAnchor)->update(['created_at' => '2026-11-16 07:00:01']);
        DB::table('messages')->where('conversation_id', $youngSaleRoom->id)->where('sender', 'user')->orderByDesc('id')->limit(1)
            ->update(['created_at' => '2026-11-16 10:00:01']);
        $this->fakeModelApis();
        Queue::fake();

        $this->startLogCapture();
        $this->runScan();

        $rows = DB::table('lead_recovery_logs')->where('bot_id', $bot->id)->orderBy('id')->get();
        $this->assertCount(2, $rows, 'only rooms past their wait time get a candidate row');
        $this->assertSame(
            collect([$paymentRoom->id, $saleRoom->id])->sort()->values()->all(),
            array_values(array_unique($rows->pluck('conversation_id')->sort()->values()->all())),
        );
        $queuedIds = array_map(fn (array $pushed): int => $pushed['job']->logId, Queue::pushedJobs()[GenerateCustomerFollowUp::class] ?? []);
        $this->assertSame($rows->pluck('id')->all(), $queuedIds, 'each candidate row queues exactly one drafting job with its own id');

        $infoLines = array_values(array_filter($this->logCalls, fn (array $call): bool => $call['level'] === 'info'));
        $this->assertCount(1, $infoLines, 'one info line per open bot per scan');
        $this->assertSame(
            ['bot_id', 'rooms_refreshed', 'rooms_failed', 'eligible_rooms', 'candidates_created', 'jobs_queued', 'cards_offered', 'card_results', 'send_jobs_queued', 'milliseconds'],
            array_keys($infoLines[0]['context'])
        );
        $this->assertSame($bot->id, $infoLines[0]['context']['bot_id']);
        $this->assertSame(4, $infoLines[0]['context']['rooms_refreshed']);
        $this->assertSame(0, $infoLines[0]['context']['rooms_failed']);
        $this->assertSame(2, $infoLines[0]['context']['eligible_rooms']);
        $this->assertSame(2, $infoLines[0]['context']['candidates_created']);
        $this->assertSame(2, $infoLines[0]['context']['jobs_queued']);

        // control: ขยับข้อความสุดท้ายให้เงียบครบ → ห้องใต้เพดานได้แถวจริง
        DB::table('messages')->where('id', $youngPaymentAnchor)->update(['created_at' => '2026-11-16 05:00:00']);
        DB::table('messages')->where('conversation_id', $youngSaleRoom->id)->where('sender', 'user')->orderByDesc('id')->limit(1)
            ->update(['created_at' => '2026-11-15 08:59:00']);
        $this->runScan();

        $rowsAfter = DB::table('lead_recovery_logs')->where('bot_id', $bot->id)->get();
        $this->assertSame(
            collect([$paymentRoom->id, $saleRoom->id, $youngPaymentRoom->id, $youngSaleRoom->id])->sort()->values()->all(),
            array_values(array_unique($rowsAfter->pluck('conversation_id')->sort()->values()->all())),
            'the lifted under-threshold rooms gain their own candidate rows'
        );
    }

    #[Test]
    public function test_second_scan_fifteen_minutes_later_requeues_the_same_rows_without_other_writes(): void
    {
        $bot = $this->seedOpenBot();
        $paymentRoom = $this->makeSilentPaymentRoom($bot, 'UPAY3', $this->clock());
        $saleRoom = $this->makeSilentSaleRoom($bot, 'USALE3');
        $this->fakeModelApis();
        Queue::fake();

        $this->runScan();
        $firstIds = array_map(fn (array $pushed): int => $pushed['job']->logId, Queue::pushedJobs()[GenerateCustomerFollowUp::class] ?? []);
        $this->assertCount(2, $firstIds);

        $this->setClock('2026-11-16 10:15:00');
        Queue::fake();
        $this->captureQueries();
        $this->runScan();

        $secondIds = array_map(fn (array $pushed): int => $pushed['job']->logId, Queue::pushedJobs()[GenerateCustomerFollowUp::class] ?? []);
        sort($firstIds);
        $secondIdsSorted = $secondIds;
        sort($secondIdsSorted);
        $this->assertSame($firstIds, $secondIdsSorted, 'the same rows must be queued again, ids unchanged');
        $this->assertSame(2, DB::table('lead_recovery_logs')->where('bot_id', $bot->id)->count(), 'no duplicate candidate rows');
        $this->assertSame(['follow_up_sales_signals'], $this->writeTableNames(), 'the second scan may only rewrite the signal rows');
    }

    // ------------------------------------------------------------------
    // Queue matrix — แถวไหนเข้าคิว = แถวที่ prepare จะเก็บ (ที่อายุไม่เกิน 7 วัน)
    // ------------------------------------------------------------------

    #[Test]
    public function test_queue_matrix_rows_queued_exactly_when_prepare_would_claim(): void
    {
        $bot = $this->seedOpenBot();
        $closed = $this->seedOpenBot();
        DB::table('bot_settings')->where('bot_id', $closed->id)->update(['follow_up_enabled' => false]);
        config(['customer_follow_up.bot_ids' => [$bot->id, $closed->id]]);

        $cases = [
            'candidate' => ['status' => 'candidate'],
            'sort_retry_due' => ['status' => 'sort_retry', 'next_attempt_at' => '2026-11-16 09:30:00'],
            'sort_retry_not_due' => ['status' => 'sort_retry', 'next_attempt_at' => '2026-11-16 23:00:00'],
            'deferred_due' => ['status' => 'deferred', 'next_attempt_at' => '2026-11-16 09:30:00'],
            'deferred_not_due' => ['status' => 'deferred', 'next_attempt_at' => '2026-11-16 23:00:00'],
            'deferred_null_next' => ['status' => 'deferred', 'next_attempt_at' => null],
            'sorting_10m_old' => ['status' => 'sorting', 'updated_at' => '2026-11-16 09:50:00'],
            'sorting_9m59s' => ['status' => 'sorting', 'updated_at' => '2026-11-16 09:50:01'],
            'drafting_10m_old' => ['status' => 'drafting', 'updated_at' => '2026-11-16 09:50:00'],
            'drafting_9m59s' => ['status' => 'drafting', 'updated_at' => '2026-11-16 09:50:01'],
            'candidate_7d_exact' => ['status' => 'candidate', 'created_at' => '2026-11-09 10:00:00'],
            'candidate_7d_1s' => ['status' => 'candidate', 'created_at' => '2026-11-09 09:59:59'],
            'excluded' => ['status' => 'excluded'],
            'cancelled' => ['status' => 'cancelled'],
            'pending' => ['status' => 'pending'],
            'approved' => ['status' => 'approved'],
            'sending' => ['status' => 'sending'],
            'retry' => ['status' => 'retry'],
            'sent' => ['status' => 'sent'],
            'send_failed' => ['status' => 'send_failed'],
            'skipped' => ['status' => 'skipped'],
            'expired' => ['status' => 'expired'],
        ];

        $rooms = [];
        $ids = [];
        foreach ($cases as $name => $spec) {
            $room = $this->makeSilentSaleRoom($bot, 'UQ'.count($ids));
            FollowUpSalesProjector::refresh($room, $this->clock());
            $log = app(FollowUpReservationService::class)->candidate($room, $this->clock());
            $update = ['delivery_status' => $spec['status']];
            if (array_key_exists('next_attempt_at', $spec)) {
                $update['next_attempt_at'] = $spec['next_attempt_at'];
            }
            if (isset($spec['updated_at'])) {
                $update['updated_at'] = $spec['updated_at'];
            }
            if (isset($spec['created_at'])) {
                $update['created_at'] = $spec['created_at'];
            }
            DB::table('lead_recovery_logs')->where('id', $log->id)->update($update);
            $ids[$name] = $log->id;
            $rooms[$name] = $room;
        }

        $closedRoom = $this->makeSilentSaleRoom($closed, 'UQC');
        FollowUpSalesProjector::refresh($closedRoom, $this->clock());
        $closedLog = app(FollowUpReservationService::class)->candidate($closedRoom, $this->clock());
        $ids['closed_bot_candidate'] = $closedLog->id;

        $this->fakeModelApis();
        Queue::fake();
        $this->runScan();

        $expectedQueued = array_filter($ids, fn (int $id, string $name): bool => in_array($name, [
            'candidate', 'sort_retry_due', 'deferred_due', 'deferred_null_next',
            'sorting_10m_old', 'drafting_10m_old', 'candidate_7d_exact',
        ], true), ARRAY_FILTER_USE_BOTH);
        $queuedIds = array_map(fn (array $pushed): int => $pushed['job']->logId, Queue::pushedJobs()[GenerateCustomerFollowUp::class] ?? []);
        $expectedQueuedValues = array_values($expectedQueued);
        $queuedSorted = $queuedIds;
        sort($expectedQueuedValues);
        sort($queuedSorted);
        $this->assertSame($expectedQueuedValues, $queuedSorted, 'the queued set must be exactly the claimable rows of the open bot');

        // หลักฐานที่สอง: prepare เอง (model จำลอง) ต้องเปลี่ยนพอดีชุดที่เข้าคิว
        $this->fakeWriterMany();
        $changed = [];
        $proofNames = array_keys(array_filter($ids, fn (int $id, string $name): bool => $name !== 'candidate_7d_1s' && $name !== 'closed_bot_candidate', ARRAY_FILTER_USE_BOTH));
        foreach ($proofNames as $name) {
            $before = (array) DB::table('lead_recovery_logs')->where('id', $ids[$name])->first();
            app(FollowUpPipeline::class)->prepare($ids[$name], $this->clock());
            $after = (array) DB::table('lead_recovery_logs')->where('id', $ids[$name])->first();
            if ($before !== $after) {
                $changed[] = $ids[$name];
            }
        }
        sort($changed);
        $this->assertSame($expectedQueuedValues, $changed, 'the rows prepare changes must be exactly the queued set');
    }

    // ------------------------------------------------------------------
    // พารามิเตอร์จำกัดและลำดับ
    // ------------------------------------------------------------------

    #[Test]
    public function test_limits_100_rooms_and_5_cards_and_the_next_scan_takes_the_left_out(): void
    {
        $bot = $this->seedOpenBot();
        $rooms = [];
        foreach (range(0, 100) as $index) {
            $room = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'UL'.$index]);
            $this->addMessage($room->id, 'user', $this->at('2026-11-15 08:00:00')->subMinutes($index)->format('Y-m-d H:i:s'), 'สนใจสินค้าครับ');
            $this->addMessage($room->id, 'user', $this->at('2026-11-15 08:59:00')->subMinutes($index)->format('Y-m-d H:i:s'), 'ประเมินราคาให้หน่อยครับ');
            $rooms[] = $room;
        }
        $this->fakeModelApis();
        Queue::fake();

        $this->runScan();

        $this->assertSame(100, DB::table('lead_recovery_logs')->where('bot_id', $bot->id)->count(), 'at most 100 candidate rows per scan');
        $newestRoom = $rooms[0];
        $this->assertSame(
            0,
            DB::table('lead_recovery_logs')->where('conversation_id', $newestRoom->id)->count(),
            'the room with the newest last_message_at is the one left out'
        );

        $approvalIds = [];
        foreach (range(0, 5) as $index) {
            $room = $this->makeSilentSaleRoom($bot, 'UA'.$index);
            FollowUpSalesProjector::refresh($room, $this->clock());
            $log = app(FollowUpReservationService::class)->candidate($room, $this->clock());
            DB::table('lead_recovery_logs')->where('id', $log->id)->update(['delivery_status' => 'pending']);
            $approvalIds[] = $this->makeApproval((int) $log->id);
        }

        Queue::fake();
        $this->runScan();

        $this->assertCount(5, $this->sendMessageRequests(), 'at most 5 cards offered per scan');
        sort($approvalIds);
        $offered = array_slice($approvalIds, 0, 5);
        $leftOut = $approvalIds[5];
        foreach ($offered as $approvalId) {
            $this->assertSame('sent', (string) $this->approvalRow($approvalId)->telegram_delivery_state);
        }
        $this->assertSame('new', (string) $this->approvalRow($leftOut)->telegram_delivery_state, 'the highest id is left out');

        $this->runScan();

        $this->assertSame('sent', (string) $this->approvalRow($leftOut)->telegram_delivery_state, 'the next scan takes the left-out card');
        $this->assertCount(6, $this->sendMessageRequests());
    }

    #[Test]
    public function test_step_c_queues_only_the_lowest_100_of_101_claimable_rows_on_both_scans(): void
    {
        $bot = $this->seedOpenBot();
        $ids = [];
        foreach (range(0, 100) as $index) {
            $room = $this->makeSilentSaleRoom($bot, 'UCLAIM'.$index);
            FollowUpSalesProjector::refresh($room, $this->clock());
            $ids[] = app(FollowUpReservationService::class)->candidate($room, $this->clock())->id;
        }
        $before = DB::table('lead_recovery_logs')->where('bot_id', $bot->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        $expectedIds = array_slice($ids, 0, 100);
        $this->fakeModelApis();

        foreach ([self::NOW, '2026-11-16 10:15:00'] as $clock) {
            $this->setClock($clock);
            Queue::fake();
            $this->runScan();

            $queuedIds = array_map(fn (array $pushed): int => $pushed['job']->logId, Queue::pushedJobs()[GenerateCustomerFollowUp::class] ?? []);
            $this->assertSame($expectedIds, $queuedIds, '100 lowest ids only, including on the next scan while they remain candidate');
            $this->assertNotContains($ids[100], $queuedIds, 'the highest id must be left out');
            $this->assertSame($before, DB::table('lead_recovery_logs')->where('bot_id', $bot->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
        }
        $this->assertSame([], $this->publishedIds);
        $this->assertSame([], $this->httpRequests);
    }

    // ------------------------------------------------------------------
    // Offer matrix (step D)
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('stepDOfferCases')]
    public function test_step_d_offer_matrix(array $overrides, int $expectedRequests, ?string $expectedState, bool $expectByteEqual, bool $expectPublish): void
    {
        $bot = $this->seedOpenBot();
        $room = $this->makeSilentSaleRoom($bot, 'UD');
        FollowUpSalesProjector::refresh($room, $this->clock());
        $log = app(FollowUpReservationService::class)->candidate($room, $this->clock());
        DB::table('lead_recovery_logs')->where('id', $log->id)->update(['delivery_status' => 'pending']);
        $approvalId = $this->makeApproval((int) $log->id, $overrides);
        $before = DB::table('follow_up_approvals')->where('id', $approvalId)->first();
        $this->fakeModelApis();
        Queue::fake();

        $this->runScan();

        $this->assertCount($expectedRequests, $this->sendMessageRequests(), 'wrong number of sendMessage requests');
        $this->assertSame($expectPublish ? [$approvalId] : [], $this->publishedIds, 'only eligible ids may consume a publish slot');
        $after = DB::table('follow_up_approvals')->where('id', $approvalId)->first();
        if ($expectedState !== null) {
            $this->assertSame($expectedState, (string) $after->telegram_delivery_state, 'wrong final telegram_delivery_state');
        }
        if ($expectByteEqual) {
            $this->assertSame((array) $before, (array) $after, 'the approval row must stay byte-equal');
        }
    }

    /** @return array<string, array{array, int, ?string, bool, bool}> */
    public static function stepDOfferCases(): array
    {
        return [
            'pending new unexpired' => [['status' => 'pending', 'telegram_delivery_state' => 'new'], 1, 'sent', false, true],
            'pending sending young' => [['status' => 'pending', 'telegram_delivery_state' => 'sending', 'updated_at' => '2026-11-16 09:59:30'], 0, null, true, true],
            'pending sending old' => [['status' => 'pending', 'telegram_delivery_state' => 'sending', 'updated_at' => '2026-11-16 09:57:59'], 0, 'unknown', false, true],
            'pending sent state' => [['status' => 'pending', 'telegram_delivery_state' => 'sent'], 0, null, true, false],
            'pending failed state' => [['status' => 'pending', 'telegram_delivery_state' => 'failed'], 0, null, true, false],
            'pending unknown state' => [['status' => 'pending', 'telegram_delivery_state' => 'unknown'], 0, null, true, false],
            'expires exactly at now' => [['status' => 'pending', 'expires_at' => '2026-11-16 10:00:00'], 0, null, true, false],
            'expires before now' => [['status' => 'pending', 'expires_at' => '2026-11-16 09:59:59'], 0, null, true, false],
            'approved new' => [['status' => 'approved'], 0, null, true, false],
            'skipped new' => [['status' => 'skipped'], 0, null, true, false],
            'expired new' => [['status' => 'expired'], 0, null, true, false],
            'cancelled new' => [['status' => 'cancelled'], 0, null, true, false],
            'sent approval new' => [['status' => 'sent'], 0, null, true, false],
            'editing new' => [['status' => 'editing'], 0, null, true, false],
        ];
    }

    #[Test]
    #[DataProvider('ineligibleLowIdCards')]
    public function test_ineligible_low_ids_do_not_take_the_five_card_slots(array $overrides): void
    {
        $bot = $this->seedOpenBot();
        $before = [];
        foreach ($overrides as $index => $override) {
            $id = $this->seedPendingApproval($bot, 'USLOT'.$index, $override + ['updated_at' => '2026-11-16 09:00:00']);
            $before[$id] = (array) $this->approvalRow($id);
        }
        $this->assertCount(5, $before);
        $readyId = $this->seedPendingApproval($bot, 'USLOTREADY');
        $this->assertGreaterThan(max(array_keys($before)), $readyId);
        $this->fakeModelApis();
        Queue::fake();

        $this->runScan();

        $this->assertSame([$readyId], $this->publishedIds, 'ineligible older rows must not consume any of the five publish slots');
        $this->assertCount(1, $this->sendMessageRequests(), 'the newer ready card must be sent in this same scan');
        $this->assertSame('sent', $this->approvalRow($readyId)->telegram_delivery_state);
        foreach ($before as $id => $row) {
            $this->assertSame($row, (array) $this->approvalRow($id), "ineligible approval {$id} must stay byte-equal");
        }
        Queue::assertNothingPushed();
    }

    /** @return array<string, array{array}> */
    public static function ineligibleLowIdCards(): array
    {
        return [
            'five expire exactly now' => [array_fill(0, 5, ['expires_at' => self::NOW])],
            'five non-pending statuses with new state' => [[
                ['status' => 'expired'],
                ['status' => 'skipped'],
                ['status' => 'cancelled'],
                ['status' => 'approved'],
                ['status' => 'sent'],
            ]],
        ];
    }

    #[Test]
    public function test_approval_of_a_closed_bot_is_never_offered(): void
    {
        $bot = $this->seedOpenBot();
        $closed = $this->seedOpenBot();
        DB::table('bot_settings')->where('bot_id', $closed->id)->update(['follow_up_enabled' => false]);
        config(['customer_follow_up.bot_ids' => [$bot->id, $closed->id]]);
        $room = $this->makeSilentSaleRoom($closed, 'UDCLOSED');
        FollowUpSalesProjector::refresh($room, $this->clock());
        $log = app(FollowUpReservationService::class)->candidate($room, $this->clock());
        DB::table('lead_recovery_logs')->where('id', $log->id)->update(['delivery_status' => 'pending']);
        $approvalId = $this->makeApproval((int) $log->id);
        $before = DB::table('follow_up_approvals')->where('id', $approvalId)->first();
        $this->fakeModelApis();
        Queue::fake();

        $this->runScan();

        $this->assertSame([], $this->sendMessageRequests(), 'a closed bot gets no card offer');
        $this->assertSame([], $this->publishedIds, 'a closed bot must not call publish');
        $this->assertSame((array) $before, (array) DB::table('follow_up_approvals')->where('id', $approvalId)->first());
    }

    // ------------------------------------------------------------------
    // ห้องล้มรายห้องไม่หยุดสแกน — warning เดียว ไม่พา message
    // ------------------------------------------------------------------

    #[Test]
    public function test_one_failing_room_does_not_stop_the_scan(): void
    {
        $bot = $this->seedOpenBot();
        $failing = $this->makeSilentSaleRoom($bot, 'UF1');
        $survivorA = $this->makeSilentSaleRoom($bot, 'UF2');
        $survivorB = $this->makeSilentSaleRoom($bot, 'UF3');
        $this->fakeModelApis();
        Queue::fake();
        $this->startLogCapture();

        $job = new class($failing->id) extends ProcessCustomerFollowUps
        {
            public function __construct(private readonly int $failRoomId) {}

            protected function refreshOne(Conversation $room, CarbonImmutable $now): void
            {
                if ($room->id === $this->failRoomId) {
                    throw new RuntimeException('refresh boom leak-marker-'.'b6d');
                }

                parent::refreshOne($room, $now);
            }
        };

        $this->runScan($job);

        $warnings = array_values(array_filter($this->logCalls, fn (array $call): bool => $call['level'] === 'warning'));
        $this->assertCount(1, $warnings, 'exactly one warning line');
        $this->assertSame(['bot_id', 'room_id', 'exception_class'], array_keys($warnings[0]['context']));
        $this->assertSame($failing->id, $warnings[0]['context']['room_id']);
        $this->assertSame(RuntimeException::class, $warnings[0]['context']['exception_class']);
        $this->assertStringNotContainsString(self::FAILURE_MARKER, $warnings[0]['message'].json_encode($warnings[0]['context']));
        foreach ([$survivorA, $survivorB] as $room) {
            $this->assertSame(1, DB::table('follow_up_sales_signals')->where('conversation_id', $room->id)->count());
            $this->assertSame(1, DB::table('lead_recovery_logs')->where('conversation_id', $room->id)->count());
        }
        $this->assertSame(0, DB::table('lead_recovery_logs')->where('conversation_id', $failing->id)->count());

        Queue::fake();
        $this->startLogCapture();
        $reservations = $this->mock(FollowUpReservationService::class)->makePartial();
        $reservations->shouldReceive('candidate')
            ->once()
            ->withArgs(fn (Conversation $room): bool => $room->id === $failing->id)
            ->andThrow(new RuntimeException('candidate boom '.self::FAILURE_MARKER));
        $reservations->shouldReceive('candidate')->passthru();

        $this->runScan(null, $reservations);

        $warnings = array_values(array_filter($this->logCalls, fn (array $call): bool => $call['level'] === 'warning'));
        $this->assertCount(1, $warnings, 'exactly one warning line for the failing candidate call');
        $this->assertSame($failing->id, $warnings[0]['context']['room_id']);
        $this->assertStringNotContainsString(self::FAILURE_MARKER, $warnings[0]['message'].json_encode($warnings[0]['context']));
        foreach ([$survivorA, $survivorB] as $room) {
            $this->assertSame(1, DB::table('lead_recovery_logs')->where('conversation_id', $room->id)->count(), 'other rooms still gain their candidates');
        }
    }

    // ------------------------------------------------------------------
    // Job ร่าง (GenerateCustomerFollowUp) — เสนอการ์ดทันทีที่ร่างพร้อม (D98)
    // ------------------------------------------------------------------

    #[Test]
    public function test_drafting_job_publishes_card_right_after_prepare(): void
    {
        $bot = $this->seedOpenBot();
        $room = $this->makeSilentPaymentRoom($bot, 'UJ1', $this->clock());
        FollowUpSalesProjector::refresh($room, $this->clock());
        $log = app(FollowUpReservationService::class)->candidate($room, $this->clock());
        $draft = 'ทางร้านขออนุญาตถามหน่อยครับ ยังสนใจรายการเดิมอยู่ไหมครับ';
        $this->fakeModelApis();
        // ห้องจ่ายเงินต้อง sort ออกมาเป็น G2 (กลุ่มจ่ายเงิน) — G3 จะโดนหน่วงรอกรอบขาย
        self::setSortAnswer(['group' => ['choice' => 'G2', 'confidence' => 0.9], 'declined' => ['noul' => 0.1]]);
        $this->fakeWriterMany($draft);

        (new GenerateCustomerFollowUp($log->id))->handle(app(FollowUpPipeline::class), app(FollowUpTelegramService::class));

        $approval = DB::table('follow_up_approvals')->where('lead_recovery_log_id', $log->id)->first();
        $this->assertNotNull($approval);
        $this->assertSame('pending', (string) $approval->status);
        $this->assertSame('sent', (string) $approval->telegram_delivery_state);
        $this->assertSame(4242, $approval->telegram_message_id === null ? null : (int) $approval->telegram_message_id, 'the accepted card message id must be stored');
        $this->assertCount(1, $this->sendMessageRequests(), 'exactly one sendMessage');
        $this->assertSame([(int) $approval->id], $this->publishedIds);
        $this->assertStringContainsString($draft, (string) $this->sendMessageRequests()[0]['data']['text']);
    }

    #[Test]
    #[DataProvider('draftingJobSilentOutcomes')]
    public function test_drafting_job_outcomes_without_approval_stay_silent(Closure $fixture, array $expectedStatusReason): void
    {
        $bot = $this->seedOpenBot();
        $room = $this->makeSilentPaymentRoom($bot, 'UJ2', $this->clock());
        FollowUpSalesProjector::refresh($room, $this->clock());
        $log = app(FollowUpReservationService::class)->candidate($room, $this->clock());
        $this->fakeModelApis();
        $this->fakeWriterMany();
        $fixture($log->id);

        (new GenerateCustomerFollowUp($log->id))->handle(app(FollowUpPipeline::class), app(FollowUpTelegramService::class));

        $this->assertSame([], $this->sendMessageRequests(), 'no Telegram request');
        $this->assertSame([], $this->publishedIds);
        $row = DB::table('lead_recovery_logs')->where('id', $log->id)->first();
        $this->assertSame($expectedStatusReason['status'], (string) $row->delivery_status, 'wrong final status');
        if (array_key_exists('reason', $expectedStatusReason)) {
            $this->assertSame($expectedStatusReason['reason'], (string) $row->reason_code, 'wrong reason');
        }
        $this->assertNull(DB::table('follow_up_approvals')->where('lead_recovery_log_id', $log->id)->first(), 'no approval row');
    }

    /** @return array<string, array{Closure, array}> */
    public static function draftingJobSilentOutcomes(): array
    {
        return [
            'excluded by sort G1' => [
                function (int $logId): void {
                    self::setSortAnswer(['group' => ['choice' => 'G1', 'confidence' => 0.9], 'declined' => ['noul' => 0.1]]);
                },
                ['status' => 'excluded', 'reason' => 'group_g1'],
            ],
            'cancelled by new bot message' => [
                function (int $logId): void {
                    $conversationId = (int) DB::table('lead_recovery_logs')->where('id', $logId)->value('conversation_id');
                    DB::table('messages')->insertGetId([
                        'conversation_id' => $conversationId,
                        'sender' => 'bot',
                        'content' => 'มีอะไรเพิ่มเติมไหมครับ',
                        'type' => 'text',
                        'created_at' => '2026-11-16 09:00:00',
                        'updated_at' => '2026-11-16 09:00:00',
                    ]);
                },
                ['status' => 'cancelled', 'reason' => 'new_bot_message'],
            ],
            'deferred waiting for sale window' => [
                function (int $logId): void {
                    // ห้องจ่ายเงินเงียบ 5 ชม. + sort G3 → ยังไม่ครบกรอบขาย 24 ชม. = deferred
                },
                ['status' => 'deferred', 'reason' => 'wait_for_sale_window'],
            ],
            'sort retry after decision failure' => [
                function (int $logId): void {
                    self::setSortStatus(500);
                },
                ['status' => 'sort_retry'],
            ],
            'candidate put back outside hours' => [
                function (int $logId): void {
                    self::setClockStatic('2026-11-16 23:00:00');
                },
                ['status' => 'candidate', 'reason' => 'outside_hours'],
            ],
        ];
    }

    #[Test]
    #[DataProvider('duplicateDeliveryStates')]
    public function test_duplicate_drafting_job_states_are_not_republished(string $state, string $updatedAt): void
    {
        $bot = $this->seedOpenBot();
        $room = $this->makeSilentPaymentRoom($bot, 'UJ3', $this->clock());
        FollowUpSalesProjector::refresh($room, $this->clock());
        $log = app(FollowUpReservationService::class)->candidate($room, $this->clock());
        $this->fakeModelApis();
        self::setSortAnswer(['group' => ['choice' => 'G2', 'confidence' => 0.9], 'declined' => ['noul' => 0.1]]);
        $this->fakeWriterMany();

        app(FollowUpPipeline::class)->prepare($log->id, $this->clock());
        $approval = DB::table('follow_up_approvals')->where('lead_recovery_log_id', $log->id)->first();
        $this->assertNotNull($approval);
        DB::table('follow_up_approvals')->where('id', $approval->id)->update([
            'telegram_delivery_state' => $state,
            'updated_at' => $updatedAt,
        ]);
        $before = DB::table('follow_up_approvals')->where('id', $approval->id)->first();

        $this->app->call([new GenerateCustomerFollowUp($log->id), 'handle']);

        $this->assertSame([], $this->sendMessageRequests(), "state {$state} must not republish");
        $this->assertSame([], $this->publishedIds, "state {$state} must not call publish even when the service would make no request");
        $this->assertSame((array) $before, (array) DB::table('follow_up_approvals')->where('id', $approval->id)->first(), "state {$state} row must stay untouched");
    }

    /** @return array<string, array{string, string}> */
    public static function duplicateDeliveryStates(): array
    {
        return [
            'sent' => ['sent', '2026-11-16 09:00:00'],
            'sending young' => ['sending', '2026-11-16 09:59:30'],
            'sending older than 120 seconds' => ['sending', '2026-11-16 09:57:59'],
            'failed' => ['failed', '2026-11-16 09:00:00'],
            'unknown' => ['unknown', '2026-11-16 09:00:00'],
        ];
    }

    #[Test]
    #[DataProvider('nonPendingApprovalStatuses')]
    public function test_drafting_job_does_not_publish_a_non_pending_new_approval(string $status): void
    {
        $bot = $this->seedOpenBot();
        $approvalId = $this->seedPendingApproval($bot, 'UJOBSTATUS', [
            'status' => $status,
            'updated_at' => '2026-11-16 09:00:00',
        ]);
        $approval = $this->approvalRow($approvalId);
        $before = (array) $approval;
        $this->fakeModelApis();

        $this->app->call([new GenerateCustomerFollowUp((int) $approval->lead_recovery_log_id), 'handle']);

        $this->assertSame([], $this->publishedIds, 'the job must filter non-pending approvals before publish');
        $this->assertSame([], $this->httpRequests);
        $this->assertSame($before, (array) $this->approvalRow($approvalId));
    }

    /** @return array<string, array{string}> */
    public static function nonPendingApprovalStatuses(): array
    {
        return [
            'approved' => ['approved'],
            'skipped' => ['skipped'],
            'expired' => ['expired'],
            'cancelled' => ['cancelled'],
            'sent' => ['sent'],
            'editing' => ['editing'],
        ];
    }

    #[Test]
    public function test_drafting_job_prepare_exception_propagates_without_telegram(): void
    {
        $this->fakeModelApis();
        $pipeline = $this->mock(FollowUpPipeline::class);
        $pipeline->shouldReceive('prepare')->once()->with(7)->andThrow(new RuntimeException('prepare boom'));
        $telegram = $this->mock(FollowUpTelegramService::class);
        $telegram->shouldReceive('publish')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('prepare boom');

        (new GenerateCustomerFollowUp(7))->handle($pipeline, $telegram);
    }

    #[Test]
    public function test_drafting_job_contract_tries_timeout_and_payload(): void
    {
        $job = new GenerateCustomerFollowUp(7);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(1, $job->tries);
        $this->assertSame(180, $job->timeout);

        $differences = [];
        $otherVars = get_object_vars(new GenerateCustomerFollowUp(8));
        $jobVars = get_object_vars($job);
        foreach ($otherVars as $prop => $value) {
            if ($jobVars[$prop] !== $value) {
                $differences[] = $prop;
            }
        }
        $this->assertSame(['logId'], $differences, 'two payloads differ only in logId');
    }

    // ------------------------------------------------------------------
    // End to end — สแกนหนึ่งครั้งจนถึงการ์ด, รอบสองไม่มีอะไรเกิดขึ้น
    // ------------------------------------------------------------------

    #[Test]
    public function test_end_to_end_scan_to_card_on_sync_queue(): void
    {
        config(['queue.default' => 'sync']);
        $bot = $this->seedOpenBot();
        $room = $this->makeSilentPaymentRoom($bot, 'UE2E', $this->clock());
        $this->fakeModelApis();
        // ห้องจ่ายเงิน + G2 → เดินต่อถึง pending (G3 จะโดนหน่วงรอกรอบขาย)
        self::setSortAnswer(['group' => ['choice' => 'G2', 'confidence' => 0.9], 'declined' => ['noul' => 0.1]]);
        $this->fakeWriterMany();

        dispatch(new ProcessCustomerFollowUps);

        $log = DB::table('lead_recovery_logs')->where('bot_id', $bot->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('pending', (string) $log->delivery_status);
        $approval = DB::table('follow_up_approvals')->where('lead_recovery_log_id', $log->id)->first();
        $this->assertNotNull($approval);
        $this->assertSame('sent', (string) $approval->telegram_delivery_state);
        $this->assertCount(1, $this->sendMessageRequests(), 'exactly one Telegram request');
        $this->assertSame([], array_values(array_filter($this->httpRequests, fn (array $entry): bool => str_contains($entry['url'], 'api.line.me'))), 'zero requests to LINE');
        $this->assertCount(2, array_values(array_filter($this->httpRequests, fn (array $entry): bool => str_contains($entry['url'], 'openrouter'))), 'sort + check decisions');

        $approvalAfter = (array) DB::table('follow_up_approvals')->where('id', $approval->id)->first();
        $logAfter = (array) DB::table('lead_recovery_logs')->where('id', $log->id)->first();

        $this->setClock('2026-11-16 10:15:00');
        $this->captureQueries();
        $requestCountBefore = count($this->httpRequests);

        dispatch(new ProcessCustomerFollowUps);

        $this->assertCount($requestCountBefore, $this->httpRequests, 'the second scan makes zero requests of any kind');
        $this->assertSame(['follow_up_sales_signals'], $this->writeTableNames(), 'the second scan only rewrites signal rows');
        $this->assertSame($approvalAfter, (array) DB::table('follow_up_approvals')->where('id', $approval->id)->first());
        $this->assertSame($logAfter, (array) DB::table('lead_recovery_logs')->where('id', $log->id)->first());
    }

    // ------------------------------------------------------------------
    // Schedule entry
    // ------------------------------------------------------------------

    #[Test]
    public function test_schedule_registers_the_fifteen_minute_named_entry(): void
    {
        $this->artisan('schedule:list')->assertExitCode(0);
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event): bool => $event->getSummaryForDisplay() === 'customer-follow-up-scan'
        ));

        $this->assertCount(1, $events, 'exactly one entry named customer-follow-up-scan');
        $event = $events[0];
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(5, $event->expiresAt);

        $callback = (new ReflectionProperty($event, 'callback'))->getValue($event);
        $job = (new ReflectionFunction($callback))->getStaticVariables()['job'];
        $this->assertInstanceOf(ProcessCustomerFollowUps::class, $job);

        Queue::fake();
        $event->run($this->app->make(Container::class));
        Queue::assertPushed(ProcessCustomerFollowUps::class, 1);
    }

    // ------------------------------------------------------------------
    // Fixtures and helpers
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // Step E — งานส่งค้าง (D104): รันก่อน gate, (r)/(s) เสมอ, (a) เมื่อบอทเปิดเท่านั้น
    // ------------------------------------------------------------------

    /** เมทริกซ์เคส step E — คืน case => approval id */
    private function seedMatrix(Bot $bot): array
    {
        $retryBase = [
            'delivery_status' => 'retry',
            'next_attempt_at' => '2026-11-16 10:00:00',
            'updated_at' => '2026-11-16 09:52:00',
            'first_push_at' => '2026-11-16 09:52:00',
            'message_sent' => 'ร่างทดสอบ step E ส่งงาน',
            'attempt_number' => 1,
            'retry_key' => 'uuid-retry-base',
        ];
        $sendingBase = [
            'delivery_status' => 'sending',
            'updated_at' => '2026-11-16 09:50:00',
            'claimed_at' => '2026-11-16 09:50:00',
            'claim_token' => 'claim-token-base',
            'first_push_at' => '2026-11-16 09:50:00',
            'message_sent' => 'ร่างทดสอบ step E ส่งงาน',
            'attempt_number' => 1,
            'retry_key' => 'uuid-sending-base',
        ];
        $approvedBase = ['delivery_status' => 'approved', 'updated_at' => '2026-11-16 09:58:00'];
        $sendingApproval = ['status' => 'sending'];

        $cases = [
            'retry_due' => [$retryBase, $sendingApproval],
            'retry_not_due' => [array_merge($retryBase, ['next_attempt_at' => '2026-11-16 23:00:00']), $sendingApproval],
            'retry_null_next' => [array_merge($retryBase, ['next_attempt_at' => null]), $sendingApproval],
            'sending_10m' => [$sendingBase, $sendingApproval],
            'sending_9m59' => [array_merge($sendingBase, ['updated_at' => '2026-11-16 09:50:01']), $sendingApproval],
            'approved_2m' => [$approvedBase, ['status' => 'approved']],
            'approved_1m59' => [array_merge($approvedBase, ['updated_at' => '2026-11-16 09:58:01']), ['status' => 'approved']],
            'log_approved_appr_pending' => [array_merge($approvedBase, ['updated_at' => '2026-11-16 09:00:00']), ['status' => 'pending']],
            'log_retry_appr_approved' => [array_merge($retryBase, ['next_attempt_at' => '2026-11-16 09:00:00', 'updated_at' => '2026-11-16 09:00:00']), ['status' => 'approved']],
            'pending_pair' => [['delivery_status' => 'pending'], ['status' => 'pending']],
            'sent_pair' => [['delivery_status' => 'sent', 'sent_at' => '2026-11-16 09:00:00'], ['status' => 'sent']],
            'send_failed_pair' => [['delivery_status' => 'send_failed'], ['status' => 'cancelled']],
            'skipped_pair' => [['delivery_status' => 'skipped'], ['status' => 'skipped']],
            'expired_pair' => [['delivery_status' => 'expired'], ['status' => 'expired']],
            'cancelled_pair' => [['delivery_status' => 'cancelled'], ['status' => 'cancelled']],
        ];

        $ids = [];
        foreach ($cases as $name => [$logOverrides, $approvalOverrides]) {
            $ids[$name] = $this->seedFollowPair($bot, $logOverrides, $approvalOverrides);
        }

        return $ids;
    }

    /** คู่ log + approval สำหรับ step E — คืน approval id */
    private function seedFollowPair(Bot $bot, array $logOverrides = [], array $approvalOverrides = []): int
    {
        $room = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => 'UE'.bin2hex(random_bytes(8)),
        ]);
        $logId = (int) DB::table('lead_recovery_logs')->insertGetId(array_merge([
            'conversation_id' => $room->id,
            'bot_id' => $bot->id,
            'attempt_number' => 0,
            'attempt_count' => 0,
            'message_mode' => 'ai',
            'delivery_status' => 'approved',
            'customer_key' => hash('sha256', $bot->id.'|line|'.$room->external_customer_id),
            'draft' => 'ร่างทดสอบ step E ส่งงาน',
            'drafted_at' => '2026-11-16 09:30:00',
            'draft_day' => '2026-11-16',
            'draft_model' => 'openai/gpt-6-luna-writer',
            'check_answers' => json_encode(['scores' => [], 'failed' => [], 'error' => false, 'version' => 1]),
            'owner_action' => 'sent',
            'owner_action_at' => '2026-11-16 09:55:00',
            'owner_telegram_user_id' => '555',
            'created_at' => '2026-11-16 09:55:00',
            'updated_at' => '2026-11-16 09:55:00',
        ], $logOverrides));

        return (int) DB::table('follow_up_approvals')->insertGetId(array_merge([
            'lead_recovery_log_id' => $logId,
            'status' => 'approved',
            'revision' => 1,
            'nonce' => 'ef12cd34ab56ab78',
            'text' => 'ร่างทดสอบ step E ส่งงาน',
            'check_answers' => json_encode(['scores' => [], 'failed' => [], 'error' => false, 'version' => 1]),
            'expires_at' => '2026-11-16 22:00:00',
            'telegram_delivery_state' => 'new',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ], $approvalOverrides));
    }

    /** @return list<int> approval id ของงาน SendCustomerFollowUp ที่ถูก push ตามจังหวะ */
    private function pushedSendIds(): array
    {
        return array_map(
            fn (array $pushed): int => $pushed['job']->approvalId,
            Queue::pushedJobs()[SendCustomerFollowUp::class] ?? [],
        );
    }

    /** @return array<int, array{name: string, claimed: bool}> ผล send ต่อเคสเมทริกซ์ */
    private function sendProof(Bot $bot): array
    {
        $proofIds = $this->seedMatrix($bot);
        $rows = [];
        foreach ($proofIds as $name => $approvalId) {
            $result = app(FollowUpSendService::class)->send($approvalId, $this->clock());
            $reason = null;
            foreach (array_reverse($this->logCalls) as $call) {
                if ($call['level'] === 'info' && $call['message'] === 'Customer follow-up send'
                    && ($call['context']['approval_id'] ?? null) === $approvalId) {
                    $reason = $call['context']['reason'] ?? null;
                    break;
                }
            }
            $rows[] = ['name' => $name, 'claimed' => $result !== 'blocked' || $reason !== 'claim_refused'];
        }

        return $rows;
    }

    #[Test]
    public function test_step_e_open_bot_queues_exactly_the_claimable_rows_in_order(): void
    {
        $bot = $this->seedOpenBot();
        $ids = $this->seedMatrix($bot);
        // แถวของบอทอื่น: สแกนของบอทนี้ห้ามแตะ (join ตาม bot_id)
        $other = $this->seedOpenBot();
        $ids['other_bot'] = $this->seedFollowPair($other, [
            'delivery_status' => 'retry',
            'next_attempt_at' => '2026-11-16 09:59:00',
            'updated_at' => '2026-11-16 09:52:00',
            'first_push_at' => '2026-11-16 09:52:00',
            'message_sent' => 'ร่างทดสอบ step E ส่งงาน',
            'attempt_number' => 1,
            'retry_key' => 'uuid-other-bot',
        ], ['status' => 'sending']);
        config(['customer_follow_up.bot_ids' => [$bot->id]]);

        Queue::fake();
        $this->startLogCapture();
        $this->runScan();

        $this->assertSame(
            [$ids['retry_due'], $ids['sending_10m'], $ids['approved_2m']],
            $this->pushedSendIds(),
            'คิวเฉพาะแถวที่ถึงคิว/ค้าง/อนุมัติค้าง เรียง id น้อยไปมาก'
        );
        $infoLines = array_values(array_filter($this->logCalls, fn (array $call): bool => $call['level'] === 'info'));
        $this->assertSame(3, $infoLines[0]['context']['send_jobs_queued'] ?? -1);

        // พิสูจน์ที่สอง: send ในวินาทีเดียวกัน claims ชุดเดียวกับที่ step E คิว
        $claimed = array_values(array_filter(
            $this->sendProof($bot),
            fn (array $row): bool => $row['claimed'],
        ));
        $this->assertSame(
            ['retry_due', 'sending_10m', 'approved_2m'],
            array_column($claimed, 'name'),
            'send claims exactly the rows step E queued',
        );
    }

    #[Test]
    public function test_step_e_closed_bot_queues_only_due_retry_and_stale_sending(): void
    {
        // บอทปิดแต่ไม่มีแถว (r)/(s) — เหมือน B6d เดิม: SELECT เท่านั้น ไม่คิว ไม่มี info line
        $quiet = $this->seedOpenBot();
        DB::table('bot_settings')->where('bot_id', $quiet->id)->update(['follow_up_enabled' => false]);
        $this->seedFollowPair($quiet, ['delivery_status' => 'approved', 'updated_at' => '2026-11-16 09:00:00']);
        $this->seedFollowPair($quiet, ['delivery_status' => 'pending'], ['status' => 'pending']);
        config(['customer_follow_up.bot_ids' => [$quiet->id]]);
        Queue::fake();
        $this->captureQueries();
        $this->startLogCapture();
        $this->runScan();
        $this->assertSame([], $this->pushedSendIds(), 'บอทปิดไม่มีแถวค้าง = ไม่คิวงาน');
        $this->assertSame([], array_values(array_filter($this->logCalls, fn (array $call): bool => $call['level'] === 'info')), 'ไม่มี info line');
        $this->assertSame([], $this->writeTableNames(), 'บอทปิด SELECT เท่านั้น');

        // บอทปิดด้วยสวิตช์ + มีแถว (r) ถึงคิว และ (s) ค้าง → คิวทั้งคู่ ไม่รับ (a)
        $switch = $this->seedOpenBot();
        DB::table('bot_settings')->where('bot_id', $switch->id)->update(['follow_up_enabled' => false]);
        $ids = $this->seedMatrix($switch);
        config(['customer_follow_up.bot_ids' => [$switch->id]]);
        Queue::fake();
        $this->captureQueries();
        $this->startLogCapture();
        $this->runScan();
        $this->assertSame([$ids['retry_due'], $ids['sending_10m']], $this->pushedSendIds(), 'ปิดสวิตช์: คิว (r)+(s) เท่านั้น');
        $this->assertSame([], $this->writeTableNames(), 'การคิวงานเขียนอะไรลงตารางไม่ได้');
        $infoLines = array_values(array_filter($this->logCalls, fn (array $call): bool => $call['level'] === 'info'));
        $this->assertCount(1, $infoLines, 'คิวแล้วต้องมี info line หนึ่งบรรทัด');
        $context = $infoLines[0]['context'];
        $this->assertSame($switch->id, $context['bot_id']);
        $this->assertSame(0, $context['rooms_refreshed']);
        $this->assertSame(0, $context['rooms_failed']);
        $this->assertSame(0, $context['eligible_rooms']);
        $this->assertSame(0, $context['candidates_created']);
        $this->assertSame(0, $context['jobs_queued']);
        $this->assertSame(0, $context['cards_offered']);
        $this->assertSame([], $context['card_results']);
        $this->assertSame(2, $context['send_jobs_queued']);

        // บอทเปิดสวิตช์แต่เลยเวลา 23:00 → เหมือนปิด: (r)+(s) เท่านั้น
        $night = $this->seedOpenBot();
        $nightIds = $this->seedMatrix($night);
        config(['customer_follow_up.bot_ids' => [$night->id]]);
        $this->setClock('2026-11-16 23:00:00');
        Queue::fake();
        $this->startLogCapture();
        $this->runScan();
        $this->assertSame([$nightIds['retry_due'], $nightIds['sending_10m']], $this->pushedSendIds(), 'นอกเวลา: คิว (r)+(s) เท่านั้น');
        $nightLines = array_values(array_filter($this->logCalls, fn (array $call): bool => $call['level'] === 'info'));
        $this->assertCount(1, $nightLines);
        $this->assertSame(2, $nightLines[0]['context']['send_jobs_queued']);
    }

    #[Test]
    public function test_step_e_limit_does_not_take_non_qualifying_slots(): void
    {
        $bot = $this->seedOpenBot();
        $blockers = [
            [['delivery_status' => 'retry', 'next_attempt_at' => '2026-11-16 23:00:00', 'updated_at' => '2026-11-16 09:52:00', 'first_push_at' => '2026-11-16 09:52:00', 'message_sent' => 'ร่าง', 'attempt_number' => 1, 'retry_key' => 'k'], ['status' => 'sending']],
            [['delivery_status' => 'sending', 'updated_at' => '2026-11-16 09:51:01', 'first_push_at' => '2026-11-16 09:51:01', 'message_sent' => 'ร่าง', 'attempt_number' => 1, 'retry_key' => 'k'], ['status' => 'sending']],
            [['delivery_status' => 'approved', 'updated_at' => '2026-11-16 09:59:01'], ['status' => 'approved']],
            [['delivery_status' => 'approved', 'updated_at' => '2026-11-16 09:00:00'], ['status' => 'pending']],
        ];
        $blockerIds = [];
        for ($i = 0; $i < 20; $i++) {
            [$logOverrides, $approvalOverrides] = $blockers[$i % 4];
            $blockerIds[] = $this->seedFollowPair($bot, $logOverrides, $approvalOverrides);
        }
        $due = $this->seedFollowPair($bot, [
            'delivery_status' => 'retry',
            'next_attempt_at' => '2026-11-16 09:59:00',
            'updated_at' => '2026-11-16 09:52:00',
            'first_push_at' => '2026-11-16 09:52:00',
            'message_sent' => 'ร่าง',
            'attempt_number' => 1,
            'retry_key' => 'k-due',
        ], ['status' => 'sending']);

        sort($blockerIds);
        $before = DB::table('follow_up_approvals')->whereIn('id', $blockerIds)->orderBy('id')->get()
            ->map(fn (object $row): array => (array) $row)->all();
        Queue::fake();
        $this->runScan();

        $this->assertSame([$due], $this->pushedSendIds(), 'ตำแหน่ง 20 ตัวต้องไม่รับแถวที่ไม่เข้าเงื่อนไข');
        $after = DB::table('follow_up_approvals')->whereIn('id', $blockerIds)->orderBy('id')->get()
            ->map(fn (object $row): array => (array) $row)->all();
        $this->assertSame($before, $after, 'แถวที่ไม่เข้าเงื่อนไขคงเดิมทุกไบต์');
    }

    #[Test]
    public function test_step_e_queues_the_20_lowest_due_ids_and_starts_from_the_lowest_next_scan(): void
    {
        $bot = $this->seedOpenBot();
        $ids = [];
        for ($i = 0; $i < 21; $i++) {
            $ids[] = $this->seedFollowPair($bot, [
                'delivery_status' => 'retry',
                'next_attempt_at' => '2026-11-16 09:59:00',
                'updated_at' => '2026-11-16 09:52:00',
                'first_push_at' => '2026-11-16 09:52:00',
                'message_sent' => 'ร่าง',
                'attempt_number' => 1,
                'retry_key' => 'k'.$i,
            ], ['status' => 'sending']);
        }
        $expected = array_slice($ids, 0, 20);

        Queue::fake();
        $this->runScan();
        $this->assertSame($expected, $this->pushedSendIds(), 'ยี่สิบ id น้อยสุด ตัวใหญ่สุดตกคิว');

        Queue::fake();
        $this->runScan();
        $this->assertSame($expected, $this->pushedSendIds(), 'สแกนรอบถัดไปยังเริ่มจาก id น้อยสุด');
    }

    #[Test]
    public function test_step_e_bot_id_without_a_bots_row_is_silent(): void
    {
        config(['customer_follow_up.bot_ids' => [424242]]);
        Queue::fake();
        $this->startLogCapture();

        $this->runScan();

        $this->assertSame([], $this->pushedSendIds());
        $this->assertSame([], array_values(array_filter($this->logCalls, fn (array $call): bool => $call['level'] === 'info')), 'ไม่มี bots row = เงียบทั้งหมด');
    }

    private function seedOpenBot(): Bot
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-6-luna-writer',
        ]);
        config(['customer_follow_up.bot_ids' => array_values(array_merge(
            (array) config('customer_follow_up.bot_ids', []),
            [$bot->id],
        ))]);

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
            'config' => ['access_token' => 'tok-'.'seed-'.md5((string) $bot->id), 'chat_id' => '999', 'authorized_user_ids' => ['555']],
        ]);

        return $bot;
    }

    private function makeSilentSaleRoom(Bot $bot, string $externalCustomerId): Conversation
    {
        $room = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => $externalCustomerId,
        ]);
        $this->addMessage($room->id, 'user', '2026-11-15 08:55:00', 'สนใจสินค้าครับ');
        $this->addMessage($room->id, 'user', '2026-11-15 08:59:00', 'ประเมินราคาให้หน่อยครับ');

        return $room;
    }

    private function makeSilentPaymentRoom(Bot $bot, string $externalCustomerId, CarbonImmutable $clock): Conversation
    {
        $room = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => $externalCustomerId,
        ]);
        $this->addMessage($room->id, 'user', $clock->subHours(5)->subMinutes(5)->format('Y-m-d H:i:s'), 'สนใจสินค้าครับ');
        $this->addMessage($room->id, 'user', $clock->subHours(5)->subMinutes(2)->format('Y-m-d H:i:s'), 'ประเมินราคาให้หน่อยครับ');
        $this->addMessage($room->id, 'bot', $clock->subHours(5)->format('Y-m-d H:i:s'), '[แจ้งเตือน Support] ทีมงานรับเรื่องไว้แล้ว รบกวนรอสักครู่นะครับ');

        return $room;
    }

    private function seedPendingApproval(Bot $bot, string $externalCustomerId, array $overrides = []): int
    {
        $room = $this->makeSilentSaleRoom($bot, $externalCustomerId);
        FollowUpSalesProjector::refresh($room, $this->clock());
        $log = app(FollowUpReservationService::class)->candidate($room, $this->clock());
        DB::table('lead_recovery_logs')->where('id', $log->id)->update(['delivery_status' => 'pending']);

        return $this->makeApproval((int) $log->id, $overrides);
    }

    private function makeApproval(int $logId, array $overrides = []): int
    {
        $row = array_merge([
            'lead_recovery_log_id' => $logId,
            'status' => 'pending',
            'revision' => 1,
            'nonce' => 'ab12cd34ef56ab78',
            'text' => 'ร่างทดสอบการ์ด B6d',
            'check_answers' => json_encode(['scores' => [], 'failed' => [], 'error' => false, 'version' => 1]),
            'check_overridden' => false,
            'expires_at' => '2026-11-16 22:00:00',
            'telegram_delivery_state' => 'new',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ], $overrides);

        return (int) DB::table('follow_up_approvals')->insertGetId($row);
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
     * fake ทุกเส้น HTTP ที่ยอมให้เกิด: Decisions (sort/check) และ Telegram sendMessage —
     * จับทุก request ไว้ตรวจ; คำตอบ sort อ่านจาก static override ณ เวลา request
     * (provider fixture ตั้งก่อน handle ได้); เส้นอื่น (LINE, อื่น ๆ) ไม่มี stub
     * จึงโดน preventStrayRequests
     */
    private function fakeModelApis(): void
    {
        $this->httpRequests = [];
        $this->publishedIds = [];
        $checkAnswer = array_fill_keys(array_keys(FollowUpPrompts::checkQuestions()), ['noul' => 0.1]);
        $sortUsage = ['input_tokens' => 100, 'output_tokens' => 20, 'cost' => 0.0001];
        $checkUsage = ['input_tokens' => 30, 'output_tokens' => 8, 'cost' => 0.0003];

        Http::fake(function (Request $request) use ($checkAnswer, $sortUsage, $checkUsage): mixed {
            $this->httpRequests[] = ['url' => (string) $request->url(), 'data' => $request->data()];

            if (str_contains($request->url(), 'openrouter')) {
                $body = $request->data();
                if (isset($body['questions']['group'])) {
                    $sortAnswer = self::$sortAnswerOverride ?? ['group' => ['choice' => 'G3', 'confidence' => 0.9], 'declined' => ['noul' => 0.1]];

                    return self::$sortStatusOverride === 200
                        ? Http::response(['answers' => $sortAnswer, 'usage' => $sortUsage])
                        : Http::response([], self::$sortStatusOverride);
                }

                return Http::response(['answers' => $checkAnswer, 'usage' => $checkUsage]);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 4242]], 200);
        });
    }

    /** @return list<array{url: string, data: array}> */
    private function sendMessageRequests(): array
    {
        return array_values(array_filter($this->httpRequests, fn (array $entry): bool => str_contains($entry['url'], '/sendMessage')));
    }

    /**
     * mock writer (OpenRouterService) ที่รับได้หลายครั้ง — prepare หลายแถวในเทสเดียว
     *
     * @return MockInterface&OpenRouterService
     */
    private function fakeWriterMany(?string $text = 'ทางร้านขออนุญาตถามหน่อยครับ ยังสนใจรายการเดิมอยู่ไหมครับ'): MockInterface
    {
        $mock = $this->mock(OpenRouterService::class);
        $mock->shouldReceive('chat')->zeroOrMoreTimes()->andReturnUsing(fn (): array => [
            'content' => $text,
            'reasoning' => null,
            'model' => 'openai/gpt-6-luna-writer',
            'usage' => [
                'prompt_tokens' => 50,
                'completion_tokens' => 12,
                'total_tokens' => 62,
                'cached_tokens' => 0,
                'reasoning_tokens' => 0,
                'cost' => 0.0002,
            ],
            'id' => 'chatcmpl-'.'b6d-fake',
            'finish_reason' => 'stop',
        ]);

        return $mock;
    }

    private function startLogCapture(): void
    {
        $this->logCalls = [];
        foreach (['warning', 'error', 'notice', 'info', 'critical', 'alert', 'emergency', 'debug'] as $level) {
            Log::shouldReceive($level)
                ->zeroOrMoreTimes()
                ->andReturnUsing(function (string $message, array $context = []) use ($level): void {
                    $this->logCalls[] = ['level' => $level, 'message' => $message, 'context' => $context];
                });
        }
    }

    private function captureQueries(): void
    {
        $this->queries = [];
        DB::listen(function (QueryExecuted $query): void {
            $this->queries[] = $query->sql;
        });
    }

    /** @return list<string> */
    private function writeTableNames(): array
    {
        $tables = [];
        foreach ($this->queries as $sql) {
            if (preg_match('/^(insert into|update|delete from)\s+"?([a-zA-Z0-9_]+)"?/i', trim($sql), $matches) === 1) {
                $tables[$matches[2]] = true;
            }
        }

        return array_keys($tables);
    }

    private function assertScanTouchedNothing(string $name): void
    {
        $nonSelect = array_values(array_filter(
            $this->queries,
            fn (string $sql): bool => ! str_starts_with(strtolower(trim($sql)), 'select')
        ));
        $this->assertSame([], $nonSelect, "[{$name}] a closed bot must see SELECT statements only");

        $pushed = Queue::pushedJobs();
        foreach ($pushed as $jobClass => $entries) {
            $this->assertSame([], $entries, "[{$name}] queue recorded {$jobClass}");
        }

        $this->assertSame([], $this->httpRequests, "[{$name}] no HTTP request at all");
    }

    private function runScan(?ProcessCustomerFollowUps $job = null, ?FollowUpReservationService $reservations = null): void
    {
        ($job ?? new ProcessCustomerFollowUps)->handle(
            $reservations ?? app(FollowUpReservationService::class),
            app(FollowUpTelegramService::class),
        );
    }

    private function approvalRow(int $approvalId): object
    {
        $row = DB::table('follow_up_approvals')->where('id', $approvalId)->first();
        $this->assertNotNull($row, 'approval row must exist');

        return $row;
    }

    private function clock(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Bangkok')->startOfSecond();
    }

    private function at(string $localBangkok): CarbonImmutable
    {
        return CarbonImmutable::parse($localBangkok, 'Asia/Bangkok');
    }

    private function setClock(string $localBangkok): void
    {
        Carbon::setTestNow($this->at($localBangkok));
        CarbonImmutable::setTestNow($this->at($localBangkok));
    }

    private static function setClockStatic(string $localBangkok): void
    {
        $at = CarbonImmutable::parse($localBangkok, 'Asia/Bangkok');
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    /** ตั้งคำตอบ sort ล่วงหน้าให้ stub ถัดไปใช้ — สำหรับ fixture closure ใน provider */
    private static ?array $sortAnswerOverride = null;

    private static int $sortStatusOverride = 200;

    private static function setSortAnswer(array $answer): void
    {
        self::$sortAnswerOverride = $answer;
    }

    private static function setSortStatus(int $status): void
    {
        self::$sortStatusOverride = $status;
    }
}
