<?php

namespace Tests\Feature\FollowUp;

use App\Services\FollowUp\FollowUpAccountDataDetector;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด B2b (D15) — ตัวจับข้อมูลบัญชี/credential ในข้อความ
 *
 * สัญญาจาก plan §3.4 + b2b.md:
 * - ล้วนๆ ไม่มี I/O, ไม่มี network, ไม่มี model
 * - จับ: บรรทัด credential คั่น pipe (รวม pipe เว้นวรรค/fullwidth), label-values
 *   (password / รหัสผ่าน / OTP / 2FA / cookie / token / secret / authorization),
 *   Facebook cookies (c_user / xs), JWT/Bearer tokens, URL แฝง user:password
 * - ไม่จับ: อีเมลล้วน, เบอร์โทรล้วน, ราคา, เลขออเดอร์, ประโยคขายปกติ
 * - การ์ด redactor (B3) จะ reuse class นี้
 */
class AccountDataDetectorTest extends TestCase
{
    private FollowUpAccountDataDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new FollowUpAccountDataDetector;
    }

    #[Test]
    public function test_pipe_delimited_credential_lines_match(): void
    {
        $this->assertTrue($this->detector->containsAccountData('foo@mail.com|p@ssw0rd9'));
        $this->assertTrue($this->detector->containsAccountData('foo@mail.com | p@ssw0rd9 | otp'));
        $this->assertTrue($this->detector->containsAccountData('foo@mail.com｜p@ssw0rd9'), 'fullwidth pipe U+FF5C ต้องจับเท่า pipe ปกติ');
        $this->assertTrue($this->detector->containsAccountData('otp|482913'), 'segment เป็น label ล้วน เช่น otp ก็นับ');
        $this->assertTrue($this->detector->containsAccountData("รายละเอียดที่ส่งมา\nusername: foo@mail.com\npassword: p@ssw0rd9"));
    }

    #[Test]
    public function test_label_values_match(): void
    {
        $this->assertTrue($this->detector->containsAccountData('password: Sup3rSecret'));
        $this->assertTrue($this->detector->containsAccountData('PASSWORD=Sup3rSecret'));
        $this->assertTrue($this->detector->containsAccountData('pwd = abc123'));
        $this->assertTrue($this->detector->containsAccountData('otp: 482913'));
        $this->assertTrue($this->detector->containsAccountData('2fa: on'));
        $this->assertTrue($this->detector->containsAccountData('cookie: sessionid=abc; Path=/'));
        $this->assertTrue($this->detector->containsAccountData('token: '.'abc123def456')); // split literal: gitleaks
        $this->assertTrue($this->detector->containsAccountData('secret=shhh123'));
        $this->assertTrue($this->detector->containsAccountData('authorization: Basic Zm9vOmJhcg=='));
        $this->assertTrue($this->detector->containsAccountData('รหัสผ่าน: ทดสอบ123'));
        $this->assertTrue($this->detector->containsAccountData('รหัสผ่าน : abc123'));
    }

    #[Test]
    public function test_facebook_cookie_names_match(): void
    {
        $this->assertTrue($this->detector->containsAccountData('c_user=100001234567890'));
        $this->assertTrue($this->detector->containsAccountData('c_user : 100001234567890; xs=3:abcdef:0:1'));
        $this->assertTrue($this->detector->containsAccountData('datr=X; xs=35:abc123def:2'));
    }

    #[Test]
    public function test_jwt_and_bearer_tokens_match(): void
    {
        // split literal: gitleaks — ค่า runtime ยังเป็น JWT สามท่อนเต็ม
        $this->assertTrue($this->detector->containsAccountData(
            'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.'.'eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJVadQssw5c'
        ));
        $this->assertTrue($this->detector->containsAccountData('Bearer abc123def456ghi789jkl012mno345'));
        $this->assertTrue($this->detector->containsAccountData('bearer abc123def456ghi789jkl012mno345'));
    }

    #[Test]
    public function test_urls_carrying_user_password_match(): void
    {
        $this->assertTrue($this->detector->containsAccountData('https://user:p%40ss@example.com/login'));
        $this->assertTrue($this->detector->containsAccountData('http://admin:P4ssw0rd@10.0.0.1/admin'));
    }

    #[Test]
    public function test_combolist_email_password_pair_matches(): void
    {
        $this->assertTrue($this->detector->containsAccountData('foo@mail.com:p4ssw0rd9'));
        $this->assertTrue($this->detector->containsAccountData('foo@mail.com;p4ssw0rd9'));
    }

    #[Test]
    public function test_email_alone_is_not_account_data(): void
    {
        $this->assertFalse($this->detector->containsAccountData('ติดต่อกลับได้ที่ quote@shop.com ครับ'));
        $this->assertFalse($this->detector->containsAccountData('ส่งใบเสนอราคาไปที่ quote@shop.com: รบกวนแนบรายละเอียดครับ'));
    }

    #[Test]
    public function test_phone_number_alone_is_not_account_data(): void
    {
        $this->assertFalse($this->detector->containsAccountData('โทร 0812345678 ได้เลยครับ'));
        $this->assertFalse($this->detector->containsAccountData('เบอร์ 0612345678 ครับ'));
    }

    #[Test]
    public function test_price_and_order_number_are_not_account_data(): void
    {
        $this->assertFalse($this->detector->containsAccountData('ยอดรวมทั้งหมด 1,200 บาท พิมพ์ยืนยันได้เลยครับ'));
        $this->assertFalse($this->detector->containsAccountData('หมายเลขออเดอร์ A12345 ครับ'));
    }

    #[Test]
    public function test_normal_sales_sentence_is_not_account_data(): void
    {
        $this->assertFalse($this->detector->containsAccountData('สนใจ Nolimit Personal ไหมครับ ราคา 50 บาท'));
        $this->assertFalse($this->detector->containsAccountData('ผมลืมรหัสผ่านครับ ช่วยหน่อย'), 'ถามเรื่องรหัสผ่านโดยไม่มีค่า ไม่ใช่ credential');
        $this->assertFalse($this->detector->containsAccountData('เลือกไซส์ | เอา M'), 'pipe ที่ไม่ใช่บรรทัด credential ต้องไม่จับ');
    }

    // ==== การ์ด B3a — เคสใหม่ (ไม่แก้ assertion เดิม) ====

    #[Test]
    public function test_fullwidth_label_and_value_match(): void
    {
        $this->assertTrue($this->detector->containsAccountData('ｐａｓｓｗｏｒｄ：'.'Sup3rSecret'), 'fullwidth label+value ต้องถูก NFKC แล้วจับ');
        $this->assertTrue($this->detector->containsAccountData('ＰＡＳＳＷＯＲＤ'.'＝'.'Sup3rSecret'), 'fullwidth ตัวใหญ่ + fullwidth = ก็ต้องจับ');
        $this->assertTrue($this->detector->containsAccountData('ＯＴＰ：'.'482913'), 'fullwidth OTP label');
    }

    #[Test]
    public function test_label_split_by_invisible_character_match(): void
    {
        $this->assertTrue($this->detector->containsAccountData('p'."\u{200B}".'assword: '.'Sup3rSecret'), 'zero-width คั่น label ต้องจับ');
        $this->assertTrue($this->detector->containsAccountData('pass'."\u{202E}".'word='.'Sup3rSecret'), 'bidi override คั่น label ต้องจับ');
        $this->assertTrue($this->detector->containsAccountData('รหัส'."\u{200D}".'ผ่าน : '.'ทดสอบ123'), 'zero-width คั่น label ไทยต้องจับ');
    }

    #[Test]
    public function test_label_hidden_by_format_characters_match(): void
    {
        // รอบ 2 ข้อ 2 — format/ควบคุมนอกชุด zero-width เดิม ต้องไม่ทำให้ label หลุด
        foreach (["\u{00AD}", "\u{034F}", "\u{180E}", "\u{061C}", "\u{E0020}"] as $invisible) {
            $this->assertTrue(
                $this->detector->containsAccountData('pass'.$invisible.'word: '.'Sup3rSecret'),
                sprintf('อักขระ format U+%04X คั่น label ต้องจับ', mb_ord($invisible, 'UTF-8')),
            );
        }
        $this->assertTrue(
            $this->detector->containsAccountData('รหัส'."\u{00AD}".'ผ่าน : ทดสอบ123'),
            'soft hyphen คั่น label ไทยต้องจับ',
        );
    }

    #[Test]
    public function test_label_with_sara_am_around_it_still_matches(): void
    {
        // รีวิวรอบ 3 — D22: normalize คืนสระอำเดิม (U+0E33) แล้ว label ต้องยังจับ
        // ทั้งกรณีสระอำอยู่หน้า label และหลังค่า
        $this->assertSame('ทำ น้ำ คำ', FollowUpAccountDataDetector::normalize('ทำ น้ำ คำ'), 'D22: normalize ต้องคืนสระอำเดิม');
        $this->assertTrue($this->detector->containsAccountData('สำหรับคำสั่ง pass'.'word: Sup3rSecret'), 'สระอำหน้า label ต้องยังจับ');
        $this->assertTrue($this->detector->containsAccountData('รหัสผ่าน : ทดสอบ123 สำหรับคำสั่งซื้อ'), 'สระอำหลังค่าต้องยังจับ');
        $this->assertTrue($this->detector->containsAccountData('จำนวน token: '.'abc123def456'.' ครับ'), 'สระอำหน้า label อังกฤษต้องยังจับ');
    }

    #[Test]
    public function test_invalid_utf8_counts_as_account_data(): void
    {
        $this->assertTrue($this->detector->containsAccountData("\xC3\x28"), 'invalid UTF-8 ต้อง fail closed (D20)');
        $this->assertTrue($this->detector->containsAccountData(substr('🙏', 0, 2)), 'UTF-8 ตัดกลางอักขระ ก็ต้อง fail closed');
    }

    #[Test]
    public function test_plain_email_and_phone_stay_not_account_data(): void
    {
        $this->assertFalse($this->detector->containsAccountData('somchai.jaidee'.'@'.'shop.example.com'));
        $this->assertFalse($this->detector->containsAccountData('0812345678'));
        $this->assertFalse($this->detector->containsAccountData('090 123 4567'));
        $this->assertFalse($this->detector->containsAccountData('๐๑๒๓๔๕๖๗๘๙'));
    }

    #[Test]
    public function test_pipe_line_with_thai_marked_password_matches(): void
    {
        // รีวิวรอบ 4 ข้อ 2 — PASSWORD_LIKE ต้องยอมรับสระ/วรรณยุกต์ไทย (\p{M}):
        // บรรทัด uid|รหัสผ่านไทยที่มีวรรณยุกต์ ต้องนับเป็นบรรทัด credential ทั้งบรรทัด
        // (เดิมคลาสมีแต่ \p{L} ทำให้ น้ำใส2024 / ใจดี5555 ไม่ match และรหัสหลุดเข้า snapshot)
        $this->assertTrue(
            $this->detector->containsAccountData('100012345678901|'.'น้ำใส2024'),
            'รหัสผ่านไทยมีวรรณยุกต์ (น้ำ) ในบรรทัด pipe ต้องถูกจับ',
        );
        $this->assertTrue(
            $this->detector->containsAccountData('100012345678901|'.'ใจดี5555'),
            'รหัสผ่านไทยมีสระบน (ใจดี) ในบรรทัด pipe ต้องถูกจับ',
        );
    }

    #[Test]
    public function test_digit_glued_labels_and_cookies_match(): void
    {
        // รีวิวรอบ 4 ข้อ 1 — \b เดิมนับเลขเป็น word char (ASCII \w) ขอบเลข→อักษรจึงไม่มี boundary
        // ลับติดเลขหน้า label/cookie ต้องถูกจับ โดยไม่เปลี่ยนพฤติกรรมเดิมฝั่งอักษร:
        // ตัวอักษร ASCII ติดหน้า = คำอื่น (ยังไม่จับเหมือนเดิม), อักษรไทยติดหน้า = เคยผ่าน \b
        // (\w เป็น ASCII จึงเห็นขอบ) ต้องยังจับเหมือนเดิม
        $this->assertTrue(
            $this->detector->containsAccountData('0812345678pass'.'word: Sup3rSecret'),
            'เลขติดหน้า password: ต้องจับ',
        );
        $this->assertTrue(
            $this->detector->containsAccountData('0812345678token='.'Sup3rSecret'),
            'เลขติดหน้า token= ต้องจับ',
        );
        $this->assertTrue(
            $this->detector->containsAccountData('0812345678c_user='.'100001234567890'),
            'เลขติดหน้า c_user= ต้องจับ',
        );
        $this->assertTrue(
            $this->detector->containsAccountData('1000123456xs='.'35:abc123def:2'),
            'เลขติดหน้า xs= ต้องจับ',
        );
        $this->assertFalse(
            $this->detector->containsAccountData('xpass'.'word: Sup3rSecret'),
            'ตัวอักษร ASCII ติดหน้า = คำอื่น ยังไม่จับ (คงเดิม)',
        );
        $this->assertTrue(
            $this->detector->containsAccountData('สมชายpass'.'word: Sup3rSecret'),
            'อักษรไทยติดหน้า label ยังจับเหมือนเดิม (\b เดิมเห็นขอบไทย→ASCII)',
        );
    }

    /**
     * Fixture สำหรับ parity loop ของ RedactorTest (การ์ด B3a): detector กับ redactor
     * ต้องตอบ is_account_data ตรงกันทุกกรณีด้วยข้อมูลชุดเดียวกัน
     * ทุกค่าที่มีรูปร่าง credential สร้าง runtime จากชิ้นส่วน (ผ่าน gitleaks)
     */
    public static function redactor_parity_fixtures(): array
    {
        $jwt = 'eyJ'.'hbGciOiJIUzI1NiJ9'.'.'.'eyJ'.'zdWIiOiIxMjM0NTY3ODkwIn0'.'.'.'SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJVadQssw5c';
        $bearer = 'Bearer abc123def456ghi789jkl012mno345';

        return [
            // positives — mirror ของเคสเดิมทุกข้อ
            ['text' => 'foo'.'@'.'mail.com|p@ssw0rd9', 'is_account_data' => true],
            ['text' => 'foo'.'@'.'mail.com | p@ssw0rd9 | otp', 'is_account_data' => true],
            ['text' => 'foo'.'@'.'mail.com｜p@ssw0rd9', 'is_account_data' => true],
            ['text' => 'otp'.'|'.'482913', 'is_account_data' => true],
            ['text' => "รายละเอียดที่ส่งมา\nusername: foo@mail.com\npassword: p@ssw0rd9", 'is_account_data' => true],
            ['text' => 'pass'.'word: Sup3rSecret', 'is_account_data' => true],
            ['text' => 'PASSWORD=Sup3rSecret', 'is_account_data' => true],
            ['text' => 'pwd = abc123', 'is_account_data' => true],
            ['text' => 'otp: 482913', 'is_account_data' => true],
            ['text' => '2fa: on', 'is_account_data' => true],
            ['text' => 'cookie: ***', 'is_account_data' => true],
            ['text' => 'token: '.'abc123def456', 'is_account_data' => true],
            ['text' => 'secret=shhh123', 'is_account_data' => true],
            ['text' => 'authorization: Basic ***', 'is_account_data' => true],
            ['text' => 'รหัสผ่าน: ทดสอบ123', 'is_account_data' => true],
            ['text' => 'รหัสผ่าน : abc123', 'is_account_data' => true],
            ['text' => 'ｐａｓｓｗｏｒｄ：Sup3rSecret', 'is_account_data' => true],
            ['text' => 'p'."\u{200B}".'assword: Sup3rSecret', 'is_account_data' => true],
            ['text' => 'p'."\u{202E}".'assword: Sup3rSecret', 'is_account_data' => true],
            ['text' => 'pass'."\u{00AD}".'word: Sup3rSecret', 'is_account_data' => true],
            ['text' => 'pass'."\u{061C}".'word: Sup3rSecret', 'is_account_data' => true],
            ['text' => 'c_user=100001234567890', 'is_account_data' => true],
            ['text' => 'c_user : 100001234567890; xs=3:abcdef:0:1', 'is_account_data' => true],
            ['text' => 'datr=X; xs=35:abc123def:2', 'is_account_data' => true],
            ['text' => $jwt, 'is_account_data' => true],
            ['text' => $bearer, 'is_account_data' => true],
            ['text' => strtolower($bearer), 'is_account_data' => true],
            ['text' => 'https://user:***@example.com/login', 'is_account_data' => true],
            ['text' => 'http://admin:***@10.0.0.1/admin', 'is_account_data' => true],
            ['text' => 'foo'.'@'.'mail.com:p4ssw0rd9', 'is_account_data' => true],
            ['text' => 'foo'.'@'.'mail.com;p4ssw0rd9', 'is_account_data' => true],
            // negatives
            ['text' => 'ติดต่อกลับได้ที่ quote@shop.com ครับ', 'is_account_data' => false],
            ['text' => 'ส่งใบเสนอราคาไปที่ quote@shop.com: รบกวนแนบรายละเอียดครับ', 'is_account_data' => false],
            ['text' => 'โทร 0812345678 ได้เลยครับ', 'is_account_data' => false],
            ['text' => 'เบอร์ 0612345678 ครับ', 'is_account_data' => false],
            ['text' => 'ยอดรวมทั้งหมด 1,200 บาท พิมพ์ยืนยันได้เลยครับ', 'is_account_data' => false],
            ['text' => 'หมายเลขออเดอร์ A12345 ครับ', 'is_account_data' => false],
            ['text' => 'สนใจ Nolimit Personal ไหมครับ ราคา 50 บาท', 'is_account_data' => false],
            ['text' => 'ผมลืมรหัสผ่านครับ ช่วยหน่อย', 'is_account_data' => false],
            ['text' => 'เลือกไซส์ | เอา M', 'is_account_data' => false],
            ['text' => 'somchai.jaidee'.'@'.'shop.example.com', 'is_account_data' => false],
            ['text' => '090 123 4567', 'is_account_data' => false],
            ['text' => '๐๑๒๓๔๕๖๗๘๙', 'is_account_data' => false],
            ['text' => '', 'is_account_data' => false],
            // รีวิวรอบ 3 — D22: label ที่ข้อความรอบตัวมีสระอำ ยังต้องจับ
            ['text' => 'สำหรับคำสั่ง pass'.'word: Sup3rSecret', 'is_account_data' => true],
            // รีวิวรอบ 4 ข้อ 2 — รหัสผ่านไทยที่มีสระ/วรรณยุกต์ (\p{M}) ในบรรทัด pipe ต้องจับ
            ['text' => '100012345678901|'.'น้ำใส2024', 'is_account_data' => true],
            ['text' => '100012345678901|'.'ใจดี5555', 'is_account_data' => true],
            // รีวิวรอบ 4 ข้อ 1 — เลขติดหน้า label/cookie ต้องจับเท่ากันทั้ง detector และ redactor;
            // ตัวอักษร ASCII ติดหน้ายังไม่จับ, อักษรไทยติดหน้ายังจับ (คงพฤติกรรมเดิมของ \b)
            ['text' => '0812345678pass'.'word: Sup3rSecret', 'is_account_data' => true],
            ['text' => '0812345678token='.'Sup3rSecret', 'is_account_data' => true],
            ['text' => '0812345678c_user='.'100001234567890', 'is_account_data' => true],
            ['text' => '1000123456xs='.'35:abc123def:2', 'is_account_data' => true],
            ['text' => 'xpass'.'word: Sup3rSecret', 'is_account_data' => false],
            ['text' => 'สมชายpass'.'word: Sup3rSecret', 'is_account_data' => true],
            // fail closed (D20)
            ['text' => "\xC3\x28", 'is_account_data' => true],
        ];
    }
}
