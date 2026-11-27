<?php

namespace App\Services\Guardrail;

/**
 * ตัดหาง "ความคิดในใจ" ของโมเดลที่หลุดมาท้ายกล่องข้อความ — กติกาตายตัว ไม่เรียกโมเดล
 *
 * พื้นหลัง: บอท 26 มีคำตอบลงท้าย "เครดิตฟรี" หรือ "เครดิตฟรี? no. ask more." (42 เคส ต.ค. ย้อน
 * ไปถึง ก.ค. 2026) — เศษ reasoning หลุดเข้าช่อง content เมื่อ finish_reason ไม่ใช่ stop
 * (ดูรายงาน root cause และ docs/testing/bot26-v28-gate-review-2026-09-16/signal-trips-T02-T21.md)
 *
 * กติกา:
 * - แยกกล่องด้วยตัวคั่น multiple bubbles ของบอท (ค่าเริ่มต้น '|||')
 * - กล่องที่มี 'เครดิตฟรี' โดยส่วนท้ายหลังคำเป็นช่องว่าง/เครื่องหมายวรรคตอน หรืออักขระ
 *   ASCII ล้วนจนจบกล่อง → ตัดตั้งแต่ 'เครดิตฟรี' ถึงจบกล่อง + trim ท้าย
 * - ทำเฉพาะเมื่อข้อความลูกค้ารอบนี้และข้อความฝั่งลูกค้าในประวัติที่ส่งให้โมเดล ไม่มีคำว่า 'เครดิต'
 * - ถ้าหลัง 'เครดิตฟรี' มีอักขระไม่ใช่ ASCII (เช่น ไทย) ต่อ = กลางประโยค → ห้ามแก้ (log อย่างเดียว)
 * - ห้ามแตะกล่องที่มี 'บัตรเครดิต'
 * - กล่องที่ตัดแล้วว่าง → ทิ้งกล่องนั้น; ถ้าตัดแล้วคำตอบทั้งก้อนว่าง → คืนของเดิม
 *   (ห้ามทำให้ลูกค้าไม่ได้คำตอบ — ผู้เรียกต้อง log กรณีนี้เป็นข้อจำกัด)
 * - คำตอบที่ไม่เข้ากติกาต้องออกมาไบต์เดิมทุกไบต์
 *
 * คลาสนี้ pure — ไม่ใช้ Log/DB เอง ผู้เรียกอ่าน `events` ไป log พร้อม id เอง
 */
class LeakTailGuard
{
    public const TRIGGER = 'เครดิตฟรี';

    /** คำที่ห้ามแตะ — กล่องที่มีคำนี้ถูกข้ามทั้งกล่อง */
    private const PROTECTED = 'บัตรเครดิต';

    /** คำเงื่อนไขลูกค้า — ลูกค้าพูดคำนี้ (รอบนี้หรือในประวัติ) ตัวตัดปิด */
    private const CUSTOMER_CONDITION = 'เครดิต';

    public const EVENT_STRIPPED = 'stripped';

    public const EVENT_MID_SENTENCE = 'mid_sentence';

    public const EVENT_WOULD_EMPTY = 'would_empty';

    /**
     * @param  string  $userMessage  ข้อความลูกค้ารอบนี้ (ก่อน merge ก็ได้ — ตรวจแบบ substring)
     * @param  array<int, array{sender: string, content: string}>  $history  ประวัติที่ส่งให้โมเดล
     * @param  string  $delimiter  ตัวคั่น multiple bubbles ของบอท (ค่าเริ่มต้น '|||')
     * @return array{content: string, events: array<int, string>} events = ชนิดเหตุการณ์ (ซ้ำซ้อนตัดออกโดยผู้เรียก)
     */
    public function guard(string $content, string $userMessage, array $history = [], string $delimiter = '|||'): array
    {
        // fail-safe: ตัวคั่นว่างทำให้ explode() โยน ValueError — ใช้ค่าเริ่มต้นแทน
        if ($delimiter === '') {
            $delimiter = '|||';
        }

        if ($content === '' || mb_strpos($content, self::TRIGGER) === false) {
            return ['content' => $content, 'events' => []];
        }

        // เงื่อนไขลูกค้า: ลูกค้าพูดเรื่องเครดิตเอง (คำใด ๆ รวมบัตรเครดิต/เครดิตฟรี) → ไม่แตะอะไรเลย
        if (mb_strpos($userMessage, self::CUSTOMER_CONDITION) !== false) {
            return ['content' => $content, 'events' => []];
        }
        foreach ($history as $message) {
            if (($message['sender'] ?? '') !== 'user') {
                continue;
            }
            if (mb_strpos((string) ($message['content'] ?? ''), self::CUSTOMER_CONDITION) !== false) {
                return ['content' => $content, 'events' => []];
            }
        }

        $events = [];
        $strippedAny = false;
        $kept = [];
        foreach (explode($delimiter, $content) as $bubble) {
            $pos = mb_strpos($bubble, self::TRIGGER);
            if ($pos === false || mb_strpos($bubble, self::PROTECTED) !== false) {
                $kept[] = $bubble;

                continue;
            }

            $tail = mb_substr($bubble, $pos + mb_strlen(self::TRIGGER));
            if (preg_match('/[^\x00-\x7F]/', $tail) === 1) {
                // หลังคำมีอักขระไม่ใช่ ASCII (ไทย/อีโมจิ) ต่อ = กลางประโยค → ห้ามแก้
                $events[] = self::EVENT_MID_SENTENCE;
                $kept[] = $bubble;

                continue;
            }

            $stripped = rtrim(mb_substr($bubble, 0, $pos));
            if ($stripped !== '') {
                $kept[] = $stripped;
            }
            $strippedAny = true;
            $events[] = self::EVENT_STRIPPED;
        }

        if (! $strippedAny) {
            return ['content' => $content, 'events' => $events];
        }

        $joined = implode($delimiter, $kept);
        if ($joined === '') {
            // ตัดแล้วคำตอบทั้งก้อนว่าง → ส่งของเดิม ห้ามทำให้ลูกค้าไม่ได้คำตอบ
            return ['content' => $content, 'events' => [self::EVENT_WOULD_EMPTY]];
        }

        return ['content' => $joined, 'events' => $events];
    }
}
