<?php

namespace Tests\Unit\Services;

use App\Services\SupportRouter\LunaPayloadScrubber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Scrubber for the text we send to the Luna Decisions API (Support Router).
 *
 * IRON RULE for fixtures: every credential-looking string below is SYNTHETIC
 * and obviously fake (FAKE-...). Never copy real customer text into this file.
 */
class LunaPayloadScrubberTest extends TestCase
{
    private LunaPayloadScrubber $scrubber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scrubber = new LunaPayloadScrubber;
    }

    #[Test]
    public function test_clean_messages_come_out_byte_identical(): void
    {
        $clean = [
            '',
            'สวัสดีครับ',
            'สนใจ BM ครับ',
            'Nolimit Personal ราคา 1,600 บาทครับ VIP 1,500 ครับ',
            'เอา 40 อันครับ โอน 1600 แล้วนะครับ',
            'ตัวนี้ยิงแอดได้ไหมครับ ราคา 50 บาท',
            'โอนผ่านพร้อมเพย์ 081-234-5678 ไปแล้วครับ',
            'เบอร์ 0812345678 ครับ',
            'สวัสดีครับพี่|||ตอนนี้มีสินค้าพร้อมส่งครับ',
            'ยืนยัน 12345 ครับ', // 5 digits after a keyword: below the 6-8 OTP rule, no 5+ keyword-digit line rule (ยืนยัน is not รหัส/บัญชี)
            'อีเมลคือ fake.user@example.test ครับ', // email is explicitly OUT OF SCOPE
            "หลายบรรทัด\nบรรทัดสอง ราคา 1,600 บาท\nบรรทัดสามครับ",
        ];

        foreach ($clean as $text) {
            $this->assertSame($text, $this->scrubber->scrub($text));
        }
    }

    #[Test]
    public function test_masks_value_after_password_keywords_with_separator_english(): void
    {
        $this->assertSame('password: [รหัส] ครับ', $this->scrubber->scrub('password: FAKE-PASS-123 ครับ'));
        $this->assertSame('pwd=[รหัส]', $this->scrubber->scrub('pwd=FAKEPASS99'));
        $this->assertSame('pass : [รหัส] ครับ', $this->scrubber->scrub('pass : FAKEPW01 ครับ'));
        $this->assertSame('pw|[รหัส]', $this->scrubber->scrub('pw|FAKEPW02'));
    }

    #[Test]
    public function test_masks_value_after_password_keywords_thai_and_mixed(): void
    {
        $this->assertSame('รหัสผ่าน: [รหัส] ครับ', $this->scrubber->scrub('รหัสผ่าน: พาสเวิร์ดปลอม-1234 ครับ'));
        $this->assertSame('พาส=[รหัส]', $this->scrubber->scrub('พาส=FakePass77'));
        $this->assertSame('พาสเวิร์ด : [รหัส] นะครับ', $this->scrubber->scrub('พาสเวิร์ด : FAKE-WORD-99 นะครับ'));
        $this->assertSame('รหัส: [รหัส] ครับ', $this->scrubber->scrub('รหัส: FAKE-CODE-99 ครับ'));
    }

    #[Test]
    public function test_masks_value_after_password_keyword_with_space_when_value_has_alnum(): void
    {
        $this->assertSame('password [รหัส]', $this->scrubber->scrub('password FAKEPASS99'));
        $this->assertSame('รหัส [รหัส] ครับ', $this->scrubber->scrub('รหัส FakeCode42 ครับ'));
        // Thai-only value right after the keyword is kept (indistinguishable from prose)
        $this->assertSame('รหัส อ้างอิงครับ', $this->scrubber->scrub('รหัส อ้างอิงครับ'));
        // รหัสส่วนลด is one word — no space directly after รหัส, not masked here
        $this->assertSame('รหัสส่วนลดล่าสุดครับ', $this->scrubber->scrub('รหัสส่วนลดล่าสุดครับ'));
    }

    #[Test]
    public function test_masks_account_data_rows_with_two_separators_and_keyword(): void
    {
        $text = "บัญชีที่ส่งมาครับ\nuid|รหัส|2fa\nFAKE-USER-1|FAKE-PASS-2|FAKE-3FA-3\nขอบคุณครับ";
        $expected = "บัญชีที่ส่งมาครับ\n[รหัส]\n[รหัส]\nขอบคุณครับ";

        $this->assertSame($expected, $this->scrubber->scrub($text));
    }

    #[Test]
    public function test_account_row_rule_needs_a_keyword_so_bot_prose_with_pipes_survives(): void
    {
        $text = 'สวัสดีครับพี่|||ตอนนี้มี Nolimit Personal ครับ|||สนใจไหมครับ';

        $this->assertSame($text, $this->scrubber->scrub($text));
    }

    #[Test]
    public function test_masks_base32_secret_keys_contiguous_and_spaced(): void
    {
        $this->assertSame('2FA: [รหัสลับ] เก็บดีๆ นะครับ', $this->scrubber->scrub('2FA: JBSWY3DPEHPK3PXPJBSWY3DP เก็บดีๆ นะครับ'));
        $this->assertSame('[รหัสลับ]', $this->scrubber->scrub('JBSW Y3DP EHPK 3PXP JBSW'));
        // short normal caps word is untouched
        $this->assertSame('สนใจ BM กับ G3D ครับ', $this->scrubber->scrub('สนใจ BM กับ G3D ครับ'));
    }

    #[Test]
    public function test_masks_verification_codes_6_to_8_digits_after_keywords(): void
    {
        $this->assertSame('โค้ด [รหัส] ครับ', $this->scrubber->scrub('โค้ด 123456 ครับ'));
        $this->assertSame('OTP: [รหัส]', $this->scrubber->scrub('OTP:654321'));
        $this->assertSame('code = [รหัส] ครับ', $this->scrubber->scrub('code = 12345678 ครับ'));
        $this->assertSame('2fa [รหัส] ครับ', $this->scrubber->scrub('2fa 765432 ครับ'));
    }

    #[Test]
    public function test_nukes_whole_line_when_รหัส_or_บัญชี_keyword_meets_a_5_plus_digit_run(): void
    {
        $this->assertSame('[รหัส]', $this->scrubber->scrub('บัญชี 12345'));
        $this->assertSame('[รหัส]', $this->scrubber->scrub('รหัสบัญชี 123456789 ครับ'));
        $this->assertSame("[รหัส]\n[รหัส]", $this->scrubber->scrub('บัญชี 12345 ของผมครับ
รหัส 98765 ครับ'));
    }

    #[Test]
    public function test_keyword_digit_line_rule_leaves_prices_and_quantities_alone(): void
    {
        $clean = [
            'ราคา 1,600 บาทครับ',
            'เติม 16000 ได้ครับไหม', // no รหัส/บัญชี keyword
            'เอา 40 อันครับ',
            'G3D 50 บาท 50 ตัว',
        ];

        foreach ($clean as $text) {
            $this->assertSame($text, $this->scrubber->scrub($text));
        }
    }

    #[Test]
    public function test_masks_cookies(): void
    {
        $this->assertSame('c_user=[คุกกี้] ครับ', $this->scrubber->scrub('c_user=FAKECOOKIE123 ครับ'));
        $this->assertSame('xs=[คุกกี้] datr=[คุกกี้]', $this->scrubber->scrub('xs=FAKE-XS-1; datr=FAKE-DATR-22'));
        $this->assertSame('fr=[คุกกี้]', $this->scrubber->scrub('fr=FAKE-FR-33'));
    }

    #[Test]
    public function test_masks_tokens(): void
    {
        $this->assertSame('[โทเคน] ใช้ยังไงครับ', $this->scrubber->scrub('EAAFAKE12345678 ใช้ยังไงครับ'));
        $this->assertSame('[โทเคน]', $this->scrubber->scrub('eyJFAKEFAKEFAKEFAKEFAKE'));
        $this->assertSame('[โทเคน] ครับ', $this->scrubber->scrub('FAKE-TOKEN-AB12CD34EF56GH78 ครับ'));
    }

    #[Test]
    public function test_masks_card_numbers_but_not_phones_or_prices(): void
    {
        $this->assertSame('บัตรผม [เลขบัตร] ครับ', $this->scrubber->scrub('บัตรผม 1234 5678 9012 3456 ครับ'));
        $this->assertSame('[เลขบัตร] ครับ', $this->scrubber->scrub('1234-5678-9012 ครับ'));
        // Thai phone formats survive
        $clean = ['081-234-5678', '0812345678', '02-1234567', '081 234 5678'];

        foreach ($clean as $text) {
            $this->assertSame($text, $this->scrubber->scrub($text));
        }
    }

    #[Test]
    public function test_scrubbed_output_keeps_a_structural_pasted_account_signal(): void
    {
        // The QUESTION counts "pasted account credentials/IDs/card numbers" as a
        // post-sale signal. Structural guarantee: the line is REPLACED by a
        // non-empty placeholder containing the keyword, the line count is kept,
        // and nothing is deleted — Luna still sees "something was pasted".
        $text = "ล็อกไม่ได้ครับ\nuid|รหัส|2fa\nFAKE-USER-1|FAKE-PASS-2|FAKE-3FA-3\nช่วยดูหน่อยครับ";
        $result = $this->scrubber->scrub($text);

        $this->assertSame(4, substr_count($result, "\n") + 1);
        $this->assertStringContainsString('[รหัส]', $result);
        $this->assertStringContainsString('ล็อกไม่ได้ครับ', $result);
        $this->assertStringContainsString('ช่วยดูหน่อยครับ', $result);
        $this->assertStringNotContainsString('FAKE-USER-1', $result);
    }

    #[Test]
    public function test_long_and_pathological_input_terminates_quickly(): void
    {
        $cases = [
            str_repeat('A', 50000),
            str_repeat('รหัส: ', 10000),
            str_repeat('password=password=', 2000),
            str_repeat('uid|', 5000),
            str_repeat('0123456789', 5000),
            "รหัสผ่าน: ".str_repeat('FAKE', 100),
            str_repeat("บัญชี 12345\n", 3000),
        ];

        foreach ($cases as $text) {
            $result = $this->scrubber->scrub($text);

            $this->assertIsString($result);
            $this->assertStringNotContainsString('FAKE', $result);
        }
    }

    #[Test]
    public function test_truncation_length_still_masks_value_inside_first_300_chars(): void
    {
        // production truncates to 300 chars AFTER scrubbing; the value here sits
        // inside the window and must be gone, the keyword kept
        $text = 'รหัสผ่าน: FAKE-PASS-123 ครับ '.str_repeat('ค', 300);
        $result = mb_substr($this->scrubber->scrub($text), 0, 300);

        $this->assertStringNotContainsString('FAKE-PASS-123', $result);
        $this->assertStringContainsString('รหัสผ่าน: [รหัส]', $result);
    }
}
