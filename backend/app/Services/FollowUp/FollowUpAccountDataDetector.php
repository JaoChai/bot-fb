<?php

namespace App\Services\FollowUp;

/**
 * ตัวจับ "ข้อมูลบัญชี/credential" ในข้อความ — ล้วนๆ pure ไม่มี I/O ไม่มี network (D15)
 *
 * การ์ด B2b: projector ใช้ติดธง sensitive_account_data บน read model;
 * การ์ด redactor (B3) จะ reuse class เดียวกันตอนตัดข้อความก่อนส่งให้ model
 *
 * จับ (plan §3.4):
 * - บรรทัด credential คั่น pipe — รวม pipe เว้นวรรค ("a | b") และ fullwidth "｜"
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

    private const LABEL_VALUE = '/(?:\b(?:'.self::LABELS.')\b|รหัสผ่าน)\s*[=:]\s*\S/iu';

    /** segment ที่เป็น label ล้วน ("otp|482913") ก็นับเป็นบรรทัด credential */
    private const BARE_LABEL = '/^(?:'.self::LABELS.'|รหัสผ่าน)$/iu';

    private const JWT = '/eyJ[a-z0-9_-]{10,}\.eyJ[a-z0-9_-]{10,}\.[a-z0-9_-]{10,}/i';

    private const BEARER = '/bearer\s+[a-z0-9._~+\/=-]{16,}/i';

    private const URL_USERINFO = '#[a-z][a-z0-9+.-]*://[^\s/@]+:[^\s/@]+@#i';

    private const FB_COOKIE = '/(?:\bc_user\s*[=:]\s*\S|\bxs\s*[=:;]\s*\S)/i';

    /** คู่แบบ combolist: อีเมล ตามด้วย : หรือ ; ทันที (ไม่เว้นวรรค) แล้วมีค่า */
    private const EMAIL_PAIR = '#[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}[:;](?!\s)\S#i';

    private const EMAIL_SEGMENT = '/^[^@\s]+@[^@\s]+\.[^@\s]+$/';

    /** ชิ้น password-like: ตัวอักษร+ตัวเลข ≥6 ตัว ไม่มีเว้นวรรค (ในบรรทัด pipe เท่านั้น) */
    private const PASSWORD_LIKE = '/^(?=.*\p{L})(?=.*\d)[\p{L}\p{N}@._-]{6,}$/u';

    public static function containsAccountData(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        $normalized = str_replace(["\u{FEFF}", "\u{200B}", "\u{200C}", "\u{200D}"], '', $text);

        foreach ([self::LABEL_VALUE, self::JWT, self::BEARER, self::URL_USERINFO, self::FB_COOKIE, self::EMAIL_PAIR] as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return self::hasCredentialPipeLine(str_replace("\u{FF5C}", '|', $normalized));
    }

    /**
     * บรรทัด credential คั่น pipe: ≥2 ช่วง และช่วงใดช่วงหนึ่งเป็นอีเมล,
     * label ล้วน, label ตามด้วยค่า หรือ password-like — pipe ปกติ/เว้นวรรค/fullwidth เท่ากัน
     */
    private static function hasCredentialPipeLine(string $normalized): bool
    {
        foreach (preg_split('/\r\n|\r|\n/', $normalized) as $line) {
            if (! str_contains($line, '|')) {
                continue;
            }

            $segments = array_values(array_filter(
                array_map('trim', explode('|', $line)),
                fn (string $segment): bool => $segment !== ''
            ));

            if (count($segments) < 2) {
                continue;
            }

            foreach ($segments as $segment) {
                if (preg_match(self::EMAIL_SEGMENT, $segment) === 1
                    || preg_match(self::BARE_LABEL, $segment) === 1
                    || preg_match(self::PASSWORD_LIKE, $segment) === 1
                    || preg_match(self::LABEL_VALUE, $segment) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
