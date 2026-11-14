<?php

namespace App\Support;

use App\Services\FollowUp\FollowUpAccountDataDetector;
use InvalidArgumentException;

/**
 * ตัวปิดข้อมูลลับก่อนส่งแชตให้ AI (plan §3.4, การ์ด B3a) — pure text:
 * ไม่มี database ไม่มี HTTP ไม่มี config ไม่มี log ไม่มี model call (D18)
 *
 * - redact(): NFKC + ตัด zero-width/control/bidi (ผ่าน detector::normalize ที่เดียว D15)
 *   แล้วแทนที่ทุกชิ้นของลับด้วย marker รูปแบบคงที่ [redacted:<kind>] —
 *   pattern ฝั่ง detector อยู่กับ FollowUpAccountDataDetector; อีเมล/เลข ≥10 หลัก/
 *   ค่าใน query+fragment ของ URL อยู่ที่นี่ (ไม่ซ้ำกับ detector)
 * - containsAccountData(): delegate ไป detector อย่างเดียว
 * - assertSafeOutbound(): สำหรับข้อความที่จะส่ง/แสดง "ตามที่เขียน" — โยนโค้ดล้วน ไม่แตะข้อความ
 * - snapshot(): บริบทแชตสำหรับ model — มี account data ใน message ใด = null (หยุดก่อน model)
 *
 * properties ที่ต้องคงที่ทุกอินพุต: containsAccountData(redact(x)) = false,
 * redact(redact(x)) = redact(x), และ marker ไม่ match pattern ใด
 */
class FollowUpRedactor
{
    /** จำนวนบรรทัด (ล่าสุด) ที่ snapshot คืนสูงสุด */
    public const MAX_LINES = 10;

    /** ตัดข้อความแต่ละข้อความหลัง redact ที่จำนวน code points นี้ */
    public const MAX_MESSAGE_CODEPOINTS = 1000;

    /** งบ code points รวมของ snapshot ทั้งก้อน — เกินเมื่อไหร่ทิ้งบรรทัดเก่าสุด */
    public const MAX_TOTAL_CODEPOINTS = 8000;

    private const UNREADABLE_MARKER = '[redacted:unreadable]';

    /** อีเมลล้วน — detector ยืนยันแล้วว่าไม่ใช่ account data แต่ต้องถูกตัดก่อนออกนอกระบบ */
    private const EMAIL = '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i';

    /** เลขติดกัน ≥10 หลัก — เลขไทย ๐-๙ นับด้วย (NFKC ไม่แปลง) */
    private const DIGIT_RUN = '/[0-9\x{0E50}-\x{0E59}]{10,}/u';

    /** กลุ่มเลขคั่นช่องว่าง/จุด/ขีด แต่ละกลุ่ม ≥2 หลัก (นับยอดรวมหลัง callback) */
    private const DIGIT_GROUPS = '/[0-9\x{0E50}-\x{0E59}]{2,}(?:[ .\-][0-9\x{0E50}-\x{0E59}]{2,})+/u';

    /**
     * ค่าหลัง = ใน query และ fragment ของ URL: ต้องมีตัวอักษร URL นำหน้า ?/# เพื่อไม่โดน
     * ประโยคไทย/แฮชแท็ก และ name ยาวไม่เกิน 64; ค่าจบที่ช่องว่าง & หรือ #
     */
    private const URL_PARAM_VALUE = '/(?<=[a-z0-9\/._~%\-])([?&][^\s&#=]{0,64}=|#[^\s&#=]{0,64}=)([^\s&#]+)/iu';

    /** fragment ไม่มี = เช่น #top — เนื้อหาเป็น ASCII charset ของ URL fragment เท่านั้น */
    private const URL_BARE_FRAGMENT = '/(?<=[a-z0-9\/._~%\-])#[^\s#&]*/iu';

    /** control / zero-width / bidi ที่ห้ามออกของจริงตามที่เขียน (line break ผ่านได้) */
    private const OUTBOUND_INVISIBLE = '/[\x{00}-\x{08}\x{0B}\x{0C}\x{0E}-\x{1F}\x{7F}-\x{9F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FFFE}\x{FFFF}\x{FEFF}]/u';

    /** ตัวอักษรเลข: ASCII + เลขไทย */
    private const DIGITS = '/[^0-9\x{0E50}-\x{0E59}]/u';

    public static function redact(string $text): string
    {
        $spans = FollowUpAccountDataDetector::findAccountData($text);
        if ($spans === null) {
            return self::UNREADABLE_MARKER;
        }

        $normalized = FollowUpAccountDataDetector::normalize($text);
        // findAccountData คืน span ได้แปลว่าอ่านได้ → normalize ไม่เป็น null ณ จุดนี้
        $text = self::spliceAccountData((string) $normalized, $spans);

        $text = self::replaceUrlParamValues($text);
        if ($text === null) {
            return self::UNREADABLE_MARKER;
        }

        $text = self::replaceUrlBareFragments($text);
        if ($text === null) {
            return self::UNREADABLE_MARKER;
        }

        foreach ([
            self::EMAIL => '[redacted:email]',
            self::DIGIT_RUN => '[redacted:digits]',
        ] as $pattern => $marker) {
            $text = self::replaceMatches($pattern, $marker, $text);
            if ($text === null) {
                return self::UNREADABLE_MARKER;
            }
        }

        return self::replaceDigitGroups($text) ?? self::UNREADABLE_MARKER;
    }

    public static function containsAccountData(string $text): bool
    {
        // D15: pattern อยู่กับ detector ที่เดียว — คลาสนี้ไม่ถือ pattern ของ account data เอง
        return FollowUpAccountDataDetector::containsAccountData($text);
    }

    /**
     * ตรวจข้อความที่จะส่งหรือแสดง "ตามที่เขียน" (แก้ไขโดยเจ้าของ, draft สุดท้าย) —
     * ไม่แก้ข้อความ และ exception ไม่พาเนื้อความออกมา: message เป็นโค้ดอย่างเดียว
     *
     * @throws InvalidArgumentException โค้ดใดโค้ดหนึ่ง: account_data | redaction_marker | control_character | unreadable
     */
    public static function assertSafeOutbound(string $text): void
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('unreadable');
        }

        $hasInvisible = self::pregMatch(self::OUTBOUND_INVISIBLE, $text);
        if ($hasInvisible === null) {
            throw new InvalidArgumentException('unreadable');
        }
        if ($hasInvisible) {
            throw new InvalidArgumentException('control_character');
        }

        if (str_contains($text, '[redacted:')) {
            throw new InvalidArgumentException('redaction_marker');
        }

        if (FollowUpAccountDataDetector::containsAccountData($text)) {
            throw new InvalidArgumentException('account_data');
        }
    }

    /**
     * บริบทแชตแบบ redacted สำหรับส่งให้ model (plan §3.4 + บรรทัด C:/B:/A:)
     * $messages เรียงเก่าสุดก่อน: ['sender' => string, 'type' => string, 'content' => ?string]
     * คืน null เมื่อ message ใดมี account data — caller ต้องหยุดก่อนเรียก model
     *
     * @param  list<array{sender: string, type: string, content: ?string}>  $messages
     * @return list<string>|null
     */
    public static function snapshot(array $messages): ?array
    {
        foreach ($messages as $message) {
            $content = $message['content'] ?? null;
            if (is_string($content) && FollowUpAccountDataDetector::containsAccountData($content)) {
                return null;
            }
        }

        $lines = [];
        foreach ($messages as $message) {
            $line = self::lineFor($message);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        if (count($lines) > self::MAX_LINES) {
            $lines = array_slice($lines, -self::MAX_LINES);
        }

        while ($lines !== [] && self::totalCodePoints($lines) >= self::MAX_TOTAL_CODEPOINTS) {
            array_shift($lines);
        }

        return $lines;
    }

    /**
     * แทนที่ span ของ detector ด้วย marker — span เรียงแล้วและไม่ซ้อนกัน
     *
     * @param  list<array{start: int, length: int, kind: string}>  $spans
     */
    private static function spliceAccountData(string $text, array $spans): string
    {
        $out = '';
        $cursor = 0;
        foreach ($spans as $span) {
            $out .= substr($text, $cursor, $span['start'] - $cursor).'[redacted:'.$span['kind'].']';
            $cursor = $span['start'] + $span['length'];
        }

        return $out.substr($text, $cursor);
    }

    /**
     * ค่าหลัง = ใน query/fragment — ค่าที่เป็น marker อยู่แล้ว (เช่น jwt ที่ detector ตัดไป)
     * ไม่ทับซ้ำ; คืน null เมื่อ preg error
     */
    private static function replaceUrlParamValues(string $text): ?string
    {
        $replaced = preg_replace_callback(self::URL_PARAM_VALUE, function (array $match): string {
            [$full, $prefix, $value] = $match;

            if (str_starts_with($value, '[redacted:')) {
                return $full;
            }

            return $prefix.'[redacted:'.(str_starts_with($prefix, '#') ? 'url_fragment' : 'url_query').']';
        }, $text);

        if ($replaced === null || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        return $replaced;
    }

    /**
     * fragment ไม่มี = (#top, #section) — เนื้อหาเป็น charset URL เท่านั้นถึงจับ,
     * มี = ให้กฎค่าหลัง = จัดการ; คืน null เมื่อ preg error
     */
    private static function replaceUrlBareFragments(string $text): ?string
    {
        $replaced = preg_replace_callback(self::URL_BARE_FRAGMENT, function (array $match): string {
            $content = substr($match[0], 1);

            if ($content === '' || str_contains($content, '=')) {
                return $match[0];
            }

            return '#[redacted:url_fragment]';
        }, $text);

        if ($replaced === null || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        return $replaced;
    }

    /**
     * กลุ่มเลขคั่น [ .\-] — รวมกัน ≥10 หลักถึงจับ (นับหลักใน callback ไม่ใช้ preg ซ้อน);
     * คืน null เมื่อ preg error
     */
    private static function replaceDigitGroups(string $text): ?string
    {
        $replaced = preg_replace_callback(self::DIGIT_GROUPS, function (array $match): string {
            $digitCount = 0;
            foreach (mb_str_split($match[0], 1, 'UTF-8') as $char) {
                if (ctype_digit($char) || ($char >= '๐' && $char <= '๙')) {
                    $digitCount++;
                }
            }

            return $digitCount >= 10 ? '[redacted:digit_groups]' : $match[0];
        }, $text);

        if ($replaced === null || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        return $replaced;
    }

    /**
     * preg_replace แบบตรวจ preg error — คืน null เมื่อ error (caller ต้อง fail closed)
     */
    private static function replaceMatches(string $pattern, string $replacement, string $subject): ?string
    {
        $replaced = preg_replace($pattern, $replacement, $subject);
        if ($replaced === null || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        return $replaced;
    }

    /**
     * @return ?bool null เมื่อ preg error
     */
    private static function pregMatch(string $pattern, string $subject): ?bool
    {
        $result = preg_match($pattern, $subject);
        if ($result === false || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        return $result === 1;
    }

    /**
     * @param  array{sender: mixed, type: mixed, content: mixed}  $message
     */
    private static function lineFor(array $message): ?string
    {
        $prefix = match ((string) ($message['sender'] ?? '')) {
            'user' => 'C',
            'bot' => 'B',
            'agent' => 'A',
            default => null,
        };

        if ($prefix === null) {
            return null; // unknown sender → ทิ้ง
        }

        $type = strtolower((string) ($message['type'] ?? ''));
        if ($type !== 'text') {
            // ไม่ดึง content ของ non-text — ใส่ type เท่านั้น (เหลือ a-z_)
            $safeType = (string) preg_replace('/[^a-z_]/', '', $type);

            return $prefix.': [non-text:'.$safeType.']';
        }

        $content = $message['content'] ?? null;
        if ($content === null) {
            return $prefix.':';
        }

        // redact ก่อนเสมอ แล้วจึงตัดที่ 1000 code points
        $redacted = self::redact((string) $content);

        return $prefix.': '.mb_substr($redacted, 0, self::MAX_MESSAGE_CODEPOINTS, 'UTF-8');
    }

    /**
     * @param  list<string>  $lines
     */
    private static function totalCodePoints(array $lines): int
    {
        return array_sum(array_map(fn (string $line): int => mb_strlen($line, 'UTF-8'), $lines));
    }
}
