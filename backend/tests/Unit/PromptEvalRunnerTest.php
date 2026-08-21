<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Services\AIService;
use App\Services\EmbeddingService;
use App\Services\PromptEval\PromptEvalRunner;
use App\Services\RAGService;
use App\Services\SemanticCacheService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

class PromptEvalRunnerTest extends TestCase
{
    private function bot(): Bot
    {
        return (new Bot)->forceFill(['id' => 1]);
    }

    /**
     * mock RAGService ธรรมดา (constructor ไม่ถูกเรียก) มี property semanticCache ที่ยัง
     * ไม่ initialize — ใช้ตรวจว่า runner ไม่พังเวลาไม่มี cache ให้ปิด (กรณีเทสต์ทั่วไป)
     * แต่เทสต์ที่ต้องยืนยันพฤติกรรมปิด/คืนค่า cache จริงๆ ต้อง inject SemanticCacheService
     * ตัวจริง (constructor รันจริง มี property $enabled จริงให้ override) เข้าไปแทน — ทำผ่าน
     * reflection เพราะ Mockery::mock() ไม่รัน constructor ของ RAGService ให้
     *
     * @return array{0: RAGService, 1: SemanticCacheService}
     */
    private function mockRagWithRealSemanticCache(): array
    {
        $embedding = Mockery::mock(EmbeddingService::class);
        $semanticCache = new SemanticCacheService($embedding);

        $rag = Mockery::mock(RAGService::class);
        $property = new ReflectionProperty(RAGService::class, 'semanticCache');
        $property->setAccessible(true);
        $property->setValue($rag, $semanticCache);

        return [$rag, $semanticCache];
    }

    /**
     * @param  array<string, mixed>  $overrides  ผสมทับ default result ของ AIService::generateResponse
     */
    private function runnerWithAiResponse(array $overrides = []): PromptEvalRunner
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->andReturn(array_merge([
            'content' => '',
            'cost' => 0.0,
            'order_payload' => null,
            'off_topic_triggered' => false,
        ], $overrides));

        $rag = Mockery::mock(RAGService::class);
        $rag->shouldReceive('generateResponse')->never();

        return new PromptEvalRunner($ai, $rag);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function test_must_contain_single_group_found_passes(): void
    {
        $runner = $this->runnerWithAiResponse(['content' => 'ไม่มีตัวสร้างพิกเซลเองได้ครับ']);

        $result = $runner->run($this->bot(), [
            'id' => 'case_1',
            'label' => 'เคส 1',
            'message' => 'ขอหน่อย',
            'must_contain' => [
                ['ไม่มี'],
            ],
        ]);

        $this->assertTrue($result->passed);
        $this->assertSame([], $result->failures);
    }

    #[Test]
    public function test_must_contain_two_groups_only_one_found_fails_with_missing_group_message(): void
    {
        $runner = $this->runnerWithAiResponse(['content' => 'ไม่มีครับ']);

        $result = $runner->run($this->bot(), [
            'id' => 'case_2',
            'label' => 'เคส 2',
            'message' => 'ขอหน่อย',
            'must_contain' => [
                ['ไม่มี'],
                ['สร้างพิกเซลเองได้', 'สร้างเองได้'],
            ],
        ]);

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->failures);
        $this->assertStringContainsString('สร้างพิกเซลเองได้', $result->failures[0]);
        $this->assertStringContainsString('สร้างเองได้', $result->failures[0]);
    }

    #[Test]
    public function test_must_contain_or_group_matches_second_alternative_passes(): void
    {
        $runner = $this->runnerWithAiResponse(['content' => 'สร้างเองได้ครับพี่']);

        $result = $runner->run($this->bot(), [
            'id' => 'case_3',
            'label' => 'เคส 3',
            'message' => 'ขอหน่อย',
            'must_contain' => [
                ['สร้างพิกเซลเองได้', 'สร้างเองได้'],
            ],
        ]);

        $this->assertTrue($result->passed);
    }

    #[Test]
    public function test_must_not_contain_found_fails(): void
    {
        $runner = $this->runnerWithAiResponse(['content' => 'ไม่สามารถยืนยันได้ครับ']);

        $result = $runner->run($this->bot(), [
            'id' => 'case_4',
            'label' => 'เคส 4',
            'message' => 'ขอหน่อย',
            'must_not_contain' => ['ไม่สามารถยืนยัน'],
        ]);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('ไม่สามารถยืนยัน', $result->failures[0]);
    }

    #[Test]
    public function test_regex_needle_matches(): void
    {
        $runner = $this->runnerWithAiResponse(['content' => 'ยอดรวม 1,100 บาทครับ']);

        $result = $runner->run($this->bot(), [
            'id' => 'case_5',
            'label' => 'เคส 5',
            'message' => 'ขอหน่อย',
            'must_contain' => [
                ['/1,?100/'],
            ],
        ]);

        $this->assertTrue($result->passed);
    }

    #[Test]
    public function test_bubble_separator_and_repeated_newlines_do_not_break_substring_match(): void
    {
        $runner = $this->runnerWithAiResponse(['content' => "ค่าส่งฟรีครับ|||\n\n\nสนใจไหมครับ"]);

        $result = $runner->run($this->bot(), [
            'id' => 'case_6',
            'label' => 'เคส 6',
            'message' => 'ขอหน่อย',
            'must_contain' => [
                ['ค่าส่งฟรีครับ'],
                ['สนใจไหมครับ'],
            ],
        ]);

        $this->assertTrue($result->passed);
    }

    #[Test]
    public function test_expect_off_topic_true_but_not_triggered_fails(): void
    {
        $runner = $this->runnerWithAiResponse(['content' => 'BM ราคา 1,100 บาทครับ', 'off_topic_triggered' => false]);

        $result = $runner->run($this->bot(), [
            'id' => 'case_7',
            'label' => 'เคส 7',
            'message' => 'เขียนโค้ดให้หน่อย',
            'expect_off_topic' => true,
        ]);

        $this->assertFalse($result->passed);
    }

    #[Test]
    public function test_expect_order_total_mismatch_fails_and_match_passes(): void
    {
        $runner = $this->runnerWithAiResponse([
            'content' => 'สรุปออเดอร์ครับ',
            'order_payload' => ['total' => 1100],
        ]);

        $failResult = $runner->run($this->bot(), [
            'id' => 'case_8a',
            'label' => 'เคส 8a',
            'message' => 'ยืนยันครับ',
            'expect_order' => ['total' => 2200],
        ]);

        $this->assertFalse($failResult->passed);

        $passResult = $runner->run($this->bot(), [
            'id' => 'case_8b',
            'label' => 'เคส 8b',
            'message' => 'ยืนยันครับ',
            'expect_order' => ['total' => 1100],
        ]);

        $this->assertTrue($passResult->passed);
    }

    #[Test]
    public function test_case_with_history_calls_rag_service_not_ai_service(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->never();

        $rag = Mockery::mock(RAGService::class);
        $rag->shouldReceive('generateResponse')
            ->once()
            ->withArgs(function (Bot $bot, string $userMessage, array $conversationHistory, $conversation, $flow) {
                return $conversation === null
                    && $conversationHistory === [['sender' => 'user', 'content' => 'สวัสดีครับ']];
            })
            ->andReturn(['content' => 'สวัสดีครับพี่', 'cost' => 0.0]);

        $runner = new PromptEvalRunner($ai, $rag);

        $result = $runner->run($this->bot(), [
            'id' => 'case_9',
            'label' => 'เคส 9',
            'message' => 'มีอะไรแนะนำไหม',
            'history' => [
                ['sender' => 'user', 'content' => 'สวัสดีครับ'],
            ],
            'must_contain' => [
                ['สวัสดีครับ'],
            ],
        ]);

        $this->assertTrue($result->passed);
    }

    #[Test]
    public function test_case_without_history_calls_ai_service_with_null_conversation(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')
            ->once()
            ->with(Mockery::type(Bot::class), 'ราคาเท่าไหร่ครับ', null)
            ->andReturn(['content' => '1,100 บาทครับ', 'cost' => 0.0]);

        $rag = Mockery::mock(RAGService::class);
        $rag->shouldReceive('generateResponse')->never();

        $runner = new PromptEvalRunner($ai, $rag);

        $result = $runner->run($this->bot(), [
            'id' => 'case_10',
            'label' => 'เคส 10',
            'message' => 'ราคาเท่าไหร่ครับ',
            'must_contain' => [
                ['1,100'],
            ],
        ]);

        $this->assertTrue($result->passed);
    }

    #[Test]
    public function test_expect_order_extracts_order_block_from_content_when_rag_result_has_no_order_payload_key(): void
    {
        // RAGService ไม่ตัดบล็อก [[ORDER]] ออกและไม่คืนคีย์ order_payload มาด้วย — runner ต้อง
        // แยกเองจาก content ดิบ (ก่อนตัด) แล้วค่อยตัดบล็อกออกจาก response ที่ใช้เทียบข้อความ
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->never();

        $rag = Mockery::mock(RAGService::class);
        $rag->shouldReceive('generateResponse')->once()->andReturn([
            'content' => 'สรุปออเดอร์ครับ [[ORDER]]{"total":2200}[[/ORDER]]',
            'cost' => 0.0,
        ]);

        $runner = new PromptEvalRunner($ai, $rag);

        $result = $runner->run($this->bot(), [
            'id' => 'case_12',
            'label' => 'เคส 12',
            'message' => 'ยืนยันครับ',
            'history' => [
                ['sender' => 'user', 'content' => 'ยืนยันครับ'],
            ],
            'expect_order' => ['total' => 2200],
        ]);

        $this->assertTrue($result->passed);
        $this->assertStringNotContainsString('[[ORDER]]', $result->response);
        $this->assertSame('สรุปออเดอร์ครับ', $result->response);
    }

    #[Test]
    public function test_rag_path_offtopic_marker_sets_triggered_and_is_stripped_from_response(): void
    {
        // RAGService ไม่ตัด [[OFFTOPIC]] ออกเหมือน AIService::generateResponse — runner ต้อง
        // ตรวจ+ตัดเอง ไม่งั้น expect_off_topic:true จะไม่มีวันผ่านสำหรับเคสที่มี history เลย
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->never();

        $rag = Mockery::mock(RAGService::class);
        $rag->shouldReceive('generateResponse')->once()->andReturn([
            'content' => "ขอโทษครับพี่ อันนี้ผมช่วยไม่ได้ตรงนี้ครับ\n[[OFFTOPIC]]",
            'cost' => 0.0,
        ]);

        $runner = new PromptEvalRunner($ai, $rag);

        $result = $runner->run($this->bot(), [
            'id' => 'case_13',
            'label' => 'เคส 13',
            'message' => 'เขียนโค้ดให้หน่อย',
            'history' => [
                ['sender' => 'user', 'content' => 'สวัสดีครับ'],
            ],
            'expect_off_topic' => true,
        ]);

        $this->assertTrue($result->passed);
        $this->assertStringNotContainsString('[[OFFTOPIC]]', $result->response);
    }

    #[Test]
    public function test_llm_exception_is_caught_and_reported_as_failure(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->andThrow(new \RuntimeException('timeout'));

        $rag = Mockery::mock(RAGService::class);
        $rag->shouldReceive('generateResponse')->never();

        $runner = new PromptEvalRunner($ai, $rag);

        $result = $runner->run($this->bot(), [
            'id' => 'case_11',
            'label' => 'เคส 11',
            'message' => 'ขอหน่อย',
            'must_contain' => [['อะไรก็ได้']],
        ]);

        $this->assertFalse($result->passed);
        $this->assertNotEmpty($result->failures);
        $this->assertStringContainsString('timeout', $result->failures[0]);
    }

    #[Test]
    public function test_semantic_cache_is_disabled_during_rag_call_and_restored_after(): void
    {
        // เคสจริงที่พบ: RAGService::shouldSkipCache() ไม่ได้ข้าม cache ให้เองแค่เพราะมี
        // history (skip_if_has_history default = false) — semantic cache (rag_cache table)
        // เป็นตัวเดียวกับที่เสิร์ฟลูกค้าจริง ต้องปิดเองระหว่างรัน eval เท่านั้น แล้วคืนค่าเดิม
        [$rag, $semanticCache] = $this->mockRagWithRealSemanticCache();
        $this->assertTrue($semanticCache->isEnabled(), 'baseline ต้อง enabled เหมือน production ปกติ');

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->never();

        $rag->shouldReceive('generateResponse')
            ->once()
            ->andReturnUsing(function () use ($semanticCache) {
                $this->assertFalse($semanticCache->isEnabled(), 'ต้องถูกปิดระหว่างเรียก LLM จริง');

                return ['content' => 'สวัสดีครับพี่', 'cost' => 0.0];
            });

        $runner = new PromptEvalRunner($ai, $rag);

        $runner->run($this->bot(), [
            'id' => 'case_16',
            'label' => 'เคส 16',
            'message' => 'มีอะไรแนะนำไหม',
            'history' => [
                ['sender' => 'user', 'content' => 'สวัสดีครับ'],
            ],
        ]);

        $this->assertTrue($semanticCache->isEnabled(), 'ต้องคืนค่าเดิมหลัง run() จบ');
    }

    #[Test]
    public function test_semantic_cache_is_restored_after_llm_exception(): void
    {
        [$rag, $semanticCache] = $this->mockRagWithRealSemanticCache();
        $rag->shouldReceive('generateResponse')->never();

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->andThrow(new \RuntimeException('boom'));

        $runner = new PromptEvalRunner($ai, $rag);

        $result = $runner->run($this->bot(), [
            'id' => 'case_17',
            'label' => 'เคส 17',
            'message' => 'ขอหน่อย',
        ]);

        $this->assertFalse($result->passed);
        $this->assertTrue($semanticCache->isEnabled(), 'ต้องคืนค่าเดิมแม้ LLM call จะ throw');
    }

    #[Test]
    public function test_negative_lookbehind_regex_matches_dai_but_not_mai_dai(): void
    {
        // ยืนยัน pattern /(?<!ไม่)ได้/ ที่ใช้แก้เคส page_no_bm_push ใน
        // config/prompt-eval-cases.php — เดิม must_contain: [['ได้']] ผ่านเสมอเพราะ substring
        // "ได้" เจอใน "ไม่ได้" ด้วย ต้องเช็คทั้ง 2 ทิศทางว่า negative lookbehind แก้ได้จริง
        $passRunner = $this->runnerWithAiResponse(['content' => 'ใช้กับ Personal ได้ครับ']);
        $passResult = $passRunner->run($this->bot(), [
            'id' => 'case_18a',
            'label' => 'เคส 18a',
            'message' => 'ขอหน่อย',
            'must_contain' => [['/(?<!ไม่)ได้/']],
        ]);
        $this->assertTrue($passResult->passed, implode(', ', $passResult->failures));

        $failRunner = $this->runnerWithAiResponse(['content' => 'ใช้ไม่ได้ครับ']);
        $failResult = $failRunner->run($this->bot(), [
            'id' => 'case_18b',
            'label' => 'เคส 18b',
            'message' => 'ขอหน่อย',
            'must_contain' => [['/(?<!ไม่)ได้/']],
        ]);
        $this->assertFalse($failResult->passed);
    }

    #[Test]
    public function test_empty_response_fails_even_with_only_must_not_contain(): void
    {
        // เคสที่มีแค่ must_not_contain ผ่านฟรีถ้าคำตอบว่างเปล่า (ไม่มีอะไรให้เจอ) — ต้องตกเสมอ
        // แทน เพราะคำตอบว่างเปล่าคือสัญญาณว่า LLM มีปัญหา (เช่น token หมดกลางทาง)
        $runner = $this->runnerWithAiResponse(['content' => '']);

        $result = $runner->run($this->bot(), [
            'id' => 'case_19',
            'label' => 'เคส 19',
            'message' => 'ขอหน่อย',
            'must_not_contain' => ['ไม่สามารถยืนยัน'],
        ]);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('ว่างเปล่า', implode(' ', $result->failures));
    }

    #[Test]
    public function test_regex_needle_with_trailing_modifier_still_matches(): void
    {
        // เดิมเช็คทั้งขึ้นต้น**และ**ลงท้ายด้วย "/" — needle ที่มี modifier ต่อท้าย เช่น
        // /1,?100/u ไม่เข้าเงื่อนไข (ลงท้ายด้วย "u" ไม่ใช่ "/") เลยถูกตีความเป็นการหา substring
        // ของสตริง "/1,?100/u" เอง (ไม่มีทางเจอ) แล้วเคสตกแบบเงียบๆ
        $runner = $this->runnerWithAiResponse(['content' => 'ยอดรวม 1,100 บาทครับ']);

        $result = $runner->run($this->bot(), [
            'id' => 'case_20',
            'label' => 'เคส 20',
            'message' => 'ขอหน่อย',
            'must_contain' => [['/1,?100/u']],
        ]);

        $this->assertTrue($result->passed, implode(', ', $result->failures));
    }

    #[Test]
    public function test_broken_regex_needle_fails_with_explicit_error_message(): void
    {
        // pattern ที่ compile ไม่ผ่านต้องไม่ตีกลับไปเทียบแบบ substring เงียบๆ (จะกลายเป็นหา
        // substring ของ "/(unterminated/" เองซึ่งไม่มีทางเจอ แล้วเคสตกแบบไม่รู้สาเหตุ) — ต้องตก
        // พร้อมข้อความบอกชัดว่า pattern ไหนพัง
        $runner = $this->runnerWithAiResponse(['content' => 'ตอบอะไรก็ได้ครับ']);

        $result = $runner->run($this->bot(), [
            'id' => 'case_21',
            'label' => 'เคส 21',
            'message' => 'ขอหน่อย',
            'must_contain' => [['/(unterminated/']],
        ]);

        $this->assertFalse($result->passed);
        $failureText = implode(' ', $result->failures);
        $this->assertStringContainsString('regex', $failureText);
        $this->assertStringContainsString('/(unterminated/', $failureText);
    }
}
