<?php

namespace Tests\Unit\Services\RAG;

use App\Services\RAG\RAGIntentDetector;
use Tests\TestCase;

class RAGIntentDetectorTest extends TestCase
{
    private RAGIntentDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new RAGIntentDetector;
    }

    public function test_simple_message_matches_greetings_only(): void
    {
        $this->assertTrue($this->detector->isSimpleMessage('สวัสดี'));
        $this->assertTrue($this->detector->isSimpleMessage(' hello '));
        $this->assertFalse($this->detector->isSimpleMessage('ขอราคาสินค้า Nolimit Level Up ทุกแพ็กเกจ'));
    }

    public function test_detect_complexity_short_circuits_on_greeting(): void
    {
        $this->assertSame(
            ['is_complex' => false, 'score' => 0, 'reasons' => ['greeting_detected']],
            $this->detector->detectComplexity('สวัสดี')
        );
    }

    public function test_detect_complexity_flags_multiple_questions(): void
    {
        $result = $this->detector->detectComplexity('ราคาเท่าไหร่? ส่งกี่วัน?');

        $this->assertContains('multiple_questions', $result['reasons']);
        $this->assertTrue($result['is_complex']);
    }

    public function test_detect_tool_intent_only_for_enabled_tools(): void
    {
        $this->assertFalse($this->detector->detectToolIntent('คำนวณราคา 3 ชิ้น')['needs_tool']);

        $result = $this->detector->detectToolIntent('คำนวณราคา 3 ชิ้น', ['calculate']);
        $this->assertTrue($result['needs_tool']);
        $this->assertSame('calculate', $result['tool_hint']);
    }

    public function test_detect_language(): void
    {
        $this->assertSame('thai', $this->detector->detectLanguage('สวัสดีครับ ราคาเท่าไหร่'));
        $this->assertSame('english', $this->detector->detectLanguage('how much is it'));
    }
}
