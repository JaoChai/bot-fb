<?php

namespace Tests\Feature\FollowUp;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\LeadRecoveryLog;
use App\Models\User;
use App\Services\FollowUp\FollowUpCapabilities;
use App\Services\FollowUp\FollowUpQuotaService;
use App\Services\LINEService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด B4a — ตัวตัดสิน "บอทนี้ส่งข้อความตามลูกค้าอีกได้ไหม" (D42/D43)
 *
 * - check() ไม่มีแคช (D43): ยิง followUpQuota ใหม่ทุกครั้งผ่าน LINEService จริง (HTTP โดน fake)
 * - reserve ต้องเป็นจำนวนเต็มบวกจาก config ไม่งั้น fail-closed ทันที โดยไม่ยิง HTTP
 * - local_pending = แถว first_push_at ไม่ null และ (status sending/retry หรือ first_push_at >= 00:00:00 วันนี้กรุงเทพฯ)
 * - allowed = value - total_usage - local_pending - 1 >= reserve (เท่ากันพอดี = ผ่าน)
 */
class QuotaTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 10:00:00'; // วันจันทร์ 16 พ.ย. 2026 เวลากรุงเทพฯ

    private const TODAY_START = '2026-11-16 00:00:00';

    private const QUOTA_URL = 'https://api.line.me/v2/bot/message/quota';

    private const CONSUMPTION_URL = 'https://api.line.me/v2/bot/message/quota/consumption';

    private const CHECK_LOG_MESSAGE = 'Customer follow-up quota check';

    private FollowUpQuotaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new FollowUpQuotaService(new LINEService);
    }

    // ------------------------------------------------------------------
    // reserve จาก config — ไม่ใช่จำนวนเต็มบวก = fail-closed โดยไม่ยิง HTTP
    // ------------------------------------------------------------------

    #[Test]
    public function test_reserve_not_configured_stops_without_http(): void
    {
        $bot = $this->bot();
        Http::fake();
        Http::preventStrayRequests();

        foreach ([null, 0, -5, '10', 1.5, true] as $reserve) {
            config(['customer_follow_up.quota_reserve_messages' => $reserve]);

            $result = $this->service->check($bot, $this->now());

            $this->assertSame([
                'allowed' => false,
                'reason' => 'reserve_not_configured',
                'type' => null,
                'value' => null,
                'total_usage' => null,
                'local_pending' => 0,
                'reserve' => null,
            ], $result, 'reserve '.var_export($reserve, true).' ต้อง fail-closed');
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // โควต้าฝั่ง transport ล้มเหลว = บล็อกตามเหตุผลของ transport
    // ------------------------------------------------------------------

    #[Test]
    public function test_transport_failure_blocks_and_copies_reason(): void
    {
        $bot = $this->bot();
        config(['customer_follow_up.quota_reserve_messages' => 100]);

        $cases = [
            'quota_unreachable' => function () { throw new ConnectionException('cURL error 28'); },
            'quota_http_error' => Http::response('{"message":"Internal server error"}', 500),
            'quota_malformed' => Http::response('{}', 200),
        ];

        foreach ($cases as $reason => $stub) {
            Http::fake([
                self::QUOTA_URL => $stub,
                self::CONSUMPTION_URL => Http::response('{"totalUsage":1}', 200),
            ]);
            Http::preventStrayRequests();

            $result = $this->service->check($bot, $this->now());

            $this->assertSame([
                'allowed' => false,
                'reason' => $reason,
                'type' => null,
                'value' => null,
                'total_usage' => null,
                'local_pending' => 0,
                'reserve' => 100,
            ], $result, "transport ล้มเหลวแบบ {$reason} ต้องบล็อกด้วยเหตุผลเดียวกัน");
            Http::assertNotSent(fn ($request) => $request->url() === self::CONSUMPTION_URL);
        }
    }

    // ------------------------------------------------------------------
    // เส้นตัดสิน: value - usage - local_pending - 1 >= reserve (เท่ากันผ่าน)
    // ------------------------------------------------------------------

    #[Test]
    public function test_limited_boundary_equality_passes_below_stops(): void
    {
        $bot = $this->bot();
        config(['customer_follow_up.quota_reserve_messages' => 100]);

        // 1000 - 899 - 0 - 1 = 100 = reserve → ผ่านพอดี
        $this->fakeQuota('{"type":"limited","value":1000}', '{"totalUsage":899}');
        $result = $this->service->check($bot, $this->now());
        $this->assertSame([
            'allowed' => true,
            'reason' => null,
            'type' => 'limited',
            'value' => 1000,
            'total_usage' => 899,
            'local_pending' => 0,
            'reserve' => 100,
        ], $result);

        // 1000 - 900 - 0 - 1 = 99 < reserve → หยุด
        $this->fakeQuota('{"type":"limited","value":1000}', '{"totalUsage":900}');
        $result = $this->service->check($bot, $this->now());
        $this->assertSame([
            'allowed' => false,
            'reason' => 'below_reserve',
            'type' => 'limited',
            'value' => 1000,
            'total_usage' => 900,
            'local_pending' => 0,
            'reserve' => 100,
        ], $result);
    }

    #[Test]
    public function test_limited_zero_value_stops(): void
    {
        $bot = $this->bot();
        config(['customer_follow_up.quota_reserve_messages' => 100]);

        // 0 - 0 - 0 - 1 = -1 < reserve
        $this->fakeQuota('{"type":"limited","value":0}', '{"totalUsage":0}');
        $result = $this->service->check($bot, $this->now());

        $this->assertSame('below_reserve', $result['reason']);
        $this->assertFalse($result['allowed']);
    }

    #[Test]
    public function test_none_type_allows(): void
    {
        $bot = $this->bot();
        config(['customer_follow_up.quota_reserve_messages' => 100]);

        $this->fakeQuota('{"type":"none"}', '{"totalUsage":99999}');
        $result = $this->service->check($bot, $this->now());

        $this->assertSame([
            'allowed' => true,
            'reason' => null,
            'type' => 'none',
            'value' => null,
            'total_usage' => 99999,
            'local_pending' => 0,
            'reserve' => 100,
        ], $result);
    }

    // ------------------------------------------------------------------
    // local_pending — นับแถวที่ยิงแล้ว/กำลังไม่รู้ผล ของบอทนี้เท่านั้น
    // ------------------------------------------------------------------

    #[Test]
    public function test_local_pending_counts_relevant_rows_of_this_bot_only(): void
    {
        $bot = $this->bot();
        $otherBot = $this->bot();

        // นับ: สถานะ sending/retry ไม่ว่าเมื่อไร (ยังไม่รู้ผล) + แถวที่เริ่มยิงวันนี้กรุงเทพฯ ไม่ว่าสถานะ
        $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_SENDING, '2026-11-15 21:00:00');
        $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_RETRY, '2026-11-12 08:00:00');
        $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_SENT, '2026-11-16 00:10:00');
        $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_SEND_FAILED, '2026-11-16 06:00:00');
        $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_PENDING, '2026-11-16 01:00:00');

        // ไม่นับ: จบแล้วและไม่ใช่วันนี้ / ยังไม่เคยยิง / ของบอทอื่น
        $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_SENT, '2026-11-15 23:59:59');
        $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_SEND_FAILED, '2026-11-14 06:00:00');
        $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_SENDING, null);
        $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_APPROVED, null);
        $this->pushRow($otherBot, LeadRecoveryLog::DELIVERY_STATUS_SENDING, '2026-11-16 00:30:00');

        config(['customer_follow_up.quota_reserve_messages' => 100]);
        $this->fakeQuota('{"type":"limited","value":1000}', '{"totalUsage":890}');

        $result = $this->service->check($bot, $this->now());

        $this->assertSame(5, $result['local_pending']);
        // 1000 - 890 - 5 - 1 = 104 >= 100 → ยังส่งได้
        $this->assertTrue($result['allowed']);
    }

    #[Test]
    public function test_local_pending_changes_the_verdict_at_the_boundary(): void
    {
        $bot = $this->bot();

        for ($i = 0; $i < 10; $i++) {
            $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_SENT, '2026-11-16 0'.$i.':30:00');
        }

        config(['customer_follow_up.quota_reserve_messages' => 100]);

        // 1000 - 890 - 10 - 1 = 99 < reserve → หยุด
        $this->fakeQuota('{"type":"limited","value":1000}', '{"totalUsage":890}');
        $result = $this->service->check($bot, $this->now());
        $this->assertSame(10, $result['local_pending']);
        $this->assertFalse($result['allowed']);
        $this->assertSame('below_reserve', $result['reason']);

        // ตัดแถวหนึ่งออก (เช่นแถวที่กำลังจะส่ง) → 9 แถว → 100 >= reserve → ส่งได้
        $resultWithException = $this->service->check($bot, $this->now(), 1);
        $this->assertSame(9, $resultWithException['local_pending']);
        $this->assertTrue($resultWithException['allowed']);
    }

    #[Test]
    public function test_except_log_id_excludes_only_that_row(): void
    {
        $bot = $this->bot();

        $countedId = $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_SENDING, '2026-11-16 00:10:00');
        $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_RETRY, '2026-11-13 09:00:00');
        // แถวที่ไม่ถูกนับอยู่แล้ว ต้องไม่มีผลกับ exceptLogId
        $notCountedId = $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_SENT, '2026-11-15 23:59:59');

        config(['customer_follow_up.quota_reserve_messages' => 100]);
        $this->fakeQuota('{"type":"limited","value":1000}', '{"totalUsage":890}');

        $this->assertSame(2, $this->service->check($bot, $this->now())['local_pending']);
        $this->assertSame(1, $this->service->check($bot, $this->now(), $countedId)['local_pending']);
        $this->assertSame(2, $this->service->check($bot, $this->now(), $notCountedId)['local_pending']);
        $this->assertSame(2, $this->service->check($bot, $this->now(), $countedId + 999999)['local_pending']);
    }

    // ------------------------------------------------------------------
    // ขอบวันกรุงเทพฯ — "วันนี้" มาจาก $now ไม่ใช่เวลาเครื่อง
    // ------------------------------------------------------------------

    #[Test]
    public function test_bangkok_day_boundary_uses_bangkok_now(): void
    {
        $bot = $this->bot();

        $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_SENT, '2026-11-15 23:59:59');
        $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_SENT, self::TODAY_START); // เที่ยงคืนพอดี = นับ
        $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_SENT, '2026-11-16 00:10:00');

        config(['customer_follow_up.quota_reserve_messages' => 100]);
        $this->fakeQuota('{"type":"limited","value":100}', '{"totalUsage":0}');

        // now = 15 พ.ย. 17:30 UTC = 16 พ.ย. 00:30 กรุงเทพฯ → แถว 16 พ.ย. สองแถวเป็น "วันนี้"
        $utcEvening = new CarbonImmutable('2026-11-15 17:30:00', new \DateTimeZone('UTC'));
        $this->assertSame(2, $this->service->check($bot, $utcEvening)['local_pending']);

        // now ยังเป็น 15 พ.ย. 22:00 กรุงเทพฯ → แถว 23:59:59 เป็น "วันนี้" แถวเดียว
        // (แถว 16 พ.ย. เป็นอนาคต ไม่ใช่วันนี้ และ status จบแล้วจึงไม่นับ)
        $bangkokNight = CarbonImmutable::parse('2026-11-15 22:00:00', FollowUpCapabilities::TIMEZONE);
        $this->assertSame(1, $this->service->check($bot, $bangkokNight)['local_pending']);
    }

    // ------------------------------------------------------------------
    // check() อ่านอย่างเดียว — ไม่แตะแถวที่มีอยู่
    // ------------------------------------------------------------------

    #[Test]
    public function test_check_writes_nothing_to_the_rows(): void
    {
        $bot = $this->bot();
        $id = $this->pushRow($bot, LeadRecoveryLog::DELIVERY_STATUS_SENDING, '2026-11-16 00:10:00');

        config(['customer_follow_up.quota_reserve_messages' => 100]);
        $this->fakeQuota('{"type":"limited","value":100}', '{"totalUsage":0}');

        $before = (array) DB::table('lead_recovery_logs')->where('id', $id)->first();
        $this->service->check($bot, $this->now());
        $after = (array) DB::table('lead_recovery_logs')->where('id', $id)->first();

        $this->assertSame($before, $after);
    }

    // ------------------------------------------------------------------
    // log ต่อ call — มีแต่รหัสและตัวเลข 8 คีย์ตามการ์ด
    // ------------------------------------------------------------------

    #[Test]
    public function test_check_logs_one_line_with_codes_and_numbers_only(): void
    {
        $bot = $this->bot();
        config(['customer_follow_up.quota_reserve_messages' => 100]);

        $captured = [];
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
            Log::shouldReceive($level)->andReturnUsing(function (string $message, array $context = []) use (&$captured, $level) {
                $captured[] = ['level' => $level, 'message' => $message, 'context' => $context];
            });
        }

        $this->fakeQuota('{"type":"limited","value":100}', '{"totalUsage":0}');
        $this->service->check($bot, $this->now()); // allowed

        $this->fakeQuota('{"type":"limited","value":100}', '{"totalUsage":100}');
        $this->service->check($bot, $this->now()); // below_reserve

        config(['customer_follow_up.quota_reserve_messages' => null]);
        $this->service->check($bot, $this->now()); // reserve_not_configured

        $checkLines = array_values(array_filter(
            $captured,
            fn (array $entry) => $entry['message'] === self::CHECK_LOG_MESSAGE
        ));

        $this->assertCount(3, $checkLines);

        foreach ($checkLines as $entry) {
            $keys = array_keys($entry['context']);
            sort($keys);
            $this->assertSame(
                ['allowed', 'bot_id', 'local_pending', 'reason', 'reserve', 'total_usage', 'type', 'value'],
                $keys
            );
            $this->assertSame($bot->id, $entry['context']['bot_id']);
            $this->assertIsBool($entry['context']['allowed']);
            $this->assertIsInt($entry['context']['local_pending']);
        }

        // เรียงตามผลลัพธ์จริงของสามครั้ง
        $this->assertSame([true, false, false], array_map(
            fn (array $entry) => $entry['context']['allowed'],
            $checkLines
        ));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW, FollowUpCapabilities::TIMEZONE);
    }

    private function bot(): Bot
    {
        $user = User::factory()->owner()->create();

        return Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'channel_access_token' => implode('', ['fake', 'Quota', 'Token', 'Parts', '77']),
        ]);
    }

    private function fakeQuota(string $quotaBody, string $consumptionBody): void
    {
        Http::fake([
            self::QUOTA_URL => Http::response($quotaBody, 200),
            self::CONSUMPTION_URL => Http::response($consumptionBody, 200),
        ]);
        Http::preventStrayRequests();
    }

    private function pushRow(Bot $bot, string $status, ?string $firstPushAt): int
    {
        return DB::table('lead_recovery_logs')->insertGetId([
            'conversation_id' => Conversation::factory()->line()->create(['bot_id' => $bot->id])->id,
            'bot_id' => $bot->id,
            'attempt_number' => 1,
            'message_mode' => 'ai',
            'message_sent' => 'ข้อความทดสอบของการ์ด B4a',
            'sent_at' => '2026-11-15 10:00:00',
            'delivery_status' => $status,
            'first_push_at' => $firstPushAt,
            'created_at' => '2026-11-15 10:00:00',
            'updated_at' => '2026-11-15 10:00:00',
        ]);
    }
}
