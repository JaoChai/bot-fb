<?php

namespace Tests\Unit\Payment;

use App\Services\Payment\OrderPayloadExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderPayloadExtractorTest extends TestCase
{
    private OrderPayloadExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new OrderPayloadExtractor;
    }

    #[Test]
    public function test_extracts_payload_and_strips_block_from_text(): void
    {
        $content = "สรุปรายการที่พี่สั่งซื้อครับ:\n\n1. G3D (50 x 20) = 1,000 บาท\n\nรวมยอดโอน: 1,000 บาท ✅\n"
            .'[[ORDER]]{"items":[{"name":"G3D","qty":20,"price":50}],"total":1000}[[/ORDER]]';

        $result = $this->extractor->extract($content);

        $this->assertStringNotContainsString('[[ORDER]]', $result['clean']);
        $this->assertStringNotContainsString('G3D","qty"', $result['clean']);
        $this->assertStringEndsWith('รวมยอดโอน: 1,000 บาท ✅', trim($result['clean']));
        $this->assertNotNull($result['payload']);
        $this->assertSame(1000.0, $result['payload']['total']);
        $this->assertSame([['name' => 'G3D', 'qty' => 20, 'total' => '1000']], $result['payload']['items']);
    }

    #[Test]
    public function test_returns_null_payload_when_no_block(): void
    {
        $content = 'สวัสดีครับ สนใจตัวไหนดีครับ';

        $result = $this->extractor->extract($content);

        $this->assertSame($content, $result['clean']);
        $this->assertNull($result['payload']);
    }

    #[Test]
    public function test_strips_block_even_when_json_is_broken(): void
    {
        // JSON เสียก็ต้องไม่หลุดไปถึงลูกค้าเด็ดขาด — ตัดทิ้งแล้วถอยไปใช้ regex เดิม
        $content = "รวมยอดโอน: 1,000 บาท\n[[ORDER]]{\"items\":[{\"name\":\"G3D\",[[/ORDER]]";

        $result = $this->extractor->extract($content);

        $this->assertStringNotContainsString('[[ORDER]]', $result['clean']);
        $this->assertStringNotContainsString('items', $result['clean']);
        $this->assertNull($result['payload']);
    }

    #[Test]
    public function test_rejects_payload_whose_items_do_not_sum_to_total(): void
    {
        // ตาข่ายเดียวกับ Phase A: payload ที่ตัวเลขไม่สอดคล้องกันเอง = เชื่อไม่ได้
        $content = 'รวมยอดโอน: 1,000 บาท'
            .'[[ORDER]]{"items":[{"name":"G3D","qty":1,"price":50}],"total":1000}[[/ORDER]]';

        $result = $this->extractor->extract($content);

        $this->assertNull($result['payload']);
    }

    #[Test]
    public function test_computes_item_total_from_price_times_qty(): void
    {
        $content = '[[ORDER]]{"items":[{"name":"Nolimit Level Up+ BM","qty":2,"price":1100}],"total":2200}[[/ORDER]]';

        $result = $this->extractor->extract($content);

        $this->assertNotNull($result['payload']);
        $this->assertSame('2200', $result['payload']['items'][0]['total']);
        $this->assertSame(2, $result['payload']['items'][0]['qty']);
    }

    #[Test]
    public function test_rejects_payload_with_empty_or_missing_items(): void
    {
        $content = '[[ORDER]]{"items":[],"total":1000}[[/ORDER]]';

        $this->assertNull($this->extractor->extract($content)['payload']);
    }

    #[Test]
    public function test_strips_unclosed_block_so_customer_never_sees_it(): void
    {
        // โมเดลชนเพดาน token ถูกตัดกลางประโยค → บล็อกเปิดไม่ปิด ข้อมูลไม่ครบ
        // ห้าม decode แต่ข้อความที่เหลือต้องไม่มี JSON ดิบเด็ดขาด
        $content = "รวมยอดโอน: 1,000 บาท\n[[ORDER]]{\"items\":[{\"name\":\"G3D\",\"qty\":20";

        $result = $this->extractor->extract($content);

        $this->assertStringNotContainsString('[[ORDER]]', $result['clean']);
        $this->assertStringNotContainsString('items', $result['clean']);
        $this->assertNull($result['payload']);
        $this->assertStringContainsString('รวมยอดโอน: 1,000 บาท', $result['clean']);
    }

    #[Test]
    public function test_rejects_payload_with_zero_total(): void
    {
        // ไม่มี price → item total เป็น 0 → ผลรวม 0 = total 0 → checksum ต้องตก
        // ยอดโอนจริงไม่มีทางเป็น 0 → payload นี้เชื่อไม่ได้ ถอยไปใช้ regex
        $content = '[[ORDER]]{"items":[{"name":"G3D","qty":20}],"total":0}[[/ORDER]]';

        $result = $this->extractor->extract($content);

        $this->assertNull($result['payload']);
        $this->assertStringNotContainsString('[[ORDER]]', $result['clean']);
    }

    #[Test]
    public function test_clamps_non_positive_qty_to_one(): void
    {
        // qty ติดลบ/เป็น 0 ต้องไม่ทำให้จำนวนหาย → ยืนยันว่าเหลืออย่างน้อย 1
        $content = '[[ORDER]]{"items":[{"name":"G3D","qty":0,"price":1000}],"total":1000}[[/ORDER]]';

        $result = $this->extractor->extract($content);

        $this->assertNotNull($result['payload']);
        $this->assertSame(1, $result['payload']['items'][0]['qty']);
    }
}
