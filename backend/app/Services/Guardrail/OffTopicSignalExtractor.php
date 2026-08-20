<?php

namespace App\Services\Guardrail;

/**
 * ดึง marker [[OFFTOPIC]] ที่ off-topic guardrail script แนบท้ายคำตอบ (prompt flow 24)
 * ตัดออกก่อนถึงลูกค้าเสมอ — เหมือน OrderPayloadExtractor แต่ไม่มี payload มีแค่ true/false
 */
class OffTopicSignalExtractor
{
    private const PATTERN = '/\s*\[\[OFFTOPIC\]\]\s*$/u';

    /**
     * @return array{clean: string, triggered: bool}
     */
    public function extract(string $content): array
    {
        if (! preg_match(self::PATTERN, $content)) {
            return ['clean' => $content, 'triggered' => false];
        }

        $clean = trim((string) preg_replace(self::PATTERN, '', $content));

        return ['clean' => $clean, 'triggered' => true];
    }
}
