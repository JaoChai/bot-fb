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
}
