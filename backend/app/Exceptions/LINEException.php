<?php

namespace App\Exceptions;

use Exception;

class LINEException extends Exception
{
    protected ?array $details;

    public function __construct(string $message, int $code = 0, ?array $details = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->details = $details;
    }

    /**
     * Get additional error details from LINE API.
     */
    public function getDetails(): ?array
    {
        return $this->details;
    }

    /**
     * Check if this is a signature verification error.
     */
    public function isSignatureError(): bool
    {
        return $this->code === 401;
    }

    /**
     * Check if this is a rate limit error.
     */
    public function isRateLimited(): bool
    {
        return $this->code === 429;
    }

    /**
     * Check if the bot token is invalid.
     */
    public function isInvalidToken(): bool
    {
        return $this->code === 401 && str_contains($this->message, 'token');
    }

    /**
     * Check if the reply token is expired or invalid.
     * LINE returns 400 with "Invalid reply token" message when token expires.
     */
    public function isReplyTokenExpired(): bool
    {
        return $this->code === 400
            && (str_contains(strtolower($this->message), 'invalid reply token')
                || str_contains(strtolower($this->message), 'reply token'));
    }
}
