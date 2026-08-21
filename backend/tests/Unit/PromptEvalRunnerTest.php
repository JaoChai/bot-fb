<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Services\AIService;
use App\Services\PromptEval\PromptEvalRunner;
use App\Services\RAGService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PromptEvalRunnerTest extends TestCase
{
    private function bot(): Bot
    {
        return (new Bot)->forceFill(['id' => 1]);
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
        // แยกเองจาก content
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
}
