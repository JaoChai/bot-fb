<?php

namespace App\Services\Payment;

class SlipVerificationResult
{
    /** id แถว slip_verifications ที่บันทึกไป (ใส่โดย record()) — ใช้ผูกงานส่งของ */
    public ?int $slipVerificationId = null;

    public function __construct(
        public readonly bool $isSlip,
        public readonly bool $passed,
        public readonly ?string $failReason = null,
        public readonly ?float $amount = null,
        public readonly ?string $transRef = null,
        public readonly ?float $expectedAmount = null,
        public readonly ?string $orderSummary = null,
        public readonly ?array $orderItems = null,
        /** ด่านที่หาออเดอร์เจอ: summary | confirm | llm | null */
        public readonly ?string $orderSource = null,
        /** ออเดอร์ที่ระบบสรุปเอง — มีค่าเฉพาะตอน orderSource = 'llm' */
        public readonly ?OrderReconstruction $reconstruction = null,
        /** true = ผลรวมรายการขัดกับยอดโอน — รับเงินได้ แต่ห้ามส่งของอัตโนมัติ */
        public readonly bool $itemsUnreliable = false,
    ) {}

    public function status(): string
    {
        return $this->passed ? 'passed' : ($this->failReason ?? 'api_error');
    }
}
