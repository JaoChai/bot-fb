<?php

namespace App\Services\SmartAggregation;

/**
 * Thai language pattern detection utilities.
 * Provides methods to detect Thai end particles, greetings, and continuation markers.
 */
class ThaiLanguagePatterns
{
    /**
     * End particles - indicates message is complete (score 0.9)
     */
    public const END_PARTICLES = [
        // Polite endings
        'ครับ', 'คะ', 'ค่ะ',
        // Casual endings
        'นะ', 'น้า', 'นะคะ', 'นะครับ',
        // Informal endings
        'จ้า', 'จ้ะ', 'จ๊ะ', 'จ๋า', 'ฮะ', 'ฮ่ะ', 'ฮับ', 'ค้าบ', 'คับ', 'ค่า', 'คร้าบ', 'คร๊าบ', 'อ่ะ',
        // Question endings
        'หรอ', 'รึเปล่า', 'มั้ย', 'ไหม', 'ป่าว', 'เหรอ', 'หรือเปล่า',
    ];

    /**
     * Greetings - can trigger immediate response
     */
    public const GREETINGS = [
        'สวัสดี', 'หวัดดี', 'ดี', 'ดีครับ', 'ดีค่ะ', 'ดีจ้า',
        'hello', 'hi', 'hey',
        'สวัสดีครับ', 'สวัสดีค่ะ', 'สวัสดีจ้า',
    ];

    /**
     * Continuation markers - indicates more messages likely coming (score 0.3)
     */
    public const CONTINUATION_MARKERS = [
        'แล้วก็', 'แล้ว', 'ด้วย', 'กับ', 'และ', 'หรือ',
        'แล้วยัง', 'กับ...', '...',
    ];

    /**
     * Question starters (without end particles)
     */
    public const QUESTION_STARTERS = [
        'อะไร', 'ยังไง', 'ทำไม', 'เมื่อไหร่', 'ที่ไหน', 'ใคร',
        'เท่าไหร่', 'กี่', 'มี', 'ได้', 'มั้ย', 'ไหม',
    ];

    /**
     * Check if text contains Thai characters.
     */
    public static function isThaiText(string $content): bool
    {
        return (bool) preg_match('/[\x{0E00}-\x{0E7F}]/u', $content);
    }

    /**
     * Detect if content ends with a Thai particle.
     */
    public static function detectEndParticle(string $content): ?string
    {
        $content = trim($content);

        foreach (self::END_PARTICLES as $particle) {
            if (str_ends_with($content, $particle)) {
                return $particle;
            }
        }

        return null;
    }

    /**
     * Check if content is a greeting.
     */
    public static function isGreeting(string $content): bool
    {
        $content = mb_strtolower(trim($content));

        foreach (self::GREETINGS as $greeting) {
            if ($content === mb_strtolower($greeting) ||
                str_starts_with($content, mb_strtolower($greeting))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if content has continuation markers.
     */
    public static function hasContinuationMarker(string $content): bool
    {
        $content = trim($content);

        foreach (self::CONTINUATION_MARKERS as $marker) {
            if (str_ends_with($content, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if content is a question.
     */
    public static function isQuestion(string $content): bool
    {
        $content = trim($content);

        // Check for ? punctuation
        if (str_ends_with($content, '?')) {
            return true;
        }

        // Check Thai question particles
        foreach (['มั้ย', 'ไหม', 'หรอ', 'เหรอ', 'ป่าว', 'รึเปล่า', 'หรือเปล่า'] as $particle) {
            if (str_ends_with($content, $particle)) {
                return true;
            }
        }

        // Check question starters
        foreach (self::QUESTION_STARTERS as $starter) {
            if (str_contains($content, $starter)) {
                return true;
            }
        }

        return false;
    }
}
