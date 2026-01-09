<?php

namespace App\Exceptions;

use Exception;

class FacebookException extends Exception
{
    protected ?array $details;

    public function __construct(string $message, int $code = 0, ?array $details = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->details = $details;
    }

    /**
     * Get additional error details from Facebook API.
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
     * Check if the page access token is invalid.
     */
    public function isInvalidToken(): bool
    {
        return $this->code === 190 || ($this->code === 401 && str_contains($this->message, 'token'));
    }

    /**
     * Check if user has blocked the page.
     */
    public function isUserBlocked(): bool
    {
        // Facebook error code 551 = user blocked
        return $this->code === 551;
    }

    /**
     * Check if the message window has expired (24-hour rule).
     */
    public function isMessageWindowExpired(): bool
    {
        // Facebook error code 10 = permission denied (often 24-hour window)
        return $this->code === 10;
    }
}
