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
        '[redacted:url]',
        '[redacted:email]',
        '[redacted:email_pair]',
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
            // ==== รอบ 2 — Lead D21 (URL ทั้ง token) + เลขกลุ่ม ≥1 หลัก/คั่นใหม่ + อีเมล IDN ====
            'url_query_then_fragment' => [
                'input' => 'https://shop.test/pay?ok=1#'.$this->canary('u1'),
                'canaries' => [$this->canary('u1')],
            ],
            'url_paren_fragment' => [
                'input' => 'https://shop.test/a(b)#'.$this->canary('u2'),
                'canaries' => [$this->canary('u2')],
            ],
            'url_query_no_equals' => [
                'input' => 'https://shop.test/pay?'.$this->canary('u3'),
                'canaries' => [$this->canary('u3')],
            ],
            'url_query_key_kept' => [
                'input' => 'https://shop.test/pay?'.$this->canary('u4').'=1',
                'canaries' => [$this->canary('u4')],
            ],
            'url_path_token' => [
                'input' => 'https://shop.test/reset/'.$this->canary('u5'),
                'canaries' => [$this->canary('u5')],
            ],
            'url_www_token' => [
                'input' => 'ดู www.shop.test/pay?a=1&b='.$this->canary('u6').' จ้า',
                'canaries' => [$this->canary('u6')],
            ],
            'url_schemeless_slash' => [
                'input' => 'ลิงก์ shop.test/pay?code='.$this->canary('u7').' ครับ',
                'canaries' => [$this->canary('u7')],
            ],
            'url_schemeless_hash' => [
                'input' => 'shop.test/page#'.$this->canary('u8'),
                'canaries' => [$this->canary('u8')],
            ],
            'url_mid_thai_sentence' => [
                'input' => 'ดูที่https://shop.test/pay?code='.$this->canary('u9').'ครับ',
                'canaries' => [$this->canary('u9')],
            ],
            'url_email_in_query' => [
                'input' => 'https://shop.test/pay?mail='.$this->canary('ua').'@mail.test',
                'canaries' => [$this->canary('ua')],
            ],
            'url_digits_in_path' => [
                'input' => 'ออเดอร์ https://shop.test/order/007202611 ครับ',
                'canaries' => ['007202611'],
            ],
            'url_userinfo_full_replace' => [
                'input' => 'ล็อกอินที่ https://admin:'.$this->canary('ub').'@shop.example.com/login',
                'canaries' => [$this->canary('ub')],
            ],
            'phone_groups_slash' => [
                'input' => 'โอนเข้า 081/234/'.('5678'.'11').' ครับ',
                'canaries' => [('5678'.'11')],
            ],
            'phone_groups_parens' => [
                'input' => 'เบอร์ (081) 234-'.('5678'.'22').' ครับ',
                'canaries' => [('5678'.'22')],
            ],
            'bank_groups_single_digit' => [
                'input' => 'โอนเข้าใหม่ '.('123'.'-'.'4'.'-'.'56789'.'-'.'0').' ครับ',
                'canaries' => [('123'.'-'.'4'.'-'.'56789'.'-'.'0')],
            ],
            'id_groups_edge_digits' => [
                'input' => 'บัตร '.('1'.'-'.'2345'.'-'.'67890'.'-'.'12'.'-'.'3').' ครับ',
                'canaries' => [('1'.'-'.'2345'.'-'.'67890'.'-'.'12'.'-'.'3')],
            ],
            'email_idn_thai_domain' => [
                'input' => 'ติดต่อ สมชาย'.$this->canary('ei').'@'.'ตัวอย่าง.ไทย ครับ',
                'canaries' => [$this->canary('ei')],
            ],
            'email_glued_thai_local' => [
                'input' => 'ดูที่สมชาย'.$this->canary('ej').'@shop.example.com',
                'canaries' => [$this->canary('ej')],
            ],
            // ==== รอบ 3 — Lead 02:01: URL รูปร่างที่ D21 ยังเหลือ (พอร์ต/โฮสต์เลข/localhost/www เกาไทย) ====
            'url_host_port' => [
                'input' => 'ลิงก์ shop.test:8080/reset/'.$this->canary('v1').' ครับ',
                'canaries' => [$this->canary('v1')],
            ],
            'url_numeric_host' => [
                'input' => '192.168.1.1/admin?k='.$this->canary('v2'),
                'canaries' => [$this->canary('v2')],
            ],
            'url_numeric_host_port' => [
                'input' => '192.168.1.1:8080/admin?k='.$this->canary('v3'),
                'canaries' => [$this->canary('v3')],
            ],
            'url_localhost_port' => [
                'input' => 'localhost:8000/reset/'.$this->canary('v4'),
                'canaries' => [$this->canary('v4')],
            ],
            'url_www_glued_thai' => [
                'input' => 'ดูที่www.'.$this->canary('v5').'.com จ้า',
                'canaries' => [$this->canary('v5')],
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
            // ==== รอบ 2 — คงเดิมตาม D21/เลขกลุ่มใหม่: ราคา, สัดส่วน, ประโยคไทยมี #?, hashtag, วันที่ 8 หลัก ====
            'ราคา 1.500/ชิ้น ครับ',
            'ราคา 1,500 บาท ครับ',
            'ประเภทไหนดีครับ?#ตอบหน่อยครับ',
            '#ลดแรง โปรโมชันวันนี้ครับ',
            '15/11/2026 ส่งได้ไหมครับ',
            // ==== รอบ 3 — D22: ประโยคไทยมีสระอำ (ทำ/น้ำ/คำ/สำหรับ/จำนวน) ต้องคงเดิมทุกไบต์ ====
            'สนใจครับ เดี๋ยวผมทำรายการเองครับ',
            'น้ำมันเครื่อง จำนวน 2 ขวด สำหรับรถเก๋งครับ',
            'คำสั่งซื้อนี้ยืนยันแล้วครับ',
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
        // รีวิวรอบ 3 — D22 migration: เทียบกับ input ตรงๆ ไม่ใช่ normalize()
        // (เดิม NFKC แยกสระอำ U+0E33 เป็น ํ+า และการเทียบ normalize() กลืนบั๊กนั้นไป —
        // ข้อความปกติต้องออกจาก redact ตรงตามที่พิมพ์ทุกไบต์)
        foreach ($this->keptTexts() as $text) {
            $this->assertSame($text, FollowUpRedactor::redact($text), 'ข้อความปกติต้องคงเดิมตรงตามที่พิมพ์ (D22)');
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
    public function test_redacts_whole_url_token_per_lead_d21(): void
    {
        // รอบ 2 ข้อ 1 — Lead D21 แทนกฎ query/fragment เดิม: redact แทนที่ URL ทั้ง token
        // ด้วย [redacted:url] เดียว เพราะแบบจับเป็นชิ้นเศษหลุด (probe ของ Lead 01:27)
        // (a) scheme:// ถึงช่องว่าง — fragment ต่อท้าย query ก็หายไปกับทั้งลิงก์
        $this->assertSame('[redacted:url]', FollowUpRedactor::redact('https://shop.test/pay?ok=1#'.$this->canary('d1')));
        $this->assertSame('[redacted:url]', FollowUpRedactor::redact('https://shop.test/a(b)#'.$this->canary('d2')));
        $this->assertSame('[redacted:url]', FollowUpRedactor::redact('https://shop.test/pay?'.$this->canary('d3')));
        $this->assertSame('[redacted:url]', FollowUpRedactor::redact('https://shop.test/pay?'.$this->canary('d4').'=1'));
        $this->assertSame('[redacted:url]', FollowUpRedactor::redact('https://shop.test/reset/'.$this->canary('d5')));
        // (b) token ขึ้นต้น www. — ช่องว่างข้างหน้าคงเดิม
        $this->assertSame('ดู [redacted:url] จ้า', FollowUpRedactor::redact('ดู www.shop.test/pay?a=1&b='.$this->canary('d6').' จ้า'));
        // (c) schemeless host.tld (TLD อักษร) ตามด้วย / ? # — ถึงช่องว่าง
        $this->assertSame('ลิงก์ [redacted:url] ครับ', FollowUpRedactor::redact('ลิงก์ shop.test/pay?code='.$this->canary('d7').' ครับ'));
        $this->assertSame('[redacted:url]', FollowUpRedactor::redact('shop.test/page#'.$this->canary('d8')));
        // URL ติดกลางประโยคไทย (ไม่มีช่องว่างรอบ) — กินถึงช่องว่าง ตาม D21
        $this->assertSame('ดูที่[redacted:url]', FollowUpRedactor::redact('ดูที่https://shop.test/pay?code='.$this->canary('d9').'ครับ'));
        // email ใน query — ทั้ง token จบด้วย url marker เดียว
        $this->assertSame('[redacted:url]', FollowUpRedactor::redact('https://shop.test/pay?mail='.$this->canary('da').'@mail.test'));
        // เลขใน path — หายไปพร้อมลิงก์
        $this->assertSame('ออเดอร์ [redacted:url] ครับ', FollowUpRedactor::redact('ออเดอร์ https://shop.test/order/007202611 ครับ'));
        // userinfo URL — detector ตัด span ไปแล้ว ส่วน host/path ที่เหลือต้องถูกแทนจนไม่เหลือ :// หรือ host
        $this->assertSame(
            'ล็อกอินที่ [redacted:url_userinfo][redacted:url]',
            FollowUpRedactor::redact('ล็อกอินที่ https://admin:'.$this->canary('db').'@shop.example.com/login'),
        );
    }

    #[Test]
    public function test_digit_groups_with_single_digit_group_and_new_separators(): void
    {
        // รอบ 2 ข้อ 3+4 — กลุ่มเลขกลุ่มเดียวก็นับ (ธนาคาร xxx-x-xxxxx-x, เลขบัตร) และคั่นใหม่ / ( )
        $this->assertSame('โอนเข้าใหม่ [redacted:digit_groups] ครับ', FollowUpRedactor::redact('โอนเข้าใหม่ 123-4-56789-0 ครับ'));
        $this->assertSame('บัตร [redacted:digit_groups] ครับ', FollowUpRedactor::redact('บัตร 1-2345-67890-12-3 ครับ'));
        $this->assertSame('โอนเข้า [redacted:digit_groups] ครับ', FollowUpRedactor::redact('โอนเข้า 081/234/567811 ครับ'));
        $this->assertSame('เบอร์ ([redacted:digit_groups] ครับ', FollowUpRedactor::redact('เบอร์ (081) 234-5678 ครับ'));
        // ต้องคงเดิม: ราคา (คั่น ,), วันที่ 8 หลัก, ปริมาณ, เลขออเดอร์ 9 หลัก
        $this->assertSame('ราคา 1,299 บาท', FollowUpRedactor::redact('ราคา 1,299 บาท'));
        $this->assertSame('15/11/2026 ส่งได้ไหมครับ', FollowUpRedactor::redact('15/11/2026 ส่งได้ไหมครับ'));
        $this->assertSame('เอา 3 ตัว ครับ', FollowUpRedactor::redact('เอา 3 ตัว ครับ'));
        $this->assertSame('หมายเลขออเดอร์ 123456789 ครับ', FollowUpRedactor::redact('หมายเลขออเดอร์ 123456789 ครับ'));
    }

    #[Test]
    public function test_email_with_non_ascii_letters_redacted(): void
    {
        // รอบ 2 ข้อ 5 — อีเมลอักษรไทย (IDN) ต้องถูกตัด; คำไทยติดหน้า address ถูกกินรวมได้ (Lead รับข้อ 5)
        $this->assertSame(
            'ติดต่อ [redacted:email] ครับ',
            FollowUpRedactor::redact('ติดต่อ สมชาย'.$this->canary('ei').'@'.'ตัวอย่าง.ไทย ครับ'),
        );
        $this->assertSame(
            '[redacted:email]',
            FollowUpRedactor::redact('ดูที่สมชาย'.$this->canary('ej').'@shop.example.com'),
            'คำไทยติดกับ address ถูกกินรวม — over-redaction ยอมรับได้ตาม Lead',
        );
        $this->assertSame(
            'ส่งที่ [redacted:email] จ้า',
            FollowUpRedactor::redact('ส่งที่ somchai'.$this->canary('ek').'@shop.example.com จ้า'),
        );
    }

    #[Test]
    public function test_thai_sara_am_text_survives_byte_for_byte_per_lead_d22(): void
    {
        // รีวิวรอบ 3 ข้อ 1 — Lead D22: NFKC แยกสระอำ U+0E33 เป็น U+0E4D U+0E32 ทำให้ redact
        // เปลี่ยนการสะกดของประโยคไทยปกติ (probe ของ Lead: 'ทำ น้ำ คำ' 9 → 12 code points)
        // ข้อความที่ไม่ใช่ลับต้องออกมาตรงตามที่พิมพ์ — เทียบกับ input ล้วน ไม่เทียบ normalize()
        $sentences = [
            'ทำ น้ำ คำ',
            'สนใจครับ จะทำรายการตอนไหนดี',
            'สำหรับลูกค้าใหม่ จำนวนเงินเท่าไหร่ครับ',
            'คำสั่งซื้อนี้ยืนยันแล้วครับ',
            'ผมลืมรหัสผ่านครับ ต้องทำยังไง',
        ];
        foreach ($sentences as $sentence) {
            $this->assertSame($sentence, FollowUpRedactor::redact($sentence), 'redact ต้องคงสระอำเดิมทุกไบต์');
            $this->assertSame($sentence, FollowUpAccountDataDetector::normalize($sentence), 'normalize ต้องคืนสระอำเดิม');
        }

        $lines = FollowUpRedactor::snapshot([
            ['sender' => 'user', 'type' => 'text', 'content' => 'สนใจทำรายการครับ'],
        ]);
        $this->assertSame(['C: สนใจทำรายการครับ'], $lines, 'snapshot ต้องไม่เปลี่ยนสะกดสระอำ');

        // label ยังถูกจับ แม้ข้อความรอบตัวมีสระอำ
        $this->assertSame(
            'คำสั่งนี้ [redacted:label_value]',
            FollowUpRedactor::redact('คำสั่งนี้ pass'.'word: Sup3rSecret'),
            'label ต้องยังจับเมื่อข้อความรอบตัวมีสระอำ',
        );
    }

    #[Test]
    public function test_normalize_fixpoint_and_properties_on_invisible_split_inputs(): void
    {
        // รีวิวรอบ 4 ข้อ 1 — normalize ต้องเป็น fixed point: ตัดอักขระมองไม่เห็น "ก่อน" NFKC
        // (แบบเดิม NFKC ก่อนแล้วค่อยตัด: อักขระมองไม่เห็นที่คั่นฐานออกจากวรรณยุกต์ถูกตัดทีหลัง
        //  ทิ้งลำดับที่ยังไม่ normalize ไว้ → redact รอบสอง compose ต่อ → ละเมิด
        //  property redact(redact(x)) = redact(x) และ containsAccountData(redact(x)) = false)
        $inputs = [
            "e\u{200B}\u{0301}",
            "ท\u{0E4D}\u{200B}\u{0E32}",
            "a\u{00AD}\u{0308}",
            'd|'."\u{FF50}\u{00AD}\u{0301}".'e'.'yJ1@',
            'e'."\u{034F}\u{0301}",
            'ก'."\u{FEFF}\u{0E33}",
        ];

        foreach ($inputs as $input) {
            $label = bin2hex($input);

            $once = FollowUpAccountDataDetector::normalize($input);
            $this->assertNotNull($once, 'อินพุต valid UTF-8 ต้อง normalize ได้: '.$label);
            $this->assertSame(
                $once,
                FollowUpAccountDataDetector::normalize($once),
                'normalize ต้องเป็น fixed point — input '.$label,
            );

            $redacted = FollowUpRedactor::redact($input);
            $this->assertFalse(
                FollowUpRedactor::containsAccountData($redacted),
                'property 1: หลัง redact ต้องไม่เหลือ account data — input '.$label.' redacted='.bin2hex($redacted),
            );
            $this->assertSame(
                $redacted,
                FollowUpRedactor::redact($redacted),
                'property 2: redact ต้อง idempotent — input '.$label.' redacted='.bin2hex($redacted),
            );
        }
    }

    #[Test]
    public function test_seeded_property_fuzz_fixpoint_idempotence_and_no_account_data(): void
    {
        // รีวิวรอบ 4 ข้อ 3 (Lead 02:30) — property fuzz แบบ seed ตายตัว 20,000 สตริง:
        // normalize(normalize(x)) = normalize(x), redact(redact(x)) = redact(x),
        // containsAccountData(redact(x)) = false ต้องครบทุกสตริง พิมพ์ตัวแรกที่ละเมิดเป็น hex
        mt_srand(20261115);

        $alphabet = [
            'a', 'b', 'c', 'x', 'y', 'z', 'E', 'G', '0', '1', '7', '9',
            'ก', 'ข', 'จ', 'ด', 'ต', 'น', 'บ', 'ป', 'ม', 'ร', 'ล', 'ส', 'อ', 'า', 'เ', 'แ',
            '่', '้', '๊', '๋', 'ิ', 'ี', 'ึ', 'ื', 'ุ', 'ู', '็', 'ใ', 'ไ',
            'ำ', 'ํ', 'า',
            "\u{0301}", "\u{0308}", "\u{0327}", "\u{034F}",
            "\u{00AD}", "\u{061C}", "\u{180E}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}",
            "\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}", "\u{2060}", "\u{FEFF}", "\u{E0001}", "\u{E0020}",
            'ｐ', 'Ａ', '＠', '｜', '１', '：', '＝',
            '|', '@', ':', '=', '.', '/', '#', '?', ' ',
        ];
        $max = count($alphabet) - 1;
        $failures = [];
        $start = hrtime(true);

        for ($i = 0; $i < 20000 && count($failures) < 3; $i++) {
            $input = '';
            $length = mt_rand(1, 8);
            for ($j = 0; $j < $length; $j++) {
                $input .= $alphabet[mt_rand(0, $max)];
            }

            $normalized = FollowUpAccountDataDetector::normalize($input);
            if ($normalized === null || FollowUpAccountDataDetector::normalize($normalized) !== $normalized) {
                $failures[] = 'fixpoint: normalize(normalize(input)) != normalize(input), input='.bin2hex($input);

                continue;
            }

            $redacted = FollowUpRedactor::redact($input);
            if (FollowUpRedactor::containsAccountData($redacted)) {
                $failures[] = 'property 1: containsAccountData(redact(input)) != false, input='.bin2hex($input)
                    .' redacted='.bin2hex($redacted);

                continue;
            }

            if (FollowUpRedactor::redact($redacted) !== $redacted) {
                $failures[] = 'property 2: redact(redact(input)) != redact(input), input='.bin2hex($input)
                    .' redacted='.bin2hex($redacted);

                continue;
            }
        }

        $elapsedMs = (hrtime(true) - $start) / 1e6;
        fwrite(STDERR, sprintf("[fuzz] seed=20261115 n=20000 violations=%d ms=%.0f\n", count($failures), $elapsedMs));

        $this->assertSame([], $failures, 'property ละเมิด (ตัวแรก): '.($failures[0] ?? '-'));
        $this->assertLessThan(5000, $elapsedMs, 'fuzz 20,000 สตริงต้องจบภายใน 5 วินาที');
    }

    #[Test]
    public function test_url_port_numeric_host_localhost_and_glued_www_per_lead_round3(): void
    {
        // รีวิวรอบ 3 — ขยาย D21 ตามคำสั่ง Lead 02:01: URL รูปร่างที่ยังหลุด ต้องจบด้วย
        // [redacted:url] ทั้ง token เหมือนเดิม
        // (a) โฮสต์มีพอร์ต
        $this->assertSame('ลิงก์ [redacted:url] ครับ', FollowUpRedactor::redact('ลิงก์ shop.test:8080/reset/'.$this->canary('w1').' ครับ'));
        // (b) โฮสต์เป็นเลข (IP) มีและไม่มีพอร์ต
        $this->assertSame('[redacted:url]', FollowUpRedactor::redact('192.168.1.1/admin?k='.$this->canary('w2')));
        $this->assertSame('[redacted:url]', FollowUpRedactor::redact('192.168.1.1:8080/admin?k='.$this->canary('w3')));
        // (c) localhost มีพอร์ต
        $this->assertSame('[redacted:url]', FollowUpRedactor::redact('localhost:8000/reset/'.$this->canary('w4')));
        // (d) www. เกาข้อความไทย ไม่มี path ต่อท้าย
        $this->assertSame('ดูที่[redacted:url]', FollowUpRedactor::redact('ดูที่www.'.$this->canary('w5').'.com'));
        // ต้องคงเดิมตาม Lead: ราคา วันที่ เวลา เวอร์ชัน/อัตราส่วน (ไม่มี / ? # ต่อท้ายโฮสต์)
        $this->assertSame('ราคา 1.500/ชิ้น ครับ', FollowUpRedactor::redact('ราคา 1.500/ชิ้น ครับ'));
        $this->assertSame('15/11/2026 ส่งได้ไหมครับ', FollowUpRedactor::redact('15/11/2026 ส่งได้ไหมครับ'));
        $this->assertSame('นัดรับของ 14.30 น. ครับ', FollowUpRedactor::redact('นัดรับของ 14.30 น. ครับ'));
        $this->assertSame('นัดคุย 10:30 ครับ', FollowUpRedactor::redact('นัดคุย 10:30 ครับ'));
        $this->assertSame('อัปเดตเป็นเวอร์ชัน 1.2.3 แล้วครับ', FollowUpRedactor::redact('อัปเดตเป็นเวอร์ชัน 1.2.3 แล้วครับ'));
    }

    #[Test]
    public function test_account_data_stops_before_models(): void
    {
        $credentialLines = [
            'foo'.'@'.'mail.com|p@ssw0rd9',
            'foo'.'@'.'mail.com | p@ssw0rd9 | otp',
            'foo'.'@'.'mail.com｜p@ssw0rd9',
            // รีวิวรอบ 4 ข้อ 2 — รหัสผ่านไทยมีสระ/วรรณยุกต์ (\p{M}) ต้องหยุดก่อนเรียก model
            '100012345678901|'.'น้ำใส2024',
            '100012345678901|'.'ใจดี5555',
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
            ['ราคา'."\u{00AD}".'โปรช่วงลดครับ', 'control_character'],
            ['พิมพ์'."\u{061C}".'ต่อได้ครับ', 'control_character'],
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

        // รีวิวรอบ 3 — D22 migration: เทียบกับ input ตรงๆ (เดิมใช้ normalize() ซึ่งแยก ำ เป็น ํ+า)
        $this->assertSame([
            'C: ตกลงครับ',
            'B: รับคำสั่งครับ',
            'A: พนักงานดูแลให้ครับ',
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
