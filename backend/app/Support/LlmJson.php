<?php

namespace App\Support;

class LlmJson
{
    /**
     * ดึง JSON object ก้อนแรกที่วงเล็บปีกกาสมดุลออกจากข้อความ LLM
     * (รองรับ JSON ที่ห่อด้วยข้อความอธิบาย/code fence) — นับ depth แบบรู้จัก string/escape
     * คืนข้อความเดิมเมื่อไม่พบ '{' หรือวงเล็บไม่ปิดครบ (ให้ json_decode ฝั่งผู้เรียกตัดสิน)
     */
    public static function extractObject(string $content): string
    {
        $start = strpos($content, '{');
        if ($start === false) {
            return $content;
        }

        $depth = 0;
        $length = strlen($content);
        $inString = false;
        $escape = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];

            if ($escape) {
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $escape = true;

                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return $content;
    }
}
