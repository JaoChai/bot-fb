<?php

namespace Tests\Unit\FollowUp;

use App\Exceptions\OpenRouterException;
use App\Models\Bot;
use App\Services\FollowUp\FollowUpDraftService;
use App\Services\FollowUp\FollowUpPrompts;
use App\Services\OpenRouterService;
use App\Support\FollowUpRedactor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด B3b — FollowUpDraftService::draft() (plan §3.5, D27/D28/D29)
 *
 * สัญญา:
 * - เรียก OpenRouterService::chat ครั้งเดียว: model = resolvedChatModel,
 *   fallbackModelOverride = fallback_chat_model, useFallback true, temperature 0.4,
 *   maxTokens/timeout จาก config, reasoning null, responseFormat null
 * - system = writer system prompt, user = JSON(group, conversation) — ไม่ส่ง
 *   system prompt / KB ของบอท
 * - null โดยไม่ยิงเมื่อ group ไม่ใช่ G2/G3 หรือบอทไม่มี model
 * - fail closed: finish_reason !== 'stop', เกิน 400 code points, เกิน 3 บรรทัด,
 *   account data / marker / redact เปลี่ยน / '@' / เงิน-เปอร์เซ็นต์ (D29) — ไม่มี fallback text ไม่มีการตัด
 * - egress guard (D30) ก่อนยิงทุกครั้ง
 * - log ล้มเหลว: warning เดียว มีแค่ step + reason
 *
 * OpenRouterService ถูก mock ทุกเทส — ไม่มี HTTP จริง, Http::preventStrayRequests กันไว้ทุกเทส
 */
class DraftTest extends TestCase
{
    private const CHAT_MODEL = 'openai/gpt-6-luna-writer';

    private const FALLBACK_MODEL = 'openai/gpt-6-fallback';

    /** @var list<string> */
    private array $context;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->context = [
            'C: อยากได้เพจ 5 เพจครับ',
            'B: รบกวนพิมพ์ ยืนยัน เพื่อสรุปรายการครับ',
        ];
    }

    /** @return array{0: \App\Services\FollowUp\FollowUpDraftService, 1: \Mockery\MockInterface&OpenRouterService} */
    private function makeService(): array
    {
        $mock = $this->mock(OpenRouterService::class);
        $service = app(FollowUpDraftService::class);

        return [$service, $mock];
    }

    private function bot(?string $primary = self::CHAT_MODEL, ?string $fallback = self::FALLBACK_MODEL): Bot
    {
        return (new Bot)->forceFill([
            'primary_chat_model' => $primary,
            'fallback_chat_model' => $fallback,
        ]);
    }

    /** บรรทัดที่ detector ต้องว่ามี account data (credential line) — สร้าง runtime จากชิ้นส่วน */
    private function accountDataLine(): string
    {
        return 'C: login foo'.'@'.'mail.com|secret'.'value2026';
    }

    /** บรรทัดที่ไม่ใช่ account data แต่ redact() เปลี่ยน (อีเมลล้วน) — สร้าง runtime จากชิ้นส่วน */
    private function redactChangedLine(): string
    {
        return 'C: ติดต่อ john'.'@'.'example.com ได้ครับ';
    }

    /**
     * payload ที่ chat() คืนตามรูปร่าง parseResponse ของ OpenRouterService
     *
     * @return array<string, mixed>
     */
    private function chatPayload(string $content, ?string $finish = 'stop'): array
    {
        return [
            'content' => $content,
            'reasoning' => null,
            'model' => self::CHAT_MODEL,
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40, 'total_tokens' => 160, 'cached_tokens' => 0, 'reasoning_tokens' => 25, 'cost' => 0.012],
            'id' => 'gen-b3b',
            'finish_reason' => $finish,
        ];
    }

    #[Test]
    public function test_writer_version_is_frozen_and_system_prompt_hash_matches(): void
    {
        $this->assertSame('writer-v1', FollowUpPrompts::WRITER_VERSION);
        $this->assertSame(
            'f662d14edaef6a56da54bb6206c71807df427757d0862ee898eaabd4be956d06',
            hash('sha256', FollowUpPrompts::writerSystem()),
            'writer system prompt bytes must match the frozen string',
        );
    }

    #[Test]
    public function test_draft_sends_frozen_messages_and_expected_arguments(): void
    {
        [$service, $mock] = $this->makeService();
        $captured = [];
        $mock->shouldReceive('chat')->once()->withArgs(function (mixed ...$args) use (&$captured): bool {
            $captured = $args;

            return true;
        })->andReturn($this->chatPayload('ยังสนใจเพจ 5 เพจอยู่ไหมครับ'));

        $result = $service->draft($this->bot(), $this->context, 'G2');

        $this->assertNotNull($result);
        $this->assertCount(9, $captured, 'chat receives exactly the nine planned arguments');

        [$messages, $model, $temperature, $maxTokens, $useFallback, $fallbackOverride, $timeout, $reasoning, $responseFormat] = $captured;

        $this->assertCount(2, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame(
            'f662d14edaef6a56da54bb6206c71807df427757d0862ee898eaabd4be956d06',
            hash('sha256', $messages[0]['content']),
            'system message is the frozen writer prompt',
        );
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame(
            json_encode(['group' => 'G2', 'conversation' => $this->context], JSON_UNESCAPED_UNICODE),
            $messages[1]['content'],
            'user message is the JSON of group and context',
        );

        $this->assertSame(self::CHAT_MODEL, $model);
        $this->assertSame(0.4, $temperature);
        $this->assertSame(1024, $maxTokens, 'maxTokens comes from config writer_max_tokens (D28)');
        $this->assertTrue($useFallback);
        $this->assertSame(self::FALLBACK_MODEL, $fallbackOverride);
        $this->assertSame(15, $timeout, 'timeout comes from config writer_timeout_seconds');
        $this->assertNull($reasoning);
        $this->assertNull($responseFormat);
    }

    #[Test]
    public function test_draft_reads_max_tokens_and_timeout_from_config_when_changed(): void
    {
        [$service, $mock] = $this->makeService();
        $captured = [];
        $mock->shouldReceive('chat')->once()->withArgs(function (mixed ...$args) use (&$captured): bool {
            $captured = $args;

            return true;
        })->andReturn($this->chatPayload('ยังสนใจอยู่ไหมครับ'));

        config([
            'customer_follow_up.writer_max_tokens' => 77,
            'customer_follow_up.writer_timeout_seconds' => 9,
        ]);

        $result = $service->draft($this->bot(), $this->context, 'G3');

        $this->assertNotNull($result);
        $this->assertSame(77, $captured[3]);
        $this->assertSame(9, $captured[6]);
    }

    #[Test]
    public function test_draft_returns_parsed_success_result(): void
    {
        [$service, $mock] = $this->makeService();
        $payload = $this->chatPayload('ยังสนใจเพจ 5 เพจอยู่ไหมครับ');
        $mock->shouldReceive('chat')->once()->andReturn($payload);

        $result = $service->draft($this->bot(), $this->context, 'G2');

        $this->assertNotNull($result);
        $this->assertSame('ยังสนใจเพจ 5 เพจอยู่ไหมครับ', $result['text']);
        $this->assertSame(self::CHAT_MODEL, $result['model'], 'the returned model is the one chat reports');
        $this->assertSame(0.012, $result['cost']);
        $this->assertSame($payload['usage'], $result['usage']);
        $this->assertIsInt($result['latency_ms']);
        $this->assertGreaterThanOrEqual(0, $result['latency_ms']);
    }

    #[Test]
    public function test_draft_skips_non_payment_groups_without_calling_chat(): void
    {
        [$service, $mock] = $this->makeService();
        $mock->shouldNotReceive('chat');

        $this->assertNull($service->draft($this->bot(), $this->context, 'G1'));
        $this->assertNull($service->draft($this->bot(), $this->context, 'G4'));
    }

    #[Test]
    public function test_draft_skips_when_bot_has_no_chat_model(): void
    {
        [$service, $mock] = $this->makeService();
        $mock->shouldNotReceive('chat');

        $this->assertNull($service->draft($this->bot(null, null), $this->context, 'G2'));
    }

    #[Test]
    public function test_draft_refuses_unsafe_context_without_calling_chat(): void
    {
        [$service, $mock] = $this->makeService();
        $mock->shouldNotReceive('chat');

        $rows = [
            'account_data_line' => [$this->accountDataLine()],
            'redact_changed_line' => [$this->redactChangedLine()],
            'empty_context' => [],
            'eleven_lines' => array_map(fn (int $i): string => 'C: ข้อความทดสอบบรรทัดที่ '.$i, range(1, FollowUpRedactor::MAX_LINES + 1)),
        ];
        foreach ($rows as $name => $context) {
            $this->assertNull($service->draft($this->bot(), $context, 'G2'), "row {$name} must fail closed");
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload  null = chat โยน OpenRouterException
     */
    #[Test]
    #[DataProvider('rejectedDraftsProvider')]
    public function test_draft_rejects_bad_payloads_and_content(?array $payload, bool $throws): void
    {
        [$service, $mock] = $this->makeService();
        $expectation = $mock->shouldReceive('chat')->once();
        if ($throws) {
            $expectation->andThrow(new OpenRouterException('provider error', 500));
        } else {
            $expectation->andReturn($payload);
        }

        $result = $service->draft($this->bot(), $this->context, 'G2');

        $this->assertNull($result, 'rejected drafts return null — no fallback text, no truncation');
    }

    public static function rejectedDraftsProvider(): array
    {
        $payload = fn (string $content, ?string $finish = 'stop'): array => [
            'content' => $content,
            'reasoning' => null,
            'model' => self::CHAT_MODEL,
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2, 'cached_tokens' => 0, 'reasoning_tokens' => 0, 'cost' => null],
            'id' => 'gen-b3b',
            'finish_reason' => $finish,
        ];

        return [
            'openrouter_exception' => ['payload' => null, 'throws' => true],
            'empty_content' => ['payload' => $payload(''), 'throws' => false],
            'whitespace_only_content' => ['payload' => $payload("  \n\t "), 'throws' => false],
            'non_string_content' => ['payload' => array_merge($payload('x'), ['content' => null]), 'throws' => false],
            'finish_reason_length' => ['payload' => $payload('ยังสนใจอยู่ไหมครับ', 'length'), 'throws' => false],
            'finish_reason_missing' => ['payload' => $payload('ยังสนใจอยู่ไหมครับ', null), 'throws' => false],
            'over_400_code_points' => ['payload' => $payload(str_repeat('ก', 401)), 'throws' => false],
            'four_non_blank_lines' => ['payload' => $payload("บรรทัดหนึ่ง\nสอง\nสาม\nสี่"), 'throws' => false],
            'account_data_in_content' => ['payload' => $payload('ยังสนใจอยู่ไหมครับ login foo'.'@'.'mail.com|secret2026'), 'throws' => false],
            'redaction_marker_in_content' => ['payload' => $payload('ข้อความมี [redacted:digits] ครับ'), 'throws' => false],
            'email_in_content' => ['payload' => $payload('ติดต่อ john'.'@'.'example.com ได้ครับ'), 'throws' => false],
            'link_in_content' => ['payload' => $payload('ดูได้ที่ https://example.com/page ครับ'), 'throws' => false],
            'money_baht_with_comma' => ['payload' => $payload('ราคา 1,500 บาท'), 'throws' => false],
            'percentage' => ['payload' => $payload('ลด 50%'), 'throws' => false],
            'dollar_then_digit' => ['payload' => $payload('$20 ครับ'), 'throws' => false],
            'thai_digits_baht' => ['payload' => $payload('๕๐๐ บาท'), 'throws' => false],
            'thb_suffix' => ['payload' => $payload('500 THB'), 'throws' => false],
            'contact_id_at_sign' => ['payload' => $payload('ทักมาที่ @shop_contact ได้ครับ'), 'throws' => false],
        ];
    }

    /**
     * @param  string  $content  เนื้อหาที่ chat รายงาน
     * @param  string  $expectedText  ข้อความที่ต้องได้กลับ (trim แล้ว)
     */
    #[Test]
    #[DataProvider('acceptedDraftsProvider')]
    public function test_draft_accepts_safe_content_within_limits(string $content, string $expectedText): void
    {
        [$service, $mock] = $this->makeService();
        $mock->shouldReceive('chat')->once()->andReturn($this->chatPayload($content));

        $result = $service->draft($this->bot(), $this->context, 'G2');

        $this->assertNotNull($result);
        $this->assertSame($expectedText, $result['text']);
    }

    public static function acceptedDraftsProvider(): array
    {
        return [
            'plain_invite' => ['content' => 'ยังสนใจเพจ 5 เพจอยู่ไหมครับ', 'expectedText' => 'ยังสนใจเพจ 5 เพจอยู่ไหมครับ'],
            'comma_number_without_currency' => ['content' => 'เรื่องเฟส Limit 8,000 ที่พี่ถามไว้ ยังสนใจอยู่ไหมครับ', 'expectedText' => 'เรื่องเฟส Limit 8,000 ที่พี่ถามไว้ ยังสนใจอยู่ไหมครับ'],
            'days_without_currency' => ['content' => 'เรื่องยืนยันตัวตนภายใน 7 วัน ยังอยากคุยต่อไหมครับ', 'expectedText' => 'เรื่องยืนยันตัวตนภายใน 7 วัน ยังอยากคุยต่อไหมครับ'],
            'exactly_400_code_points' => ['content' => str_repeat('สนใจ', 100), 'expectedText' => str_repeat('สนใจ', 100)],
            'exactly_three_non_blank_lines' => ['content' => "ยังสนใจอยู่ไหมครับ\nเรื่องเฟสที่พี่ถามไว้\nยินดีดูแลต่อครับ", 'expectedText' => "ยังสนใจอยู่ไหมครับ\nเรื่องเฟสที่พี่ถามไว้\nยินดีดูแลต่อครับ"],
            'trailing_line_break_trimmed' => ['content' => "ยังสนใจเพจ 5 เพจอยู่ไหมครับ\n", 'expectedText' => 'ยังสนใจเพจ 5 เพจอยู่ไหมครับ'],
            'emoji' => ['content' => 'ยังสนใจอยู่ไหมครับ 🙏', 'expectedText' => 'ยังสนใจอยู่ไหมครับ 🙏'],
        ];
    }

    #[Test]
    public function test_failures_log_once_with_step_and_reason_only_and_never_leak_the_canary(): void
    {
        [$service, $mock] = $this->makeService();
        $canary = 'CANARY'.'DRAFTPROBE';

        // ร่างที่มี canary + เงิน (ถูกกฎ D29) — canary อยู่ในบริบท ในร่าง และใน payload ปลอม
        $contextWithCanary = array_merge($this->context, ['C: ข้อความ '.$canary.' ธรรมดา']);
        $payload = $this->chatPayload('ราคา 1,500 บาท '.$canary);
        $payload['id'] = 'gen-'.$canary;
        $mock->shouldReceive('chat')->once()->andReturn($payload);

        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) use ($canary): bool {
            $serialized = $message.json_encode($context, JSON_UNESCAPED_UNICODE);

            return array_keys($context) === ['step', 'reason'] && ! str_contains($serialized, $canary);
        });

        $this->assertNull($service->draft($this->bot(), $contextWithCanary, 'G2'));
    }
}
