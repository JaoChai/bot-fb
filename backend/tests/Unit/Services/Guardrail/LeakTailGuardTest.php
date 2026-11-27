<?php

namespace Tests\Unit\Services\Guardrail;

use App\Services\Guardrail\LeakTailGuard;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * กติกาตัดหางความคิดหลุด ('เครดิตฟรี' + เศษ ASCII ท้ายกล่อง) — ดูรายงานเคส 'เครดิตฟรี'
 * ของบอท 26 (42 เคส ท้ายกล่อง 33 กลางประโยค 9) และเอกสารออฟไลน์ v28 ที่ reasoning
 * หลุด "เครดิตฟรี? no. Need mention Limit initial 1600 perhaps…" (finish_reason=error)
 *
 * ข้อผูกของกติกาที่เทสต์ชุดนี้คุมไว้:
 * - คำตอบที่ไม่เข้ากติกาต้องออกมาไบต์เดิมทุกไบต์ (assertSame ตรวจชนิด+ค่า)
 * - ห้ามแตะ 'บัตรเครดิต'
 * - ลูกค้าพูดเรื่องเครดิตเอง (รอบนี้หรือในประวัติฝั่งลูกค้า) → ปิดตัวตัด
 * - ตัดแล้วทั้งก้อนว่าง → คืนของเดิม (ห้ามทำให้ลูกค้าไม่ได้คำตอบ)
 */
class LeakTailGuardTest extends TestCase
{
    private LeakTailGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new LeakTailGuard;
    }

    private function guard(string $content, string $userMessage = 'สนใจครับ', array $history = [], string $delimiter = '|||'): array
    {
        return $this->guard->guard($content, $userMessage, $history, $delimiter);
    }

    #[Test]
    public function strips_trigger_word_at_end_of_bubble(): void
    {
        $result = $this->guard('รับทราบครับพี่ เครดิตฟรี');

        $this->assertSame('รับทราบครับพี่', $result['content']);
        $this->assertSame([LeakTailGuard::EVENT_STRIPPED], $result['events']);
    }

    #[Test]
    public function strips_trailing_space_tail_at_end_of_bubble(): void
    {
        $result = $this->guard('รับทราบครับพี่ เครดิตฟรี  ');

        $this->assertSame('รับทราบครับพี่', $result['content']);
        $this->assertSame([LeakTailGuard::EVENT_STRIPPED], $result['events']);
    }

    #[Test]
    public function strips_ascii_fragment_tail(): void
    {
        $result = $this->guard('ตอบแล้วครับ เครดิตฟรี? no. ask more.');

        $this->assertSame('ตอบแล้วครับ', $result['content']);
        $this->assertSame([LeakTailGuard::EVENT_STRIPPED], $result['events']);
    }

    #[Test]
    public function multi_bubble_strips_only_matching_bubble(): void
    {
        $result = $this->guard('BM ราคา 1,600 บาทครับ|||ผูกบัตรหรือเติมเงินได้ครับ|||รับทราบครับพี่ เครดิตฟรี');

        $this->assertSame('BM ราคา 1,600 บาทครับ|||ผูกบัตรหรือเติมเงินได้ครับ|||รับทราบครับพี่', $result['content']);
        $this->assertSame([LeakTailGuard::EVENT_STRIPPED], $result['events']);
    }

    #[Test]
    public function drops_bubble_that_becomes_empty_after_strip(): void
    {
        $result = $this->guard('BM ราคา 1,600 บาทครับ|||เครดิตฟรี');

        $this->assertSame('BM ราคา 1,600 บาทครับ', $result['content']);
        $this->assertSame([LeakTailGuard::EVENT_STRIPPED], $result['events']);
    }

    #[Test]
    public function keeps_original_answer_when_stripping_would_empty_it(): void
    {
        // ห้ามทำให้ลูกค้าไม่ได้คำตอบ — ตัดแล้วว่างทั้งก้อน = ส่งของเดิม + log (ข้อจำกัดที่รายงาน)
        $result = $this->guard('เครดิตฟรี');

        $this->assertSame('เครดิตฟรี', $result['content']);
        $this->assertSame([LeakTailGuard::EVENT_WOULD_EMPTY], $result['events']);
    }

    #[Test]
    public function skips_when_this_turn_user_message_mentions_credit(): void
    {
        $original = 'รับทราบครับพี่ เครดิตฟรี';

        $result = $this->guard($original, userMessage: 'มีบัตรเครดิตให้ผูกไหมครับ');

        $this->assertSame($original, $result['content']);
        $this->assertSame([], $result['events']);
    }

    #[Test]
    public function skips_when_history_user_message_mentions_credit(): void
    {
        $original = 'รับทราบครับพี่ เครดิตฟรี';

        $result = $this->guard($original, history: [
            ['sender' => 'user', 'content' => 'เครดิตฟรีมาไหมครับ'],
            ['sender' => 'bot', 'content' => 'ไม่มีครับ'],
        ]);

        $this->assertSame($original, $result['content']);
        $this->assertSame([], $result['events']);
    }

    #[Test]
    public function bot_history_mentioning_credit_does_not_disable_the_guard(): void
    {
        // เงื่อนไขนับเฉพาะฝั่งลูกค้า — บอทเคยพูดเองในประวัติไม่ทำให้ตัวตัดปิด
        $result = $this->guard('รับทราบครับพี่ เครดิตฟรี', history: [
            ['sender' => 'bot', 'content' => 'เครดิตฟรี'],
        ]);

        $this->assertSame('รับทราบครับพี่', $result['content']);
        $this->assertSame([LeakTailGuard::EVENT_STRIPPED], $result['events']);
    }

    #[Test]
    public function mid_sentence_thai_after_trigger_leaves_bytes_untouched(): void
    {
        $original = 'ขออภัยครับ เครดิตฟรีสอบถามเพิ่มได้เลยครับ';

        $result = $this->guard($original);

        $this->assertSame($original, $result['content']);
        $this->assertSame([LeakTailGuard::EVENT_MID_SENTENCE], $result['events']);
    }

    #[Test]
    public function protects_bubble_containing_bat_kredit(): void
    {
        // ห้ามแตะ 'บัตรเครดิต' — กล่องที่มีคำนี้ถูกข้ามทั้งกล่อง (ไม่ตัด ไม่ถือเป็นกลางประโยค)
        $original = 'ผูกบัตรเครดิตได้ครับ แถมเครดิตฟรี';

        $result = $this->guard($original);

        $this->assertSame($original, $result['content']);
        $this->assertSame([], $result['events']);
    }

    #[Test]
    public function bubble_with_only_bat_kredit_is_untouched(): void
    {
        $original = 'รับผูกบัตรเครดิตครับพี่';

        $result = $this->guard($original);

        $this->assertSame($original, $result['content']);
        $this->assertSame([], $result['events']);
    }

    #[Test]
    public function normal_response_is_byte_identical(): void
    {
        $original = "BM ราคา 1,600 บาทครับ\n\nผูกบัตรหรือเติมเงินก็ได้ครับ|||รวม 250 บาทครับ";

        $result = $this->guard($original);

        $this->assertSame($original, $result['content']);
        $this->assertSame([], $result['events']);
    }

    #[Test]
    public function empty_content_returns_empty_untouched(): void
    {
        $result = $this->guard('');

        $this->assertSame('', $result['content']);
        $this->assertSame([], $result['events']);
    }
}
