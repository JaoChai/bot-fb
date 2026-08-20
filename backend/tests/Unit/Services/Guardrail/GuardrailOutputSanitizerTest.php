<?php

namespace Tests\Unit\Services\Guardrail;

use App\Services\Guardrail\GuardrailOutputSanitizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GuardrailOutputSanitizerTest extends TestCase
{
    private GuardrailOutputSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new GuardrailOutputSanitizer;
    }

    #[Test]
    public function test_flags_code_fence(): void
    {
        $result = $this->sanitizer->check("นี่คือโค้ดครับ\n```python\nprint('hi')\n```");

        $this->assertTrue($result['flagged']);
        $this->assertSame('code_fence', $result['reason']);
    }

    #[Test]
    public function test_flags_markdown_bold(): void
    {
        $result = $this->sanitizer->check('สินค้า **ยอดนิยม** ตัวนี้ครับ');

        $this->assertTrue($result['flagged']);
        $this->assertSame('markdown_bold', $result['reason']);
    }

    #[Test]
    public function test_flags_markdown_heading(): void
    {
        $result = $this->sanitizer->check("# หัวข้อ\nเนื้อหาต่อจากนี้");

        $this->assertTrue($result['flagged']);
        $this->assertSame('markdown_heading', $result['reason']);
    }

    #[Test]
    public function test_flags_ai_admission_thai(): void
    {
        $result = $this->sanitizer->check('ในฐานะ AI ผมไม่สามารถช่วยเรื่องนี้ได้ครับ');

        $this->assertTrue($result['flagged']);
        $this->assertSame('ai_admission_th', $result['reason']);
    }

    #[Test]
    public function test_flags_ai_admission_english(): void
    {
        $result = $this->sanitizer->check('As an AI, I cannot help with that.');

        $this->assertTrue($result['flagged']);
        $this->assertSame('ai_admission_en', $result['reason']);
    }

    #[Test]
    public function test_does_not_flag_normal_thai_sales_response(): void
    {
        $result = $this->sanitizer->check('BM ราคา 1,100 บาท/ตัวครับ ใช้ยิงแอด ติดพิกเซล และยิง Conversion API (CAPI) ได้ครับ');

        $this->assertFalse($result['flagged']);
        $this->assertNull($result['reason']);
    }

    #[Test]
    public function test_does_not_flag_english_business_terms(): void
    {
        // กัน false positive กับศัพท์ธุรกิจจริงที่เป็นภาษาอังกฤษปนอยู่ในบทสนทนาขายจริง
        // (ตัดสินใจแล้วว่าจะไม่เช็คภาษาอังกฤษล้วน — ดู design spec)
        $result = $this->sanitizer->check('Personal กับ BM ต่างกันแค่ Conversion API (CAPI) ครับ Pixel ติดได้ทั้งคู่');

        $this->assertFalse($result['flagged']);
    }

    #[Test]
    public function test_does_not_flag_bare_english_refusal_without_ai_admission(): void
    {
        // ตรวจสอบ narrowing: generic refusals "I cannot" / "I can't" เองไม่ได้ flag
        // ต้องมี "as an AI" / "I'm an AI" / "I am an AI" ถึงจะ flag
        $result = $this->sanitizer->check("Sorry, I cannot ship to a PO box. I can't process this without an order number.");

        $this->assertFalse($result['flagged']);
        $this->assertNull($result['reason']);
    }
}
