<?php

namespace Tests\Unit\FollowUp;

use App\Services\FollowUp\FollowUpAccountDataDetector;
use App\Support\FollowUpRedactor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\FollowUp\AccountDataDetectorTest;
use Tests\TestCase;

/**
 * การ์ด B3a — FollowUpRedactor: ตัวปิดข้อมูลลับก่อนส่งแชตให้ AI (plan §3.4, D18/D20)
 *
 * สัญญา:
 * - redact: NFKC + ตัด zero-width/control/bidi แล้วแทนที่ของลับด้วย marker [redacted:<kind>] คงที่
 * - containsAccountData: delegate ไป detector อย่างเดียว (D15 — pattern อยู่ใน class เดียว)
 * - assertSafeOutbound: โยน InvalidArgumentException ที่ message เป็นโค้ดใดโค้ดหนึ่งเท่านั้น
 * - snapshot: null เมื่อข้อความใดมี account data; ไม่งั้นบรรทัด C:/B:/A: ล่าสุด ≤10 บรรทัด รวม <8000 code points
 * - properties: containsAccountData(redact(x))=false, redact idempotent, marker ไม่ match pattern ใด
 *
 * ทุก fixture ที่มีรูปร่าง credential สร้าง runtime จากชิ้นส่วน (ผ่าน gitleaks ทั้ง RED และ GREEN)
 */
class RedactorTest extends TestCase
{
    /** marker ทั้งหมดที่ redact ใช้ — ตรวจว่าไม่ตัวใด match pattern (property 3) */
    private const MARKERS = [
        '[redacted:credential_line]',
        '[redacted:label_value]',
        '[redacted:jwt]',
        '[redacted:bearer]',
        '[redacted:fb_cookie]',
        '[redacted:url_userinfo]',
        '[redacted:url_query]',
        '[redacted:url_fragment]',
        '[redacted:email]',
        '[redacted:digits]',
        '[redacted:digit_groups]',
        '[redacted:unreadable]',
    ];

    private function canary(string $tag): string
    {
        return 'X'.$tag.'Y2026Zq';
    }

    /**
     * ครบทุก kind ในการ์ด B3a: credential line (plain/spaced/fullwidth), label-value,
     * JWT, Bearer, fb cookie, URL userinfo/query/fragment, email, เลขติดกัน, เลขคั่นกลุ่ม, unreadable
     *
     * @return array<string, array{input: string, canaries: list<string>}>
     */
    private function redactionFixtures(): array
    {
        $digitRunCanary = '913'.'2026'.'47';
        $digitGroupsCanary = '818'.'2026'.'29';

        return [
            'credential_line_plain' => [
                'input' => 'ล็อกอินส่งมา '.'foo'.'@'.'mail.com|'.$this->canary('crd'),
                'canaries' => [$this->canary('crd')],
            ],
            'credential_line_spaced' => [
                'input' => 'foo'.'@'.'mail.com | '.$this->canary('spd').' | otp',
                'canaries' => [$this->canary('spd')],
            ],
            'credential_line_fullwidth' => [
                'input' => 'foo'.'@'.'mail.com｜'.$this->canary('fwp'),
                'canaries' => [$this->canary('fwp')],
            ],
            'label_value' => [
                'input' => 'รหัสผ่านของร้าน pass'.'word: '.$this->canary('lbl'),
                'canaries' => [$this->canary('lbl')],
            ],
            'jwt' => [
                'input' => 'token: '.'eyJ'.'hbGciOiJIUzI1NiJ9'.'.'.'eyJ'.'zdWIiOiIxMjM0NTY3ODkwIn0'.'.'.$this->canary('jwt').'zzQQ1122',
                'canaries' => [$this->canary('jwt')],
            ],
            'bearer' => [
                'input' => 'พารามิเตอร์ Bearer '.$this->canary('br').'aabbccdd1122',
                'canaries' => [$this->canary('br')],
            ],
            'fb_cookie' => [
                'input' => 'เซสชัน c_user='.$this->canary('fbk'),
                'canaries' => [$this->canary('fbk')],
            ],
            'url_userinfo' => [
                'input' => 'ล็อกอินที่ https://admin:'.$this->canary('usr').'@shop.example.com/login',
                'canaries' => [$this->canary('usr')],
            ],
            'url_query' => [
                'input' => 'ลิงก์ยืนยัน https://orders.example.com/checkout?code='.$this->canary('qry'),
                'canaries' => [$this->canary('qry')],
            ],
            'url_fragment' => [
                'input' => 'ลิงก์ https://docs.example.com/page#ref='.$this->canary('frg'),
                'canaries' => [$this->canary('frg')],
            ],
            'email' => [
                'input' => 'ติดต่อ '.$this->canary('eml').'@shop.example.com ครับ',
                'canaries' => [$this->canary('eml')],
            ],
            'digits_run' => [
                'input' => 'โอนเข้า 000'.$digitRunCanary.'555 ครับ',
                'canaries' => [$digitRunCanary],
            ],
            'digits_separated_groups' => [
                'input' => 'โอนเข้า 12 345 '.$digitGroupsCanary.' 555 ครับ',
                'canaries' => [$digitGroupsCanary],
            ],
            'unreadable' => [
                'input' => "\xC3\x28",
                'canaries' => [],
            ],
        ];
    }

    /** ข้อความขายปกติ — ต้องคงเดิมมาก (หลัง normalization แล้วเหมือนเดิมทุก code point) */
    private function keptTexts(): array
    {
        return [
            'สินค้าตัวนี้ยังมีไหมครับ อยากได้สีดำครับ',
            'ราคา 1,299 บาท รวมส่งแล้วครับ',
            'เอา 3 ตัว ครับ',
            'หมายเลขออเดอร์ 123456789 ครับ',
            'สนใจ Nolimit BM ครับ',
            'สวัสดีครับพี่ 🙏 ขอบคุณครับ',
            'ขอใบเสนอราคาทางอีเมลหน่อยครับ พอไหมครับ',
            '',
        ];
    }

    #[Test]
    public function test_redacts_before_truncation_per_kind(): void
    {
        foreach ($this->redactionFixtures() as $kind => $fixture) {
            $output = FollowUpRedactor::redact($fixture['input']);

            foreach ($fixture['canaries'] as $canary) {
                $this->assertStringNotContainsString($canary, $output, "kind $kind: canary ต้องหายไปจาก redact()");
            }

            $lines = FollowUpRedactor::snapshot([
                ['sender' => 'user', 'type' => 'text', 'content' => $fixture['input']],
            ]);

            if ($lines === null) {
                continue; // account data → caller หยุดก่อนเรียก model ไม่มีบรรทัดให้ตรวจ
            }

            foreach ($lines as $line) {
                foreach ($fixture['canaries'] as $canary) {
                    $this->assertStringNotContainsString($canary, $line, "kind $kind: canary ต้องหายไปจากทุกบรรทัด snapshot");
                }
            }
        }
    }

    #[Test]
    public function test_secret_across_the_1000_codepoint_cut_does_not_survive(): void
    {
        $canary = $this->canary('cut');
        // email เริ่ม cp ที่ 986 แล้วยาวข้ามจุดตัด 1000 — ต้อง redact ก่อนตัด จึงไม่เหลือเศษ
        $content = str_repeat('ก', 985).$canary.'@shop.example.com ขอบคุณครับ';

        $lines = FollowUpRedactor::snapshot([
            ['sender' => 'user', 'type' => 'text', 'content' => $content],
        ]);

        $this->assertNotNull($lines);
        $this->assertCount(1, $lines);
        $this->assertStringNotContainsString($canary, $lines[0], 'ลับที่คาบขอบตัด 1000 ต้องไม่รอดแม้เศษเดียว');
        $this->assertLessThanOrEqual(FollowUpRedactor::MAX_MESSAGE_CODEPOINTS + mb_strlen('C: '), mb_strlen($lines[0]));
        $this->assertStringContainsString('[redacted:emai', $lines[0], 'พิสูจน์ว่า redact เกิดก่อนตัด (marker โผล่ก่อนโดนตัด)');
    }

    #[Test]
    public function test_keeps_normal_thai_text_exactly(): void
    {
        // "คงเดิม (หลัง normalization)" = เท่ากับฉบับ NFKC ของอินพุต
        // (NFKC แยก ำ U+0E33 เป็น ํ+า ตาม Unicode — ตัวอักษรเดิม ไม่มีอะไรถูกตัด)
        foreach ($this->keptTexts() as $text) {
            $expected = FollowUpAccountDataDetector::normalize($text);
            $this->assertNotNull($expected);
            $this->assertSame($expected, FollowUpRedactor::redact($text), 'ข้อความปกติต้องคงเดิมหลัง normalization');
            $this->assertFalse(FollowUpRedactor::containsAccountData($text), 'ข้อความปกติไม่ใช่ account data');
        }

        // ตัวอักษร ASCII/เลข/emoji ล้วน — NFKC เป็น identity ต้องได้ string เดิมมาก
        $this->assertSame('ราคา 1,299 บาท ออเดอร์ 123456789 Nolimit BM 🙏', FollowUpRedactor::redact('ราคา 1,299 บาท ออเดอร์ 123456789 Nolimit BM 🙏'));
    }

    #[Test]
    public function test_redact_normalises_before_matching(): void
    {
        $this->assertSame('[redacted:label_value]', FollowUpRedactor::redact('ｐａｓｓｗｏｒｄ：'.'Sup3rSecret'), 'fullwidth label+value');
        $this->assertSame('[redacted:label_value]', FollowUpRedactor::redact('p'."\u{202E}".'assword: '.'Sup3rSecret'), 'bidi คั่น label');
        $this->assertSame('[redacted:digits]', FollowUpRedactor::redact('１２３４５６７８９０'), 'fullwidth เลข 10 หลัก → NFKC → digit run');
        $this->assertSame('[redacted:digits]', FollowUpRedactor::redact('๐๑๒๓๔๕๖๗๘๙'), 'เลขไทย 10 หลัก นับเป็น digit run');
        $this->assertSame('โอน ๐๑๒ ๐๓๔ ครับ', FollowUpRedactor::redact('โอน ๐๑๒ ๐๓๔ ครับ'), 'เลขไทยรวมไม่ถึง 10 หลัก คงเดิม');
    }

    #[Test]
    public function test_account_data_stops_before_models(): void
    {
        $credentialLines = [
            'foo'.'@'.'mail.com|p@ssw0rd9',
            'foo'.'@'.'mail.com | p@ssw0rd9 | otp',
            'foo'.'@'.'mail.com｜p@ssw0rd9',
        ];

        foreach ($credentialLines as $line) {
            $this->assertNull(FollowUpRedactor::snapshot([
                ['sender' => 'bot', 'type' => 'text', 'content' => 'สวัสดีครับ'],
                ['sender' => 'user', 'type' => 'text', 'content' => $line],
            ]), 'บรรทัด credential ('.$line.') ต้องหยุดก่อนเรียก model');
        }

        $this->assertNull(FollowUpRedactor::snapshot([
            ['sender' => 'user', 'type' => 'image', 'content' => 'pass'.'word: Sup3rSecret'],
        ]), 'non-text แต่ content มี account data ก็ต้องหยุด');

        foreach (AccountDataDetectorTest::redactor_parity_fixtures() as $fixture) {
            $this->assertSame(
                $fixture['is_account_data'],
                FollowUpAccountDataDetector::containsAccountData($fixture['text']),
                'detector ต้องตอบตรง fixture: '.var_export($fixture['text'], true),
            );
            $this->assertSame(
                $fixture['is_account_data'],
                FollowUpRedactor::containsAccountData($fixture['text']),
                'redactor ต้องตอบตรง detector ทุก fixture: '.var_export($fixture['text'], true),
            );
        }
    }

    #[Test]
    public function test_properties_hold_over_every_fixture(): void
    {
        $texts = [];
        foreach (AccountDataDetectorTest::redactor_parity_fixtures() as $fixture) {
            $texts[] = $fixture['text'];
        }
        foreach ($this->redactionFixtures() as $fixture) {
            $texts[] = $fixture['input'];
        }
        foreach ($this->keptTexts() as $text) {
            $texts[] = $text;
        }

        foreach ($texts as $text) {
            $redacted = FollowUpRedactor::redact($text);

            $this->assertFalse(
                FollowUpRedactor::containsAccountData($redacted),
                'property 1: หลัง redact ต้องไม่เหลือ account data — input '.var_export($text, true),
            );
            $this->assertSame(
                $redacted,
                FollowUpRedactor::redact($redacted),
                'property 2: redact ต้อง idempotent — input '.var_export($text, true),
            );
        }

        foreach (self::MARKERS as $marker) {
            $this->assertSame($marker, FollowUpRedactor::redact($marker), 'marker ต้องเป็น fixed point ของ redact');
            $this->assertFalse(FollowUpAccountDataDetector::containsAccountData($marker), 'marker ต้องไม่ match detector');
            $this->assertFalse(FollowUpRedactor::containsAccountData($marker), 'marker ต้องไม่ match redactor');
        }
    }

    #[Test]
    public function test_assert_safe_outbound_throws_one_code_per_case(): void
    {
        $cases = [
            ['pass'.'word: '.'Sup3rSecret', 'account_data'],
            ['ลูกค้าพิมพ์มาว่า [redacted:email] ครับ', 'redaction_marker'],
            ["สวัสดีครับ\x01พี่", 'control_character'],
            ['ข้อความ'."\u{202E}".'อ่านยาก', 'control_character'],
            ['ก'."\u{200B}".'ข', 'control_character'],
            ["\xC3\x28", 'unreadable'],
            [substr('🙏', 0, 2), 'unreadable'],
        ];

        foreach ($cases as [$text, $code]) {
            try {
                FollowUpRedactor::assertSafeOutbound($text);
                $this->fail("ควรโยน InvalidArgumentException โค้ด $code");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($code, $exception->getMessage(), 'message ต้องเป็นโค้ดล้วน ไม่มีเนื้อความ');
            }
        }
    }

    #[Test]
    public function test_assert_safe_outbound_allows_normal_thai_message(): void
    {
        $text = "สวัสดีครับพี่ 🙏\nสินค้า Nolimit BM ที่พี่เลือกไว้ยังค้างอยู่นะครับ\nถ้ายังสนใจ พิมพ์มาได้เลยครับ ราคา 1,299 บาท"
            .str_repeat(' ขอบคุณมากครับ', 40);

        $this->assertGreaterThan(400, mb_strlen($text));
        $this->assertNull(FollowUpRedactor::assertSafeOutbound($text));
        $this->assertNull(FollowUpRedactor::assertSafeOutbound(''));
    }

    #[Test]
    public function test_snapshot_keeps_newest_ten_lines(): void
    {
        $messages = [];
        foreach (range(1, 12) as $i) {
            $messages[] = ['sender' => 'user', 'type' => 'text', 'content' => 'ข้อความ '.$i];
        }

        $lines = FollowUpRedactor::snapshot($messages);

        $this->assertNotNull($lines);
        $this->assertCount(FollowUpRedactor::MAX_LINES, $lines);
        $this->assertSame('C: ข้อความ 3', $lines[0], 'เก่าสุดสองข้อความต้องถูกทิ้ง');
        $this->assertSame('C: ข้อความ 12', $lines[9]);
    }

    #[Test]
    public function test_snapshot_roles_non_text_unknown_sender_and_null_content(): void
    {
        $lines = FollowUpRedactor::snapshot([
            ['sender' => 'user', 'type' => 'text', 'content' => 'ตกลงครับ'],
            ['sender' => 'bot', 'type' => 'text', 'content' => 'รับคำสั่งครับ'],
            ['sender' => 'agent', 'type' => 'text', 'content' => 'พนักงานดูแลให้ครับ'],
            ['sender' => 'user', 'type' => 'image', 'content' => null],
            ['sender' => 'customer', 'type' => 'text', 'content' => 'ผู้ส่งไม่รู้จัก ต้องหลุด'],
            ['sender' => 'user', 'type' => 'Sticker!', 'content' => null],
            ['sender' => 'bot', 'type' => 'text', 'content' => null],
        ]);

        $this->assertSame([
            'C: '.FollowUpAccountDataDetector::normalize('ตกลงครับ'),
            'B: '.FollowUpAccountDataDetector::normalize('รับคำสั่งครับ'), // NFKC แยก ำ เป็น ํ+า
            'A: '.FollowUpAccountDataDetector::normalize('พนักงานดูแลให้ครับ'),
            'C: [non-text:image]',
            'C: [non-text:sticker]',
            'B:',
        ], $lines);
    }

    #[Test]
    public function test_snapshot_total_cap_drops_oldest_lines(): void
    {
        $messages = [];
        foreach (range(1, 10) as $i) {
            $messages[] = ['sender' => 'user', 'type' => 'text', 'content' => str_repeat('ก', 900).$i];
        }

        $lines = FollowUpRedactor::snapshot($messages);

        $this->assertNotNull($lines);
        $total = array_sum(array_map(fn (string $line): int => mb_strlen($line, 'UTF-8'), $lines));
        $this->assertLessThan(FollowUpRedactor::MAX_TOTAL_CODEPOINTS, $total, 'รวมทั้งก้อนต้องต่ำกว่า 8000 code points');
        $this->assertSame(8, count($lines), '10 บรรทัด × ~904cp เกินงบ 8000 → ต้องทิ้งเก่าสุดจนเหลือ 8');
        $this->assertSame('3', mb_substr($lines[0], -1), 'ข้อความ 1-2 ต้องถูกทิ้ง');
        $this->assertSame('0', mb_substr($lines[7], -1), 'ข้อความ 10 ยังอยู่');
    }

    #[Test]
    public function test_unreadable_and_empty_inputs_fail_closed(): void
    {
        $this->assertSame('[redacted:unreadable]', FollowUpRedactor::redact("\xC3\x28"));
        $this->assertSame('[redacted:unreadable]', FollowUpRedactor::redact("สวัสดี\xC3\x28ครับ"));
        $this->assertTrue(FollowUpRedactor::containsAccountData("\xC3\x28"));
        $this->assertNull(FollowUpRedactor::snapshot([
            ['sender' => 'user', 'type' => 'text', 'content' => "\xC3\x28"],
        ]));
        $this->assertSame('', FollowUpRedactor::redact(''));
        $this->assertSame([], FollowUpRedactor::snapshot([]));
    }

    #[Test]
    public function test_regex_failure_fails_closed(): void
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '500');

        try {
            // segment ยาวที่ทำให้ regex ใน line check backtrack เกินลิมิต → preg error → fail closed (D20)
            // ตั้งใจให้ท่อนแรก ('zzz') ไม่ match อะไรเลย เพื่อบังคับว่ามาถึงจุดนี้ได้
            // ทางเดียวคือ PASSWORD_LIKE บน segment ยาว — ผิดพลาดได้ทางเดียวคือ preg error
            $text = 'zzz|'.'a1'.str_repeat('a', 6000).'!';

            $this->assertTrue(FollowUpAccountDataDetector::containsAccountData($text), 'preg error ต้อง fail closed');
            $this->assertTrue(FollowUpRedactor::containsAccountData($text));
            $this->assertSame('[redacted:unreadable]', FollowUpRedactor::redact($text));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }

    #[Test]
    public function test_adversarial_50000_chars_finishes_under_two_seconds(): void
    {
        $chunk = 'user'.'@'.'mail.com|pass'.'word: Secret123|123456789012|'."\n";
        $canary = $this->canary('adv');
        $text = str_repeat($chunk, 1400).'user'.'@'.'mail.com|pass'.'word: '.$canary.'|'."\n";

        $this->assertGreaterThanOrEqual(50000, mb_strlen($text));

        $start = hrtime(true);
        $output = FollowUpRedactor::redact($text);
        $redactMs = (hrtime(true) - $start) / 1e6;

        $start = hrtime(true);
        $hasAccountData = FollowUpRedactor::containsAccountData($text);
        $detectMs = (hrtime(true) - $start) / 1e6;

        fwrite(STDERR, sprintf("[perf] len=%d redact=%.0fms detect=%.0fms\n", mb_strlen($text), $redactMs, $detectMs));

        $this->assertTrue($hasAccountData, 'อินพุตมีบรรทัด credential → ต้อง true (หรือ fail closed)');
        $this->assertStringNotContainsString($canary, $output);
        $this->assertFalse(FollowUpRedactor::containsAccountData($output));
        $this->assertLessThan(2000, $redactMs, 'redact ต้องจบภายใน 2 วินาที');
        $this->assertLessThan(2000, $detectMs, 'containsAccountData ต้องจบภายใน 2 วินาที');
    }
}
