<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ManualPaymentConfirmService when a manual confirmation already
 * happened for this conversation within the idempotency window, guarding
 * against double confirms (two tabs / retried request) that would otherwise
 * create duplicate orders and customer pushes.
 */
class RecentManualConfirmException extends RuntimeException
{
    public function __construct(string $message = 'เพิ่งยืนยันรับเงินใน conversation นี้ไปเมื่อครู่ — ถ้าต้องการยืนยันซ้ำจริงๆ รอ 2 นาทีแล้วลองใหม่')
    {
        parent::__construct($message);
    }
}
