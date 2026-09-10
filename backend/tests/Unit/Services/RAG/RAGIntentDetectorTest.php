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

    public function test_is_high_stakes_message_matches_configured_keywords(): void
    {
        $this->assertTrue($this->detector->isHighStakesMessage('ยืนยันผู้รับผลประโยชน์รึยังครับ'));
        $this->assertTrue($this->detector->isHighStakesMessage('ผลประโยชน์ที่ได้รับคืออะไร'));
        $this->assertTrue($this->detector->isHighStakesMessage('ต้องยืนยันตัวตนยังไงคะ'));
        $this->assertTrue($this->detector->isHighStakesMessage('ประกันตัวนี้คุ้มครองอะไรบ้าง'));
        $this->assertTrue($this->detector->isHighStakesMessage('เคลมประกันยังไง'));
        $this->assertTrue($this->detector->isHighStakesMessage('ราคานี้รวม VAT หรือยัง'));
        $this->assertTrue($this->detector->isHighStakesMessage('ต้องเสียภาษีเพิ่มไหม'));
        $this->assertTrue($this->detector->isHighStakesMessage('สินค้านี้รับประกันกี่ปี'));
    }

    public function test_is_high_stakes_message_does_not_match_bare_confirm_keyword(): void
    {
        // "ยืนยัน" alone is the order-confirmation command in the sales flow —
        // it must NOT be treated as high-stakes or it breaks order confirmation.
        $this->assertFalse($this->detector->isHighStakesMessage('ยืนยัน'));
        $this->assertFalse($this->detector->isHighStakesMessage('ยืนยันค่ะ'));
        $this->assertFalse($this->detector->isHighStakesMessage('ยืนยันออเดอร์นี้เลยครับ'));
    }

    public function test_is_high_stakes_message_false_for_unrelated_text(): void
    {
        $this->assertFalse($this->detector->isHighStakesMessage('ขอราคาสินค้า Nolimit Level Up ทุกแพ็กเกจ'));
    }
}
