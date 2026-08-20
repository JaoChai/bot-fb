<?php

namespace App\Services\Guardrail;

/**
 * ตาข่ายสุดท้ายก่อนคำตอบถึงลูกค้า — เช็คด้วย regex ล้วน (ไม่มี LLM call เพิ่ม) ว่ามี
 * code block/markdown จริง หรือบอทพูดยอมรับว่าเป็น AI ไหม กันเคส guardrail ชั้น prompt
 * พลาดจริงแล้วลูกค้าโดนแคปแชร์ (ดู docs/superpowers/specs/2026-08-20-off-topic-guardrail-enforcement-design.md)
 *
 * ตั้งใจไม่เช็คภาษาอังกฤษล้วน — เสี่ยง false positive กับศัพท์ธุรกิจจริง (BM/CAPI/Pixel)
 */
class GuardrailOutputSanitizer
{
    /** @var array<string, string> reason => regex pattern, เช็คตามลำดับ คืนตัวแรกที่ match */
    private const PATTERNS = [
        'code_fence' => '/```/',
        'markdown_bold' => '/\*\*[^*]+\*\*/',
        'markdown_heading' => '/(?:^|\n)#{1,6}\s/',
        'ai_admission_th' => '/ในฐานะ\s*(?:ที่เป็น\s*)?AI|ผมเป็น\s*(?:ระบบ\s*)?AI|ฉันเป็น\s*AI/u',
        'ai_admission_en' => '/\bas an AI\b|\bI(?:\'m| am) an AI\b/i',
    ];

    /**
     * @return array{flagged: bool, reason: ?string}
     */
    public function check(string $content): array
    {
        foreach (self::PATTERNS as $reason => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return ['flagged' => true, 'reason' => $reason];
            }
        }

        return ['flagged' => false, 'reason' => null];
    }
}
