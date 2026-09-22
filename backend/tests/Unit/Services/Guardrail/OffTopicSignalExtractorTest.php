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
        // กันเคส LLM หลอนพิมพ์คำว่า OFFTOPIC ปนอยู่กลางประโยคโดยไม่ได้ตั้งใจ
        $content = 'สินค้ารหัส [[OFFTOPIC]] ไม่มีอยู่จริงครับ ต่อด้วยคำตอบปกติ';

        $result = $this->extractor->extract($content);

        $this->assertSame($content, $result['clean']);
        $this->assertFalse($result['triggered']);
    }
}
