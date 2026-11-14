<?php

namespace Tests\Unit\FollowUp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด B3b — FollowUpDecisionsService::sort() / ::check() (plan §3.5, D26/D30)
 *
 * สัญญา:
 * - POST เดียวไป customer_follow_up.decisions_url แบบ Bearer ไม่มี retry/pool
 * - อ่านเฉพาะ answers.group.choice/confidence, answers.declined.noul, usage — คะแนน
 *   ต้องเป็นเลข JSON 0..1 และ choice อยู่ใน G1-G4 ไม่งั้น fail-closed (null / check_error)
 * - ไม่มี answers คืนจาก method (D30) ไม่มี clamp ไม่มี default คะแนน
 * - egress guard (D30): ทุกบรรทัดต้องไม่มี account data และ redact(line) === line,
 *   บริบทเป็น list ว่างไม่ได้ ไม่เกิน FollowUpRedactor::MAX_LINES บรรทัด — ไม่ผ่าน = ไม่ยิงอะไร
 * - log ล้มเหลว: warning เดียว มีแค่ step + reason code ไม่มีบริบท/ร่าง/body/key
 *
 * ทุก fixture รูปร่าง credential สร้าง runtime จากชิ้นส่วน; ทุกเทส fake HTTP เท่านั้น
 */
class DecisionsTest extends TestCase
{
    /** key ทดสอบ สร้าง runtime จากชิ้นส่วน (ไม่มี key จริงในไฟล์) */
    private string $key;

    private string $url;

    /** @var list<string> บริบททดสอบ ไม่มีบรรทัด A: */
    private array $context;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->key = 'test'.'-key-'.'b3b';
        $this->url = 'https://openrouter-decisions'.'.test/api/alpha/decisions';
        config([
            'services.openrouter.api_key' => $this->key,
            'customer_follow_up.decisions_url' => $this->url,
        ]);

        $this->context = [
            'C: อยากได้เพจ 5 เพจครับ',
            'B: รบกวนพิมพ์ ยืนยัน เพื่อสรุปรายการครับ',
        ];
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
     * คำถาม sort เขียนออกมาในไฟล์เทสเอง (ไม่อ้าง FollowUpPrompts) — ตรวจ byte-for-byte
     *
     * @return array<string, mixed>
     */
    private static function expectedSortQuestions(): array
    {
        return [
            'group' => [
                'type' => 'choice',
                'instructions' => 'What should the shop do about this silent customer?',
                'criteria' => [
                    'G1' => 'Do not send a follow-up: the customer ended the chat or declined, has an after-sale problem for the support team, or wants something the shop does not sell.',
                    'G2' => 'Follow up about payment: the customer picked or confirmed an order (or got the order/transfer summary) and stopped before paying.',
                    'G3' => 'Follow up to continue the sale: the customer is interested or has a worry/objection about a product the shop sells and has in stock.',
                    'G4' => 'Wait and notify when restocked: the customer needs a product that is out of stock.',
                ],
            ],
            'declined' => [
                'type' => 'noul',
                'instructions' => 'Did the customer say, in their own words, that they do not want to buy for now or want to stop?',
                'criteria' => [
                    'true' => 'Yes',
                    'false' => 'No',
                ],
            ],
        ];
    }

    /**
     * คำถาม check ทั้งหก เขียนออกมาในไฟล์เทสเอง — ตรวจ byte-for-byte
     *
     * @return array<string, mixed>
     */
    private static function expectedCheckQuestions(): array
    {
        return [
            'support_free' => [
                'type' => 'noul',
                'instructions' => 'Does the draft say or imply that Support service is free?',
                'criteria' => ['true' => 'Yes', 'false' => 'No'],
            ],
            'promise' => [
                'type' => 'noul',
                'instructions' => 'Does the draft promise a result, guarantee, or a completion/response time about Support, verification, bans or the sale?',
                'criteria' => ['true' => 'Yes', 'false' => 'No'],
            ],
            'cannot_self' => [
                'type' => 'noul',
                'instructions' => 'Does the draft say the customer cannot file or do the task himself?',
                'criteria' => ['true' => 'Yes', 'false' => 'No'],
            ],
            'price_stock' => [
                'type' => 'noul',
                'instructions' => 'Does the draft quote a price, discount, stock quantity, or current stock availability?',
                'criteria' => ['true' => 'Yes', 'false' => 'No'],
            ],
            'pressure' => [
                'type' => 'noul',
                'instructions' => 'Does the draft pressure or threaten the customer to buy or pay?',
                'criteria' => ['true' => 'Yes', 'false' => 'No'],
            ],
            'off_topic' => [
                'type' => 'noul',
                'instructions' => 'Is the draft unrelated to what this customer left open in the conversation?',
                'criteria' => ['true' => 'Yes', 'false' => 'No'],
            ],
        ];
    }

    /**
     * body ตอบกลับแบบถูกต้อง (รูปร่างจากที่ Lead วัดจริง)
     *
     * @return array<string, mixed>
     */
    private function validSortBody(string $choice = 'G2', float|int $confidence = 0.87, float|int $declined = 0.12): array
    {
        return [
            'answers' => [
                'group' => ['type' => 'choice', 'choice' => $choice, 'confidence' => $confidence, 'probabilities' => []],
                'declined' => ['type' => 'noul', 'noul' => $declined],
            ],
            'usage' => ['cost' => 0.001, 'cost_details' => [], 'input_tokens' => 123, 'output_tokens' => 45, 'is_byok' => false],
        ];
    }

    /** @return array<string, mixed> */
    private function expectedUsage(): array
    {
        return ['input_tokens' => 123, 'output_tokens' => 45, 'cost' => 0.001];
    }

    #[Test]
    public function test_prompts_hold_the_frozen_strings(): void
    {
        $service = new \App\Services\FollowUp\FollowUpPrompts;

        $this->assertSame('sort-v1', $service::SORT_VERSION);
        $this->assertSame('check-v1', $service::CHECK_VERSION);

        // ประโยคร้าน + ต่อ suffix เมื่อมีบรรทัด "A: " — hash ตามการ์ด
        $this->assertSame(
            'ecace332841ad7e61af2599698aea2f8d06bff07a3f80c797582f63b20d5488f',
            hash('sha256', $service::shopSentence($this->context)),
            'shop sentence bytes must match the frozen string',
        );

        $withAgent = array_merge($this->context, ['A: รอเจ้าหน้าที่ตอบกลับอยู่นะครับ']);
        $this->assertSame(
            '688f07ac3b501dff88e638e17e975bbedd7fa68d0e7f1f4a7dee8e5cee3491cc',
            hash('sha256', $service::shopSentence($withAgent)),
            'shop sentence with agent suffix must match the frozen string',
        );

        $this->assertSame(self::expectedSortQuestions(), $service::sortQuestions());
        $this->assertSame(self::expectedCheckQuestions(), $service::checkQuestions());
    }

    #[Test]
    public function test_sort_posts_frozen_body_once_without_agent_suffix(): void
    {
        Http::fake([$this->url => Http::response($this->validSortBody(), 200)]);
        $service = app(\App\Services\FollowUp\FollowUpDecisionsService::class);

        $result = $service->sort($this->context, true, false);

        $this->assertNotNull($result);
        $expected = [
            'model' => config('customer_follow_up.decisions_model'),
            'state' => [
                'shop' => \App\Services\FollowUp\FollowUpPrompts::shopSentence($this->context),
                'known_facts' => [
                    'bought_from_this_shop_before' => true,
                    'transfer_summary_was_sent' => false,
                ],
                'conversation' => $this->context,
            ],
            'questions' => self::expectedSortQuestions(),
        ];

        Http::assertSent(function (Request $request) use ($expected): bool {
            return $request->url() === $this->url
                && $request->hasHeader('Authorization', 'Bearer '.$this->key)
                && $request->data() === $expected;
        });
        Http::assertSentCount(1);
    }

    #[Test]
    public function test_sort_posts_shop_with_agent_suffix_when_agent_line_present(): void
    {
        Http::fake([$this->url => Http::response($this->validSortBody(), 200)]);
        $service = app(\App\Services\FollowUp\FollowUpDecisionsService::class);

        $withAgent = array_merge($this->context, ['A: รอเจ้าหน้าที่ตอบกลับอยู่นะครับ']);
        $result = $service->sort($withAgent, false, true);

        $this->assertNotNull($result);
        $expected = [
            'model' => config('customer_follow_up.decisions_model'),
            'state' => [
                'shop' => \App\Services\FollowUp\FollowUpPrompts::shopSentence($withAgent),
                'known_facts' => [
                    'bought_from_this_shop_before' => false,
                    'transfer_summary_was_sent' => true,
                ],
                'conversation' => $withAgent,
            ],
            'questions' => self::expectedSortQuestions(),
        ];

        Http::assertSent(fn (Request $request) => $request->data() === $expected);
        Http::assertSentCount(1);
    }

    /**
     * @param  array<string, mixed>|string  $body
     * @param  array{group: string, confidence: float, declined: float, cost: ?float, usage: array<string, mixed>}|null  $expected
     */
    #[Test]
    #[DataProvider('sortParsingProvider')]
    public function test_sort_parses_only_valid_answers(array|string $body, int $status, bool $connectionError, ?array $expected): void
    {
        if ($connectionError) {
            Http::fake([$this->url => fn (): string => throw new ConnectionException('cURL error 28')]);
        } else {
            Http::fake([$this->url => Http::response($body, $status)]);
        }
        $service = app(\App\Services\FollowUp\FollowUpDecisionsService::class);

        $result = $service->sort($this->context, false, false);

        if ($expected === null) {
            $this->assertNull($result);
        } else {
            $this->assertNotNull($result);
            $this->assertSame($expected['group'], $result['group']);
            $this->assertSame($expected['confidence'], $result['confidence']);
            $this->assertSame($expected['declined'], $result['declined']);
            $this->assertSame($expected['cost'], $result['cost']);
            $this->assertSame($expected['usage'], $result['usage']);
            $this->assertIsInt($result['latency_ms']);
            $this->assertGreaterThanOrEqual(0, $result['latency_ms']);
            $this->assertArrayNotHasKey('answers', $result, 'D30: no answers key in the return value');
        }
        Http::assertSentCount(1);
    }

    public static function sortParsingProvider(): array
    {
        return [
            'valid' => [
                'body' => [
                    'answers' => [
                        'group' => ['type' => 'choice', 'choice' => 'G2', 'confidence' => 0.87, 'probabilities' => []],
                        'declined' => ['type' => 'noul', 'noul' => 0.12],
                    ],
                    'usage' => ['cost' => 0.001, 'cost_details' => [], 'input_tokens' => 123, 'output_tokens' => 45, 'is_byok' => false],
                ],
                'status' => 200,
                'connectionError' => false,
                'expected' => ['group' => 'G2', 'confidence' => 0.87, 'declined' => 0.12, 'cost' => 0.001, 'usage' => ['input_tokens' => 123, 'output_tokens' => 45, 'cost' => 0.001]],
            ],
            'int_scores_accepted' => [
                'body' => [
                    'answers' => [
                        'group' => ['type' => 'choice', 'choice' => 'G3', 'confidence' => 1, 'probabilities' => []],
                        'declined' => ['type' => 'noul', 'noul' => 0],
                    ],
                    'usage' => ['cost' => null, 'input_tokens' => 1, 'output_tokens' => 1],
                ],
                'status' => 200,
                'connectionError' => false,
                'expected' => ['group' => 'G3', 'confidence' => 1.0, 'declined' => 0.0, 'cost' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'cost' => null]],
            ],
            'status_500' => ['body' => ['error' => 'boom'], 'status' => 500, 'connectionError' => false, 'expected' => null],
            'status_401' => ['body' => ['error' => 'unauthorized'], 'status' => 401, 'connectionError' => false, 'expected' => null],
            'connection_exception' => ['body' => [], 'status' => 200,                'connectionError' => true, 'expected' => null],
            'non_json_body' => ['body' => 'not json at all', 'status' => 200, 'connectionError' => false, 'expected' => null],
            'answers_missing' => ['body' => ['usage' => []], 'status' => 200, 'connectionError' => false, 'expected' => null],
            'choice_g5' => [
                'body' => [
                    'answers' => [
                        'group' => ['type' => 'choice', 'choice' => 'G5', 'confidence' => 0.9, 'probabilities' => []],
                        'declined' => ['type' => 'noul', 'noul' => 0.1],
                    ],
                ],
                'status' => 200,
                'connectionError' => false,
                'expected' => null,
            ],
            'confidence_above_one' => [
                'body' => [
                    'answers' => [
                        'group' => ['type' => 'choice', 'choice' => 'G2', 'confidence' => 1.2, 'probabilities' => []],
                        'declined' => ['type' => 'noul', 'noul' => 0.1],
                    ],
                ],
                'status' => 200,
                'connectionError' => false,
                'expected' => null,
            ],
            'confidence_negative' => [
                'body' => [
                    'answers' => [
                        'group' => ['type' => 'choice', 'choice' => 'G2', 'confidence' => -0.1, 'probabilities' => []],
                        'declined' => ['type' => 'noul', 'noul' => 0.1],
                    ],
                ],
                'status' => 200,
                'connectionError' => false,
                'expected' => null,
            ],
            'confidence_as_string' => [
                'body' => [
                    'answers' => [
                        'group' => ['type' => 'choice', 'choice' => 'G2', 'confidence' => '0.9', 'probabilities' => []],
                        'declined' => ['type' => 'noul', 'noul' => 0.1],
                    ],
                ],
                'status' => 200,
                'connectionError' => false,
                'expected' => null,
            ],
            'confidence_null' => [
                'body' => [
                    'answers' => [
                        'group' => ['type' => 'choice', 'choice' => 'G2', 'confidence' => null, 'probabilities' => []],
                        'declined' => ['type' => 'noul', 'noul' => 0.1],
                    ],
                ],
                'status' => 200,
                'connectionError' => false,
                'expected' => null,
            ],
            'declined_missing' => [
                'body' => [
                    'answers' => [
                        'group' => ['type' => 'choice', 'choice' => 'G2', 'confidence' => 0.9, 'probabilities' => []],
                    ],
                ],
                'status' => 200,
                'connectionError' => false,
                'expected' => null,
            ],
            'declined_above_one' => [
                'body' => [
                    'answers' => [
                        'group' => ['type' => 'choice', 'choice' => 'G2', 'confidence' => 0.9, 'probabilities' => []],
                        'declined' => ['type' => 'noul', 'noul' => 2],
                    ],
                ],
                'status' => 200,
                'connectionError' => false,
                'expected' => null,
            ],
        ];
    }

    #[Test]
    public function test_sort_refuses_unsafe_context_without_any_request(): void
    {
        $service = app(\App\Services\FollowUp\FollowUpDecisionsService::class);

        $rows = [
            'account_data_line' => [$this->accountDataLine()],
            'redact_changed_line' => [$this->redactChangedLine()],
            'empty_context' => [],
            'eleven_lines' => array_map(fn (int $i): string => 'C: ข้อความทดสอบบรรทัดที่ '.$i, range(1, 11)),
        ];

        foreach ($rows as $name => $context) {
            $this->assertNull($service->sort($context, false, false), "row {$name} must fail closed");
        }
        Http::assertNothingSent();
    }

    #[Test]
    public function test_sort_fails_closed_when_not_configured(): void
    {
        $service = app(\App\Services\FollowUp\FollowUpDecisionsService::class);

        config(['services.openrouter.api_key' => '']);
        $this->assertNull($service->sort($this->context, false, false));
        Http::assertNothingSent();

        config(['services.openrouter.api_key' => $this->key, 'customer_follow_up.decisions_url' => '']);
        $this->assertNull($service->sort($this->context, false, false));
        Http::assertNothingSent();
    }

    #[Test]
    public function test_check_posts_frozen_body_once(): void
    {
        $answers = [];
        foreach (array_keys(self::expectedCheckQuestions()) as $key) {
            $answers[$key] = ['type' => 'noul', 'noul' => 0.1];
        }
        Http::fake([$this->url => Http::response(['answers' => $answers, 'usage' => ['cost' => 0.002, 'input_tokens' => 200, 'output_tokens' => 30]], 200)]);
        $service = app(\App\Services\FollowUp\FollowUpDecisionsService::class);

        $result = $service->check($this->context, 'G2', 'ยังสนใจเพจ 5 เพจอยู่ไหมครับ');

        $this->assertNotNull($result);
        $expected = [
            'model' => config('customer_follow_up.decisions_model'),
            'state' => [
                'shop' => \App\Services\FollowUp\FollowUpPrompts::shopSentence($this->context),
                'conversation' => $this->context,
                'group' => 'G2',
                'draft' => 'ยังสนใจเพจ 5 เพจอยู่ไหมครับ',
            ],
            'questions' => self::expectedCheckQuestions(),
        ];

        Http::assertSent(function (Request $request) use ($expected): bool {
            return $request->url() === $this->url
                && $request->hasHeader('Authorization', 'Bearer '.$this->key)
                && $request->data() === $expected;
        });
        Http::assertSentCount(1);
    }

    /**
     * @param  array<string, float|null>|null  $answers  noul ต่อคำถาม — null = คีย์ถูกละไว (missing)
     * @param  list<string>  $expectedFailed
     */
    #[Test]
    #[DataProvider('checkScoresProvider')]
    public function test_check_scores_threshold_and_question_order(?array $answers, bool $expectedError, array $expectedFailed): void
    {
        $answersBody = [];
        foreach ($answers ?? [] as $key => $noul) {
            if ($noul === null) {
                continue; // null = คีย์ขาดหายจริง (ไม่ส่งคีย์ใน body)
            }
            $answersBody[$key] = ['type' => 'noul', 'noul' => $noul];
        }
        Http::fake([$this->url => Http::response(['answers' => $answersBody], 200)]);
        $service = app(\App\Services\FollowUp\FollowUpDecisionsService::class);

        $result = $service->check($this->context, 'G2', 'ยังสนใจเพจ 5 เพจอยู่ไหมครับ');

        $this->assertIsArray($result);
        $this->assertSame($expectedError, $result['error']);
        $this->assertSame($expectedFailed, $result['failed']);
        $this->assertTrue($result['error'] === false || $result['failed'] !== [], 'error is never an all-clear');
        Http::assertSentCount(1);
    }

    public static function checkScoresProvider(): array
    {
        $all = fn (float $value): array => [
            'support_free' => $value, 'promise' => $value, 'cannot_self' => $value,
            'price_stock' => $value, 'pressure' => $value, 'off_topic' => $value,
        ];

        return [
            'all_below_threshold' => ['answers' => $all(0.1), 'expectedError' => false, 'expectedFailed' => []],
            'boundary_049_not_failed' => ['answers' => $all(0.49), 'expectedError' => false, 'expectedFailed' => []],
            'boundary_050_failed' => ['answers' => array_merge($all(0.1), ['price_stock' => 0.5]), 'expectedError' => false, 'expectedFailed' => ['price_stock']],
            'failed_follows_question_order' => [
                'answers' => array_merge($all(0.1), ['off_topic' => 0.7, 'promise' => 0.6, 'support_free' => 0.9]),
                'expectedError' => false,
                'expectedFailed' => ['support_free', 'promise', 'off_topic'],
            ],
            'missing_score_is_error' => [
                'answers' => array_merge($all(0.1), ['pressure' => null]),
                'expectedError' => true,
                'expectedFailed' => ['check_error'],
            ],
            'string_score_is_error' => [
                'answers' => array_merge($all(0.1), ['off_topic' => null]),
                'expectedError' => true,
                'expectedFailed' => ['check_error'],
            ],
        ];
    }

    #[Test]
    public function test_check_missing_score_leaves_that_score_null_and_flags_check_error(): void
    {
        $all = ['support_free' => 0.1, 'promise' => 0.1, 'cannot_self' => 0.1, 'price_stock' => 0.1, 'pressure' => 0.1, 'off_topic' => 0.1];
        unset($all['pressure']);
        Http::fake([$this->url => Http::response(['answers' => array_map(fn (float $noul): array => ['type' => 'noul', 'noul' => $noul], $all)], 200)]);
        $service = app(\App\Services\FollowUp\FollowUpDecisionsService::class);

        $result = $service->check($this->context, 'G2', 'ยังสนใจอยู่ไหมครับ');

        $this->assertIsArray($result);
        $this->assertTrue($result['error']);
        $this->assertSame(['check_error'], $result['failed']);
        $this->assertNull($result['scores']['pressure']);
        $this->assertSame(0.1, $result['scores']['support_free']);
    }

    #[Test]
    public function test_check_string_score_leaves_that_score_null_and_flags_check_error(): void
    {
        $all = ['support_free' => 0.1, 'promise' => 0.1, 'cannot_self' => 0.1, 'price_stock' => 0.1, 'pressure' => 0.1, 'off_topic' => 0.1];
        $answers = array_map(fn (float $noul): array => ['type' => 'noul', 'noul' => $noul], $all);
        $answers['off_topic'] = ['type' => 'noul', 'noul' => '0.9'];
        Http::fake([$this->url => Http::response(['answers' => $answers], 200)]);
        $service = app(\App\Services\FollowUp\FollowUpDecisionsService::class);

        $result = $service->check($this->context, 'G2', 'ยังสนใจอยู่ไหมครับ');

        $this->assertIsArray($result);
        $this->assertTrue($result['error']);
        $this->assertSame(['check_error'], $result['failed']);
        $this->assertNull($result['scores']['off_topic']);
    }

    #[Test]
    public function test_check_http_and_connection_errors_give_check_error_without_scores(): void
    {
        $service = app(\App\Services\FollowUp\FollowUpDecisionsService::class);

        Http::fake([$this->url => Http::response(['error' => 'boom'], 500)]);
        $result = $service->check($this->context, 'G2', 'ยังสนใจอยู่ไหมครับ');
        $this->assertIsArray($result);
        $this->assertTrue($result['error']);
        $this->assertSame(['check_error'], $result['failed']);
        $this->assertSame(array_fill_keys(array_keys(self::expectedCheckQuestions()), null), $result['scores']);
        Http::assertSentCount(1);

        Http::fake([$this->url => fn (): string => throw new ConnectionException('cURL error 28')]);
        $result = $service->check($this->context, 'G2', 'ยังสนใจอยู่ไหมครับ');
        $this->assertIsArray($result);
        $this->assertTrue($result['error']);
        $this->assertSame(['check_error'], $result['failed']);
        $this->assertTrue($result['failed'] !== []);
        Http::assertSentCount(1);
    }

    #[Test]
    public function test_check_refuses_unsafe_context_or_draft_without_any_request(): void
    {
        $service = app(\App\Services\FollowUp\FollowUpDecisionsService::class);

        $rows = [
            'account_data_line' => [$this->accountDataLine()],
            'redact_changed_line' => [$this->redactChangedLine()],
            'empty_context' => [],
            'eleven_lines' => array_map(fn (int $i): string => 'C: ข้อความทดสอบบรรทัดที่ '.$i, range(1, 11)),
        ];
        foreach ($rows as $name => $context) {
            $result = $service->check($context, 'G2', 'ยังสนใจอยู่ไหมครับ');
            $this->assertIsArray($result, "row {$name} must fail closed");
            $this->assertTrue($result['error'], "row {$name} must not be an all-clear");
            $this->assertSame(['check_error'], $result['failed'], "row {$name} must flag check_error");
        }

        // draft ที่มี account data — บริบทปกติ แต่ร่างไม่ปลอด
        $result = $service->check($this->context, 'G2', 'ยังสนใจอยู่ไหม login foo'.'@'.'mail.com|secret2026');
        $this->assertIsArray($result);
        $this->assertTrue($result['error']);
        $this->assertSame(['check_error'], $result['failed']);

        Http::assertNothingSent();
    }

    #[Test]
    public function test_check_fails_closed_when_not_configured(): void
    {
        $service = app(\App\Services\FollowUp\FollowUpDecisionsService::class);

        config(['services.openrouter.api_key' => '']);
        $result = $service->check($this->context, 'G2', 'ยังสนใจอยู่ไหมครับ');
        $this->assertIsArray($result);
        $this->assertTrue($result['error']);
        $this->assertSame(['check_error'], $result['failed']);
        Http::assertNothingSent();

        config(['services.openrouter.api_key' => $this->key, 'customer_follow_up.decisions_url' => '']);
        $result = $service->check($this->context, 'G2', 'ยังสนใจอยู่ไหมครับ');
        $this->assertIsArray($result);
        $this->assertTrue($result['error']);
        $this->assertSame(['check_error'], $result['failed']);
        Http::assertNothingSent();
    }

    #[Test]
    public function test_failures_log_once_with_step_and_reason_only(): void
    {
        $canary = 'CANARY'.'B3BPROBE';

        // sort กับ check ล้มเหลวด้วย 500 — canary อยู่ในบริบทของ sort และใน body ที่ fake ไว้
        // (fake ตัวที่สองแทนที่ทั้งหมด: ทั้งสองครั้งเจอ body เดียวกัน)
        $contextWithCanary = array_merge($this->context, ['C: ข้อความ '.$canary.' ธรรมดา']);
        Http::fake([$this->url => Http::response(['error' => $canary], 500)]);

        Log::shouldReceive('warning')->twice()->withArgs(function (string $message, array $context) use ($canary): bool {
            $serialized = $message.json_encode($context, JSON_UNESCAPED_UNICODE);

            return array_keys($context) === ['step', 'reason'] && ! str_contains($serialized, $canary);
        });

        $service = app(\App\Services\FollowUp\FollowUpDecisionsService::class);
        $this->assertNull($service->sort($contextWithCanary, false, false));
        $result = $service->check($this->context, 'G2', 'ยังสนใจอยู่ไหมครับ');
        $this->assertIsArray($result);
        $this->assertTrue($result['error']);
    }
}
