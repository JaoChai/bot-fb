<?php

namespace App\Services\Payment;

/**
 * ออเดอร์ที่ระบบสรุปเองจากบทสนทนา (ด่าน 3) — ผ่านตัวตรวจครบแล้วเท่านั้นถึงถูกสร้างขึ้น
 *
 * @param  array<int, array{name: string, total: string, qty: int}>  $items
 * @param  array<int, array<int, array{name: string, total: string, qty: int}>>  $alternatives
 */
class OrderReconstruction
{
    public function __construct(
        public readonly array $items,
        public readonly float $total,
        public readonly string $summary,
        public readonly bool $ambiguous,
        public readonly array $alternatives = [],
    ) {}
}
