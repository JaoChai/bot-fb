<?php

namespace Tests\Feature\FollowUp;

use App\Models\Bot;
use App\Services\LINEService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด B4a — transport ฝั่ง LINE ของระบบ "ตามลูกค้า" (plan §3.6 + D40/D41)
 *
 * - followUpQuota: GET quota + consumption เท่านั้น ไม่ throw คืน ok/reason/type/value/total_usage
 * - pushFollowUp: POST push ครั้งเดียวพร้อม X-Line-Retry-Key ไม่ throw ไม่ retry เอง
 *   คืน outcome accepted/retryable/rejected + status + request_id
 * - ทุกเคส HTTP โดน fake + preventStrayRequests: เทสนี้ไม่ส่งอะไรออกนอกเครื่องจริง
 * - user id/ข้อความ/retry key/token/body ทั้งหมดเป็น canary — ต้องไม่ปรากฏใน log
 */
class LineTransportTest extends TestCase
{
    private const QUOTA_URL = 'https://api.line.me/v2/bot/message/quota';

    private const CONSUMPTION_URL = 'https://api.line.me/v2/bot/message/quota/consumption';

    private const PUSH_URL = 'https://api.line.me/v2/bot/message/push';

    private const QUOTA_LOG_MESSAGE = 'LINE follow-up quota read';

    private const PUSH_LOG_MESSAGE = 'LINE follow-up push';

    private const CANARY_USER_ID = 'U-user-canary-ไม่มีตัวจริง';

    // ------------------------------------------------------------------
    // D40 — followUpQuota: เคส ok
    // ------------------------------------------------------------------

    #[Test]
    public function test_quota_ok_limited_returns_full_array(): void
    {
        Http::fake([
            self::QUOTA_URL => Http::response('{"type":"limited","value":1000}', 200),
            self::CONSUMPTION_URL => Http::response('{"totalUsage":500}', 200),
        ]);
        Http::preventStrayRequests();

        $result = (new LINEService)->followUpQuota($this->bot());

        $this->assertSame([
            'ok' => true,
            'reason' => null,
            'type' => 'limited',
            'value' => 1000,
            'total_usage' => 500,
        ], $result);

        Http::assertSent(fn ($request) => $request->url() === self::QUOTA_URL
            && $request->isGet()
            && $request->hasHeader('Authorization', 'Bearer '.$this->token()));
        Http::assertSent(fn ($request) => $request->url() === self::CONSUMPTION_URL
            && $request->isGet()
            && $request->hasHeader('Authorization', 'Bearer '.$this->token()));
        Http::assertSentCount(2);
    }

    #[Test]
    public function test_quota_ok_none_ignores_value_in_body(): void
    {
        Http::fake([
            self::QUOTA_URL => Http::response('{"type":"none","value":5}', 200),
            self::CONSUMPTION_URL => Http::response('{"totalUsage":7}', 200),
        ]);
        Http::preventStrayRequests();

        $result = (new LINEService)->followUpQuota($this->bot());

        $this->assertSame([
            'ok' => true,
            'reason' => null,
            'type' => 'none',
            'value' => null,
            'total_usage' => 7,
        ], $result);
    }

    #[Test]
    public function test_quota_value_and_usage_zero_are_ok(): void
    {
        Http::fake([
            self::QUOTA_URL => Http::response('{"type":"limited","value":0}', 200),
            self::CONSUMPTION_URL => Http::response('{"totalUsage":0}', 200),
        ]);
        Http::preventStrayRequests();

        $result = (new LINEService)->followUpQuota($this->bot());

        $this->assertSame([
            'ok' => true,
            'reason' => null,
            'type' => 'limited',
            'value' => 0,
            'total_usage' => 0,
        ], $result);
    }

    #[Test]
    public function test_quota_without_token_sends_nothing(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $result = (new LINEService)->followUpQuota($this->bot(''));

        $this->assertSame([
            'ok' => false,
            'reason' => 'no_token',
            'type' => null,
            'value' => null,
            'total_usage' => null,
        ], $result);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // D40 — request แรกล้มเหลวแล้วห้ามยิง request ที่สอง
    // ------------------------------------------------------------------

    #[Test]
    public function test_quota_failure_on_first_get_stops_the_second(): void
    {
        $firstStubs = [
            'quota_unreachable' => function () { throw new ConnectionException('cURL error 28'); },
            'quota_http_error' => Http::response('{"message":"Internal server error"}', 500),
            'quota_malformed' => Http::response('ok', 200),
        ];

        foreach ($firstStubs as $reason => $stub) {
            Http::fake([
                self::QUOTA_URL => $stub,
                self::CONSUMPTION_URL => Http::response('{"totalUsage":1}', 200),
            ]);
            Http::preventStrayRequests();

            $result = (new LINEService)->followUpQuota($this->bot());

            $this->assertSame([
                'ok' => false,
                'reason' => $reason,
                'type' => null,
                'value' => null,
                'total_usage' => null,
            ], $result, "GET แรกล้มเหลวแบบ {$reason} ต้องไม่ยิง request ที่สอง");
            Http::assertNotSent(fn ($request) => $request->url() === self::CONSUMPTION_URL);
        }
    }

    #[Test]
    public function test_quota_failure_on_second_get(): void
    {
        $secondStubs = [
            'quota_unreachable' => function () { throw new ConnectionException('cURL error 28'); },
            'quota_http_error' => Http::response('{"message":"Service unavailable"}', 503),
            'quota_malformed' => Http::response('{"nope":true}', 200),
        ];

        foreach ($secondStubs as $reason => $stub) {
            Http::fake([
                self::QUOTA_URL => Http::response('{"type":"limited","value":10}', 200),
                self::CONSUMPTION_URL => $stub,
            ]);
            Http::preventStrayRequests();

            $result = (new LINEService)->followUpQuota($this->bot());

            $this->assertSame([
                'ok' => false,
                'reason' => $reason,
                'type' => null,
                'value' => null,
                'total_usage' => null,
            ], $result);
            Http::assertSentCount(2);
        }
    }

    // ------------------------------------------------------------------
    // D40 — body ที่ไม่ใช่จำนวนเต็มจริง (JSON integer เท่านั้น) = quota_malformed
    // ------------------------------------------------------------------

    public static function provideMalformedQuotaBodies(): array
    {
        return [
            'not json' => ['ok'],
            'json array' => ['[]'],
            'empty object' => ['{}'],
            'type uppercase' => ['{"type":"LIMITED","value":100}'],
            'type number' => ['{"type":5,"value":5}'],
            'value float' => ['{"type":"limited","value":1.5}'],
            'value float zero' => ['{"type":"limited","value":100.0}'],
            'value string' => ['{"type":"limited","value":"100"}'],
            'value bool true' => ['{"type":"limited","value":true}'],
            'value null' => ['{"type":"limited","value":null}'],
            'value missing' => ['{"type":"limited"}'],
            'value negative' => ['{"type":"limited","value":-1}'],
            'type none uppercase' => ['{"type":"NONE"}'],
        ];
    }

    #[Test]
    #[DataProvider('provideMalformedQuotaBodies')]
    public function test_quota_malformed_quota_body(string $body): void
    {
        Http::fake([
            self::QUOTA_URL => Http::response($body, 200),
            self::CONSUMPTION_URL => Http::response('{"totalUsage":1}', 200),
        ]);
        Http::preventStrayRequests();

        $result = (new LINEService)->followUpQuota($this->bot());

        $this->assertSame([
            'ok' => false,
            'reason' => 'quota_malformed',
            'type' => null,
            'value' => null,
            'total_usage' => null,
        ], $result);
        Http::assertNotSent(fn ($request) => $request->url() === self::CONSUMPTION_URL);
    }

    public static function provideMalformedConsumptionBodies(): array
    {
        return [
            'not json' => ['ok'],
            'json array' => ['[]'],
            'empty object' => ['{}'],
            'usage string' => ['{"totalUsage":"7"}'],
            'usage float' => ['{"totalUsage":7.5}'],
            'usage bool' => ['{"totalUsage":true}'],
            'usage null' => ['{"totalUsage":null}'],
            'usage missing' => ['{"nope":true}'],
            'usage negative' => ['{"totalUsage":-1}'],
        ];
    }

    #[Test]
    #[DataProvider('provideMalformedConsumptionBodies')]
    public function test_quota_malformed_consumption_body(string $body): void
    {
        Http::fake([
            self::QUOTA_URL => Http::response('{"type":"limited","value":10}', 200),
            self::CONSUMPTION_URL => Http::response($body, 200),
        ]);
        Http::preventStrayRequests();

        $result = (new LINEService)->followUpQuota($this->bot());

        $this->assertSame([
            'ok' => false,
            'reason' => 'quota_malformed',
            'type' => null,
            'value' => null,
            'total_usage' => null,
        ], $result);
        Http::assertSentCount(2);
    }

    // ------------------------------------------------------------------
    // Task 3 — timeout จาก config อ่านขณะเรียก สำหรับทั้งสองเมธอด
    // ------------------------------------------------------------------

    #[Test]
    public function test_timeouts_read_at_call_time_for_both_methods(): void
    {
        [$service, $holder] = $this->capturingService();

        Http::fake([
            self::QUOTA_URL => Http::response('{"type":"none"}', 200),
            self::CONSUMPTION_URL => Http::response('{"totalUsage":0}', 200),
            self::PUSH_URL => Http::response('{}', 200),
        ]);
        Http::preventStrayRequests();

        $service->followUpQuota($this->bot());
        $this->assertSame(5, $holder->captured->getOptions()['timeout']);
        $this->assertSame(3, $holder->captured->getOptions()['connect_timeout']);

        $service->pushFollowUp($this->bot(), self::CANARY_USER_ID, 'ทดสอบ', $this->retryKey());
        $this->assertSame(5, $holder->captured->getOptions()['timeout']);
        $this->assertSame(3, $holder->captured->getOptions()['connect_timeout']);

        config([
            'customer_follow_up.line_timeout_seconds' => 7,
            'customer_follow_up.http_connect_timeout_seconds' => 2,
        ]);

        $service->followUpQuota($this->bot());
        $this->assertSame(7, $holder->captured->getOptions()['timeout']);
        $this->assertSame(2, $holder->captured->getOptions()['connect_timeout']);

        $service->pushFollowUp($this->bot(), self::CANARY_USER_ID, 'ทดสอบ', $this->retryKey());
        $this->assertSame(7, $holder->captured->getOptions()['timeout']);
        $this->assertSame(2, $holder->captured->getOptions()['connect_timeout']);
    }

    // ------------------------------------------------------------------
    // D41 — pushFollowUp
    // ------------------------------------------------------------------

    #[Test]
    public function test_push_accepted_200(): void
    {
        Http::fake([
            self::PUSH_URL => Http::response('{}', 200, ['x-line-request-id' => 'req-accept-1']),
        ]);
        Http::preventStrayRequests();

        $text = 'สวัสดีครับ ยังสนใจสินค้าอยู่ไหมครับ';

        $result = (new LINEService)->pushFollowUp($this->bot(), self::CANARY_USER_ID, $text, $this->retryKey());

        $this->assertSame(['outcome' => 'accepted', 'status' => 200, 'request_id' => 'req-accept-1'], $result);

        Http::assertSent(function ($request) use ($text) {
            return $request->url() === self::PUSH_URL
                && $request->isPost()
                && $request->hasHeader('X-Line-Retry-Key', $this->retryKey())
                && $request->hasHeader('Authorization', 'Bearer '.$this->token())
                && $request->json('to') === self::CANARY_USER_ID
                && $request->json('messages') === [['type' => 'text', 'text' => $text]]
                && json_decode($request->body(), true) === [
                    'to' => self::CANARY_USER_ID,
                    'messages' => [['type' => 'text', 'text' => $text]],
                ];
        });
        Http::assertSentCount(1);
    }

    #[Test]
    public function test_push_accepted_200_without_request_id_header(): void
    {
        Http::fake([
            self::PUSH_URL => Http::response('{}', 200),
        ]);
        Http::preventStrayRequests();

        $result = (new LINEService)->pushFollowUp($this->bot(), self::CANARY_USER_ID, 'ทดสอบ', $this->retryKey());

        $this->assertSame(['outcome' => 'accepted', 'status' => 200, 'request_id' => null], $result);
    }

    #[Test]
    public function test_push_409_with_accepted_request_id_is_accepted(): void
    {
        Http::fake([
            self::PUSH_URL => Http::response('{"message":"The retry key is already accepted"}', 409, [
                'x-line-request-id' => 'req-replay-new',
                'x-line-accepted-request-id' => 'req-accepted-original',
            ]),
        ]);
        Http::preventStrayRequests();

        $result = (new LINEService)->pushFollowUp($this->bot(), self::CANARY_USER_ID, 'ทดสอบ', $this->retryKey());

        $this->assertSame([
            'outcome' => 'accepted',
            'status' => 409,
            'request_id' => 'req-accepted-original',
        ], $result);
        Http::assertSentCount(1);
    }

    #[Test]
    public function test_push_409_without_usable_accepted_header_is_rejected(): void
    {
        $headerCases = [
            'missing' => [],
            'empty' => ['x-line-accepted-request-id' => ''],
            'spaces only' => ['x-line-accepted-request-id' => '   '],
            'request id only' => ['x-line-request-id' => 'req-new'],
        ];

        foreach ($headerCases as $case => $headers) {
            Http::fake([
                self::PUSH_URL => Http::response('{"message":"The retry key is already accepted"}', 409, $headers),
            ]);
            Http::preventStrayRequests();

            $result = (new LINEService)->pushFollowUp($this->bot(), self::CANARY_USER_ID, 'ทดสอบ', $this->retryKey());

            $this->assertSame(
                ['outcome' => 'rejected', 'status' => 409, 'request_id' => null],
                $result,
                "409 แบบ {$case} ต้องเป็น rejected ไม่ใช่ accepted"
            );
        }
    }

    #[Test]
    public function test_push_retryable_statuses(): void
    {
        foreach ([429, 500, 502, 503] as $status) {
            Http::fake([
                self::PUSH_URL => Http::response('{"message":"Internal server error"}', $status),
            ]);
            Http::preventStrayRequests();

            $result = (new LINEService)->pushFollowUp($this->bot(), self::CANARY_USER_ID, 'ทดสอบ', $this->retryKey());

            $this->assertSame(
                ['outcome' => 'retryable', 'status' => $status, 'request_id' => null],
                $result,
                "สถานะ {$status} ต้องเป็น retryable"
            );
        }
    }

    #[Test]
    public function test_push_connection_error_is_retryable_without_status(): void
    {
        Http::fake([
            self::PUSH_URL => function () { throw new ConnectionException('cURL error 28'); },
        ]);
        Http::preventStrayRequests();

        $result = (new LINEService)->pushFollowUp($this->bot(), self::CANARY_USER_ID, 'ทดสอบ', $this->retryKey());

        $this->assertSame(['outcome' => 'retryable', 'status' => null, 'request_id' => null], $result);
        Http::assertSentCount(1);
    }

    #[Test]
    public function test_push_other_4xx_is_rejected(): void
    {
        foreach ([400, 401, 403, 418] as $status) {
            Http::fake([
                self::PUSH_URL => Http::response('{"message":"Forbidden"}', $status),
            ]);
            Http::preventStrayRequests();

            $result = (new LINEService)->pushFollowUp($this->bot(), self::CANARY_USER_ID, 'ทดสอบ', $this->retryKey());

            $this->assertSame(
                ['outcome' => 'rejected', 'status' => $status, 'request_id' => null],
                $result,
                "สถานะ {$status} ต้องเป็น rejected"
            );
        }
    }

    #[Test]
    public function test_push_without_valid_retry_key_sends_nothing(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        foreach (['not-a-uuid', '', '12345', $this->retryKey().'-extra'] as $badKey) {
            $result = (new LINEService)->pushFollowUp($this->bot(), self::CANARY_USER_ID, 'ทดสอบ', $badKey);

            $this->assertSame(
                ['outcome' => 'rejected', 'status' => null, 'request_id' => null],
                $result,
                'retry key ไม่ใช่ UUID ต้อง rejected และไม่ยิงอะไรออกไป'
            );
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function test_push_without_token_sends_nothing(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $result = (new LINEService)->pushFollowUp($this->bot(''), self::CANARY_USER_ID, 'ทดสอบ', $this->retryKey());

        $this->assertSame(['outcome' => 'rejected', 'status' => null, 'request_id' => null], $result);
        Http::assertNothingSent();
    }

    #[Test]
    public function test_push_has_no_automatic_http_retry(): void
    {
        Http::fake([
            self::PUSH_URL => Http::response('{"message":"Internal server error"}', 500),
        ]);
        Http::preventStrayRequests();

        $result = (new LINEService)->pushFollowUp($this->bot(), self::CANARY_USER_ID, 'ทดสอบ', $this->retryKey());

        $this->assertSame('retryable', $result['outcome']);
        Http::assertSentCount(1);
    }

    // ------------------------------------------------------------------
    // D41 — ข้อความส่งตรงตามที่ได้รับ ไม่ตัด ไม่เปลี่ยน
    // ------------------------------------------------------------------

    #[Test]
    public function test_push_keeps_text_exactly_including_thai_newline_and_emoji(): void
    {
        Http::fake([
            self::PUSH_URL => Http::response('{}', 200),
        ]);
        Http::preventStrayRequests();

        $canary = "สวัสดี 🙏\nรบกวนทักกลับหน่อยครับ — ทดสอบ B4a";

        (new LINEService)->pushFollowUp($this->bot(), self::CANARY_USER_ID, $canary, $this->retryKey());

        Http::assertSent(function ($request) use ($canary) {
            return $request->json('messages.0.text') === $canary
                && json_decode($request->body(), true)['messages'][0]['text'] === $canary;
        });
    }

    #[Test]
    public function test_push_does_not_shorten_long_text(): void
    {
        Http::fake([
            self::PUSH_URL => Http::response('{}', 200),
        ]);
        Http::preventStrayRequests();

        // ยาวเกิน 5000 code points — เมธอดเดิม textMessage() จะตัดทิ้ง ตัวนี้ห้ามตัด
        $longText = str_repeat('ก', 400).'ต่อยาว'.str_repeat('ข', 4700);

        (new LINEService)->pushFollowUp($this->bot(), self::CANARY_USER_ID, $longText, $this->retryKey());

        Http::assertSent(fn ($request) => $request->json('messages.0.text') === $longText);
    }

    // ------------------------------------------------------------------
    // Task 3 — log ต่อ call บรรทัดเดียว ไม่มีข้อมูลอ่อนไหว
    // ------------------------------------------------------------------

    #[Test]
    public function test_log_lines_never_hold_user_id_text_key_token_or_body(): void
    {
        $captured = [];
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
            Log::shouldReceive($level)->andReturnUsing(function (string $message, array $context = []) use (&$captured, $level) {
                $captured[] = ['level' => $level, 'message' => $message, 'context' => $context];
            });
        }

        Http::fake([
            self::QUOTA_URL => Http::response('{"type":"limited","value":9}', 200),
            self::CONSUMPTION_URL => Http::response('{"totalUsage":1}', 200),
        ]);
        Http::preventStrayRequests();

        $line = new LINEService;

        // โควตา: no_token + ok
        $line->followUpQuota($this->bot(''));
        $line->followUpQuota($this->bot());

        $text = 'ข้อความ canary ห้ามเข้า log';

        Http::fake([
            self::QUOTA_URL => Http::response('{"type":"limited","value":9}', 200),
            self::CONSUMPTION_URL => Http::response('{"totalUsage":1}', 200),
            self::PUSH_URL => Http::response('{}', 200, ['x-line-request-id' => 'req-ok']),
        ]);
        $line->pushFollowUp($this->bot(), self::CANARY_USER_ID, $text, $this->retryKey()); // accepted

        Http::fake([
            self::QUOTA_URL => Http::response('{"type":"limited","value":9}', 200),
            self::CONSUMPTION_URL => Http::response('{"totalUsage":1}', 200),
            self::PUSH_URL => Http::response('{"message":"CanaryBodyLeak"}', 409),
        ]);
        $line->pushFollowUp($this->bot(), self::CANARY_USER_ID, $text, $this->retryKey()); // rejected

        Http::fake([
            self::QUOTA_URL => Http::response('{"type":"limited","value":9}', 200),
            self::CONSUMPTION_URL => Http::response('{"totalUsage":1}', 200),
            self::PUSH_URL => Http::response('{"message":"CanaryBodyLeak"}', 500),
        ]);
        $line->pushFollowUp($this->bot(), self::CANARY_USER_ID, $text, $this->retryKey()); // retryable

        $canaries = [$this->token(), $this->retryKey(), self::CANARY_USER_ID, $text, 'CanaryBodyLeak', 'req-ok'];

        foreach ($captured as $entry) {
            foreach ($canaries as $canary) {
                $this->assertStringNotContainsString($canary, $entry['message']);
                $this->assertStringNotContainsString(
                    $canary,
                    (string) json_encode($entry['context'], JSON_UNESCAPED_UNICODE)
                );
            }
        }

        $quotaLines = array_values(array_filter($captured, fn (array $e) => $e['message'] === self::QUOTA_LOG_MESSAGE));
        $pushLines = array_values(array_filter($captured, fn (array $e) => $e['message'] === self::PUSH_LOG_MESSAGE));

        // บรรทัดเดียวต่อ call: โควตา 2 (no_token + ok), push 3 (accepted + rejected + retryable)
        $this->assertCount(2, $quotaLines);
        $this->assertCount(3, $pushLines);

        foreach ($quotaLines as $entry) {
            $keys = array_keys($entry['context']);
            sort($keys);
            $this->assertSame(['bot_id', 'reason', 'status'], $keys);
            $this->assertSame(77, $entry['context']['bot_id']);
        }

        foreach ($pushLines as $entry) {
            $keys = array_keys($entry['context']);
            sort($keys);
            $this->assertSame(['bot_id', 'outcome', 'status'], $keys);
            $this->assertSame(77, $entry['context']['bot_id']);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** โทเคนปลอมสำหรับเทส — ประกอบจากชิ้นส่วนขณะรัน ไม่มีรูปแบบ credential จริงในไฟล์ */
    private function token(): string
    {
        return implode('', ['fake', 'Line', 'Token', 'Parts', '42']);
    }

    private function retryKey(): string
    {
        return implode('-', ['6f8fa5d0', 'c5de', '4a0b', '9e26', '6f55c1b2d3aa']);
    }

    private function bot(?string $token = null): Bot
    {
        return new Bot([
            'id' => 77,
            'user_id' => 1,
            'name' => 'Follow-up transport test bot',
            'channel_type' => 'line',
            'channel_access_token' => $token ?? $this->token(),
        ]);
    }

    /** @return array{0: LINEService, 1: object} */
    private function capturingService(): array
    {
        $holder = new class
        {
            public ?PendingRequest $captured = null;
        };

        $service = new class($holder) extends LINEService
        {
            public function __construct(private readonly object $holder) {}

            protected function followUpClient(Bot $bot, array $additionalHeaders = []): PendingRequest
            {
                return $this->holder->captured = parent::followUpClient($bot, $additionalHeaders);
            }
        };

        return [$service, $holder];
    }
}
