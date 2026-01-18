<?php

namespace App\Exceptions;

use Exception;

class CircuitOpenException extends Exception
{
    protected string $service;

    public function __construct(string $service, string $message = '', ?\Throwable $previous = null)
    {
        $this->service = $service;
        $message = $message ?: "Circuit breaker is open for service: {$service}";
        parent::__construct($message, 0, $previous);
    }

    public function getService(): string
    {
        return $this->service;
    }
}
