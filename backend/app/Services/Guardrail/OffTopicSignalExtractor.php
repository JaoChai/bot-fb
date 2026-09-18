<?php

namespace App\Services\Guardrail;

/**
 * ดึง marker [[OFFTOPIC]] ที่ off-topic guardrail script แนบท้ายคำตอบ (prompt flow 24)
 * ตัดออกก่อนถึงลูกค้าเสมอ — เหมือน OrderPayloadExtractor แต่ไม่มี payload มีแค่ true/false
 *
 * นับเป็น off-topic เฉพาะตอน marker อยู่ท้ายข้อความตามที่ prompt สั่ง ถ้าโผล่กลางข้อความ
 * (โมเดลเขียนต่อหลัง marker หรือหลอนพิมพ์ปนมา) ไม่นับแต้ม circuit breaker แต่ยังต้องตัดทิ้ง
 * เพราะ control token ห้ามถึงตาลูกค้าไม่ว่ากรณีใด
 */
class OffTopicSignalExtractor
{
    private const MARKER = '[[OFFTOPIC]]';

    private const PATTERN = '/\s*\[\[OFFTOPIC\]\]\s*$/u';

    /**
     * @return array{clean: string, triggered: bool}
     */
    public function extract(string $content): array
    {
        if (! str_contains($content, self::MARKER)) {
            return ['clean' => $content, 'triggered' => false];
        }

        $triggered = (bool) preg_match(self::PATTERN, $content);
        $clean = (string) preg_replace('/[ \t]*'.preg_quote(self::MARKER, '/').'[ \t]*/u', ' ', $content);

        return ['clean' => trim((string) preg_replace('/[ \t]+\n/u', "\n", $clean)), 'triggered' => $triggered];
    }
}
