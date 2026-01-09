<?php

namespace App\Exceptions;

use Exception;

class TelegramException extends Exception
{
    protected array $details;

    public function __construct(string $message, int $code = 0, array $details = [])
    {
        parent::__construct($message, $code);
        $this->details = $details;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
