<?php

namespace App\Services\PromptEval;

/**
 * ผลการรัน 1 test case ของ PromptEvalRunner — ค่าคงที่ทั้งหมด (readonly) เพราะเป็นผลลัพธ์
 * ที่รันจบแล้ว ไม่มีสถานะให้ mutate ต่อ
 */
final class CaseResult
{
    /**
     * @param  array<int, string>  $failures  เหตุผลภาษาไทยที่ทำให้ case ตก (ว่าง = ผ่าน)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly bool $passed,
        public readonly string $response,
        public readonly array $failures,
        public readonly int $durationMs,
        public readonly float $cost,
    ) {}
}
