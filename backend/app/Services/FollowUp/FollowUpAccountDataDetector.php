<?php

namespace App\Services\FollowUp;

use Normalizer;

/**
 * ตัวจับ "ข้อมูลบัญชี/credential" ในข้อความ — ล้วนๆ pure ไม่มี I/O ไม่มี network (D15)
 *
 * การ์ด B2b: projector ใช้ติดธง sensitive_account_data บน read model;
 * การ์ด B3a: redactor ใช้ findAccountData() เพื่อแทนที่ span ที่จับได้ และใช้
 * normalize() เพื่อให้ข้อความกับ offset อยู่บน string ฉบับเดียวกัน (plan §3.4)
 *
 * ก่อนจับทุกครั้ง (D20): ตัดอักขระควบคุม/มองไม่เห็นก่อน (\p{Cc} ยกเว้น \r \n, \p{Cf},
 * U+034F — INVISIBLE_CHARS ออกแบบเป็นคลาส รอบ 1 ข้อ 2 ของรีวิว) แล้วแปลงเป็น NFKC —
 * fullwidth pipe/label/เลข/เครื่องหมาย จึงถูกจับเท่าตัว ASCII และ label ที่ถูกคั่นด้วย
 * อักขระมองไม่เห็น (รวม soft hyphen, U+061C ฯลฯ) ก็ยังถูกจับ
 * รีวิวรอบ 4 ข้อ 1: ลำดับ "ตัดก่อน NFKC" ทำให้ normalize เป็น fixed point (ตัดหลัง NFKC
 * เปิดลำดับ base+mark ที่ยังไม่ normalize ทิ้งไว้ แล้ว normalize รอบสอง compose ต่อ;
 * สแกน code point ครบทุกตัวยืนยันว่า NFKC ไม่เคยแปลงอักขระที่มองเห็นไปเป็น INVISIBLE_CHARS
 * จึงพอตัดก่อนรอบเดียว)
 * รีวิวรอบ 3 (D22): หลัง NFKC คืนสระอำ U+0E4D U+0E32 → U+0E33 (และ Lao U+0ECD U+0EB2
 * → U+0EB3) ข้อความไทยที่ไม่ลับจึงคงการสะกดตรงตามที่พิมพ์
 * อ่านข้อความไม่ได้ (invalid UTF-8 หรือ preg error ใดๆ) = ถือว่ามีข้อมูลบัญชี (fail closed)
 *
 * จับ (plan §3.4):
 * - บรรทัด credential คั่น pipe — รวม pipe เว้นวรรค และ fullwidth "｜" (ผ่าน NFKC)
 * - label-values: password / pass / pwd / รหัสผ่าน / OTP / 2FA / cookie / token /
 *   secret / authorization ตามด้วย : หรือ = แล้วมีค่า
 * - Facebook cookies: c_user และ xs
 * - JWT (สามท่อน eyJ....xxx.yyy) และ Bearer tokens
 * - URL แฝง user:password ก่อน @
 * - คู่แบบ combolist อีเมล:value ติดกันโดยไม่เว้นวรรค
 *
 * ไม่จับ: อีเมลล้วน, เบอร์โทรล้วน, ราคา, เลขออเดอร์, ประโยคที่แค่ "พูดถึง" รหัสผ่าน
 */
class FollowUpAccountDataDetector
{
    /** label ที่ตามด้วยค่า ([:=]) ถือว่าเป็น credential — ASCII + ไทย "รหัสผ่าน" */
    private const LABELS = 'password|passwd|pass|pwd|token|secret|authorization|cookie|otp|2fa';

    /**
     * span สำหรับการแทนที่: label + ค่า ถึงท้ายบรรทัด (ต้องมีอย่างน้อย 1 ตัวอักษรหลังคั่น —
     * ทริกเกอร์เดิมของ LABEL_VALUE จึงไม่เปลี่ยน แต่ค่าทั้งค่าถูกแทนที่ ไม่เหลือเศษ)
     */
    private const LABEL_VALUE_SPAN = '/(?:\b(?:'.self::LABELS.')\b|รหัสผ่าน)\s*[=:]\s*\S[^\r\n]*/iu';

    /** segment ที่เป็น label ล้วน ("otp|482913") ก็นับเป็นบรรทัด credential */
    private const BARE_LABEL = '/^(?:'.self::LABELS.'|รหัสผ่าน)$/iu';

    private const JWT = '/eyJ[a-z0-9_-]{10,}\.eyJ[a-z0-9_-]{10,}\.[a-z0-9_-]{10,}/i';

    private const BEARER = '/bearer\s+[a-z0-9._~+\/=-]{16,}/i';

    private const URL_USERINFO = '#[a-z][a-z0-9+.-]*://[^\s/@]+:[^\s/@]+@#i';

    private const FB_COOKIE_SPAN = '/(?:\bc_user\s*[=:]\s*|\bxs\s*[=:;]\s*)\S+/i';

    /** คู่แบบ combolist: อีเมล ตามด้วย : หรือ ; ทันที (ไม่เว้นวรรค) แล้วมีค่า */
    private const EMAIL_PAIR_SPAN = '#[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}[:;](?!\s)\S+#i';

    private const EMAIL_SEGMENT = '/^[^@\s]+@[^@\s]+\.[^@\s]+$/';

    /** ชิ้น password-like: ตัวอักษร+ตัวเลข ≥6 ตัว ไม่มีเว้นวรรค (ในบรรทัด pipe เท่านั้น) —
     * \p{M} ครอบสระบน/วรรณยุกต์ไทยที่แทรกในค่า เช่น น้ำใส2024 (รีวิวรอบ 4 ข้อ 2) */
    private const PASSWORD_LIKE = '/^(?=.*\p{L})(?=.*\d)[\p{L}\p{M}\p{N}@._-]{6,}$/u';

    /**
     * อักขระควบคุม/มองไม่เห็นที่ตัดทิ้งก่อนจับ (line break \r \n คงเดิม) —
     * รีวิวรอบ 1 ข้อ 2: ตัดด้วย "คลาส" ไม่ใช่ลิสต์ตัวอักษร เพื่อไม่มี format character หลุด
     * \p{Cc} ยกเว้น \r \n + \p{Cf} (ครอบ soft hyphen U+00AD, U+061C, U+180E,
     * zero-width/bidi 200B-200F, 202A-202E, 2060-206F, FEFF, tag chars) + U+034F
     * (combining grapheme joiner) + tag characters U+E0001-E007F + noncharacters FFFE/FFFF.
     * คอนสแตนต์เดียวแชร์กับ FollowUpRedactor::assertSafeOutbound (โค้ด control_character)
     */
    public const INVISIBLE_CHARS = '/(?![\r\n])[\p{Cc}\p{Cf}\x{034F}\x{E0001}-\x{E007F}\x{FFFE}\x{FFFF}]/u';

    /**
     * ข้อความฉบับ normalized (ตัด zero-width/control/bidi ก่อน + NFKC + คืนสระอำไทย/ลาว,
     * line break คงเดิม)
     * คืน null เมื่ออ่านไม่ได้อย่างปลอดภัย: invalid UTF-8 หรือ normalization/preg error (D20)
     */
    public static function normalize(string $text): ?string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return null;
        }

        // รีวิวรอบ 4 ข้อ 1 — ตัดอักขระมองไม่เห็น "ก่อน" NFKC เพื่อให้ normalize เป็น fixed
        // point: ถ้าตัดหลัง NFKC การตัดอักขระที่คั่นฐานออกจากวรรณยุกต์ (เช่น e+U+200B+U+0301)
        // เปิดลำดับ base+mark ที่ยังไม่ normalize ทิ้งไว้ แล้ว redact รอบสอง compose ต่อ
        // ทำให้ redact(redact(x)) != redact(x) และ containsAccountData(redact(x)) เป็น true
        // (สแกน code point ครบทุกตัว: NFKC ไม่เคยแปลงอักขระที่มองเห็นไปเป็นอักขระใน
        // INVISIBLE_CHARS จึงพอตัดก่อน NFKC รอบเดียว)
        $cleaned = preg_replace(self::INVISIBLE_CHARS, '', $text);
        if ($cleaned === null || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        $normalized = Normalizer::normalize($cleaned, Normalizer::FORM_KC);
        if ($normalized === false) {
            return null;
        }

        // D22 (รีวิวรอบ 3): NFKC เป็น compatibility mapping ที่แยกสระอำ U+0E33 ออกเป็น
        // U+0E4D (nikhahit) + U+0E32 (สระอา) — และคู่ Lao U+0ECD U+0EB2 — ทำให้ข้อความไทย
        // ที่ไม่ลับออกจาก redact/snapshot เปลี่ยนการสะกด คืนสภาพเดิมหลัง NFKC เพื่อให้
        // ข้อความที่พิมพ์ด้วยสระอำออกมาตรงตามเดิมทุกไบต์ และ pattern ทั้งหมดยังทำงานบน
        // ฉบับ normalized ชุดเดียวกัน (recompose ก็ fixed point: NFKC รอบถัดไปแยกออก
        // แล้ว str_replace คืนให้เหมือนเดิมทุกครั้ง)
        return str_replace(
            ["\u{0E4D}\u{0E32}", "\u{0ECD}\u{0EB2}"],
            ["\u{0E33}", "\u{0EB3}"],
            $normalized,
        );
    }

    public static function containsAccountData(string $text): bool
    {
        $spans = self::findAccountData($text);

        // null = อ่านข้อความไม่ได้อย่างปลอดภัย (invalid UTF-8 / preg error) → fail closed (D20)
        return $spans === null || $spans !== [];
    }

    /**
     * span ของข้อมูลบัญชีบนข้อความ "ฉบับ normalized" (byte offset, byte length) เรียงตาม
     * ตำแหน่ง ตัด span ที่ซ้อนใน span ก่อนหน้า (บรรทัด pipe กลืน match ย่อยภายใน) —
     * ผู้เรียก (redactor) แทนที่ทีละ span ด้วย marker แล้วไม่ซ้ำ
     * kind: credential_line | label_value | jwt | bearer | url_userinfo | fb_cookie | email_pair
     * คืน null เมื่ออ่านข้อความไม่ได้อย่างปลอดภัย — caller ต้อง fail closed (D20)
     *
     * @return list<array{start: int, length: int, kind: string}>|null
     */
    public static function findAccountData(string $text): ?array
    {
        $normalized = self::normalize($text);
        if ($normalized === null) {
            return null;
        }

        $spans = [];
        foreach ([
            'label_value' => self::LABEL_VALUE_SPAN,
            'jwt' => self::JWT,
            'bearer' => self::BEARER,
            'url_userinfo' => self::URL_USERINFO,
            'fb_cookie' => self::FB_COOKIE_SPAN,
            'email_pair' => self::EMAIL_PAIR_SPAN,
        ] as $kind => $pattern) {
            $matches = self::matchAllSpans($pattern, $normalized);
            if ($matches === null) {
                return null;
            }
            foreach ($matches as [$match, $offset]) {
                $spans[] = ['start' => $offset, 'length' => strlen($match), 'kind' => $kind];
            }
        }

        $lineSpans = self::credentialLineSpans($normalized);
        if ($lineSpans === null) {
            return null;
        }
        foreach ($lineSpans as $span) {
            $spans[] = $span;
        }

        if ($spans === []) {
            return [];
        }

        usort($spans, function (array $a, array $b): int {
            return $a['start'] <=> $b['start'] ?: $b['length'] <=> $a['length'];
        });

        $merged = [];
        $coveredUntil = -1;
        foreach ($spans as $span) {
            if ($span['start'] < $coveredUntil) {
                continue;
            }
            $merged[] = $span;
            $coveredUntil = $span['start'] + $span['length'];
        }

        return $merged;
    }

    /**
     * @return list<array{string, int}>|null null เมื่อ preg error (fail closed)
     */
    private static function matchAllSpans(string $pattern, string $subject): ?array
    {
        $matched = preg_match_all($pattern, $subject, $matches, PREG_OFFSET_CAPTURE);
        if ($matched === false || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        $spans = [];
        foreach ($matches[0] as [$match, $offset]) {
            $spans[] = [$match, $offset];
        }

        return $spans;
    }

    /**
     * span ของทุกบรรทัดที่เป็น credential line (ทั้งบรรทัด เว้น line break)
     *
     * @return list<array{start: int, length: int, kind: string}>|null null เมื่อ preg error
     */
    private static function credentialLineSpans(string $normalized): ?array
    {
        $pieces = preg_split('/\r\n|\r|\n/', $normalized, -1, PREG_SPLIT_OFFSET_CAPTURE);
        if ($pieces === false || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        $spans = [];
        foreach ($pieces as [$line, $offset]) {
            $isCredential = self::lineIsCredential($line);
            if ($isCredential === null) {
                return null;
            }
            if ($isCredential) {
                $spans[] = ['start' => $offset, 'length' => strlen($line), 'kind' => 'credential_line'];
            }
        }

        return $spans;
    }

    /**
     * บรรทัด credential คั่น pipe: ≥2 ช่วง และช่วงใดช่วงหนึ่งเป็นอีเมล,
     * label ล้วน, label ตามด้วยค่า หรือ password-like — pipe ปกติ/เว้นวรรค/fullwidth เท่ากัน
     * คืน null เมื่อ preg error (fail closed)
     */
    private static function lineIsCredential(string $line): ?bool
    {
        if (! str_contains($line, '|')) {
            return false;
        }

        $segments = array_values(array_filter(
            array_map('trim', explode('|', $line)),
            fn (string $segment): bool => $segment !== ''
        ));

        if (count($segments) < 2) {
            return false;
        }

        foreach ($segments as $segment) {
            foreach ([self::EMAIL_SEGMENT, self::BARE_LABEL, self::PASSWORD_LIKE, self::LABEL_VALUE_SPAN] as $pattern) {
                $matched = self::pregMatch($pattern, $segment);
                if ($matched === null) {
                    return null;
                }
                if ($matched) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * preg_match แบบแยก "ไม่ match" ออกจาก "preg error"
     *
     * @return ?bool null เมื่อ preg error (fail closed)
     */
    private static function pregMatch(string $pattern, string $subject): ?bool
    {
        $result = preg_match($pattern, $subject);
        if ($result === false || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        return $result === 1;
    }
}
