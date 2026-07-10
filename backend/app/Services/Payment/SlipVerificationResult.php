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
    ) {}

    public function status(): string
    {
        return $this->passed ? 'passed' : ($this->failReason ?? 'api_error');
    }
}
