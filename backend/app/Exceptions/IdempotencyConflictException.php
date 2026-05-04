<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class IdempotencyConflictException extends HttpException
{
    public function __construct(string $message = 'Idempotency key reused with different payload')
    {
        parent::__construct(422, $message);
    }
}
