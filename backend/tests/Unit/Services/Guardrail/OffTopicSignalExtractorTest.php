<?php

namespace Tests\Unit\Services\Guardrail;

use App\Services\Guardrail\OffTopicSignalExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OffTopicSignalExtractorTest extends TestCase
{
    private OffTopicSignalExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new OffTopicSignalExtractor;
    }

    #[Test]
    public function test_strips_marker_and_flags_triggered(): void
    {
        $content = "ขอโทษครับพี่ อันนี้ผมช่วยไม่ได้ตรงนี้ครับ สนใจสินค้าตัวไหนดีครับ?\n[[OFFTOPIC]]";

        $result = $this->extractor->extract($content);

        $this->assertSame('ขอโทษครับพี่ อันนี้ผมช่วยไม่ได้ตรงนี้ครับ สนใจสินค้าตัวไหนดีครับ?', $result['clean']);
        $this->assertTrue($result['triggered']);
    }

    #[Test]
    public function test_no_marker_returns_original_content_untouched(): void
    {
        $content = 'BM ราคา 1,100 บาทครับ';

        $result = $this->extractor->extract($content);

        $this->assertSame($content, $result['clean']);
        $this->assertFalse($result['triggered']);
    }

    #[Test]
    public function test_marker_must_be_at_end_not_matched_mid_sentence(): void
    {
        // กันเคส LLM หลอนพิมพ์คำว่า OFFTOPIC ปนอยู่กลางประโยคโดยไม่ได้ตั้งใจ — ไม่นับเป็น
        // off-topic (circuit breaker ไม่ต้องเก็บแต้ม) แต่ marker ต้องถูกตัดทิ้งอยู่ดี
        $content = 'สินค้ารหัส [[OFFTOPIC]] ไม่มีอยู่จริงครับ ต่อด้วยคำตอบปกติ';

        $result = $this->extractor->extract($content);

        $this->assertSame('สินค้ารหัส ไม่มีอยู่จริงครับ ต่อด้วยคำตอบปกติ', $result['clean']);
        $this->assertFalse($result['triggered']);
    }

    #[Test]
    public function test_marker_followed_by_more_text_never_reaches_the_customer(): void
    {
        // ผลรันจริง 2026-09-18 (T26): โมเดลวาง marker ท้ายบรรทัดแรกแล้วเขียนต่ออีกบรรทัด
        // แบบเดิมตัดเฉพาะท้ายข้อความ ลูกค้าจึงเห็น [[OFFTOPIC]] ดิบๆ ในแชท
        $content = "ขออภัยครับ ผมช่วยเขียนโค้ดไม่ได้ครับ [[OFFTOPIC]]  \nหากพี่สนใจบัญชีโฆษณา สอบถามได้เลยครับ";

        $result = $this->extractor->extract($content);

        $this->assertStringNotContainsString('[[OFFTOPIC]]', $result['clean']);
        $this->assertSame("ขออภัยครับ ผมช่วยเขียนโค้ดไม่ได้ครับ\nหากพี่สนใจบัญชีโฆษณา สอบถามได้เลยครับ", $result['clean']);
        $this->assertFalse($result['triggered']);
    }
}
