<?php

namespace App\Exceptions;

use Exception;

class OpenRouterException extends Exception
{
    protected int $httpStatus;

    public function __construct(string $message, int $httpStatus = 500, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = $httpStatus;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429;
    }

    public function isServerError(): bool
    {
        return $this->httpStatus >= 500;
    }

    public function isAuthError(): bool
    {
        return in_array($this->httpStatus, [401, 403]);
    }
}
