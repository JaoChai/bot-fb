<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ManualPaymentConfirmService when no order amount can be resolved
 * (neither detected from chat history nor supplied in the request).
 */
class NoPendingPaymentException extends RuntimeException
{
    public function __construct(string $message = 'ไม่พบยอดออเดอร์ กรุณาระบุยอด')
    {
        parent::__construct($message);
    }
}
