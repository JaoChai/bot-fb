<?php

namespace App\Support;

use Illuminate\Support\Str;

class Sanitizer
{
    /**
     * Sanitize a string for safe storage and display.
     * Removes script tags, event handlers, and dangerous HTML.
     */
    public static function clean(string $input): string
    {
        // Remove null bytes
        $input = str_replace("\0", '', $input);

        // Remove script tags and their contents
        $input = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $input);

        // Remove event handlers (onclick, onerror, etc.)
        $input = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $input);
        $input = preg_replace('/\s*on\w+\s*=\s*[^\s>]*/i', '', $input);

        // Remove javascript: protocol
        $input = preg_replace('/javascript\s*:/i', '', $input);

        // Remove data: protocol (can contain scripts)
        $input = preg_replace('/data\s*:[^,]*,/i', '', $input);

        // Remove vbscript: protocol
        $input = preg_replace('/vbscript\s*:/i', '', $input);

        // Remove dangerous HTML tags
        $dangerousTags = ['iframe', 'object', 'embed', 'form', 'input', 'button', 'meta', 'link', 'style', 'base'];
        foreach ($dangerousTags as $tag) {
            $input = preg_replace('/<\/?'.$tag.'\b[^>]*>/i', '', $input);
        }

        return trim($input);
    }

    /**
     * Sanitize a string for plain text output (remove all HTML).
     */
    public static function plainText(string $input): string
    {
        return trim(strip_tags(self::clean($input)));
    }

    /**
     * Sanitize for JSON output (escape special characters).
     */
    public static function forJson(string $input): string
    {
        return htmlspecialchars(self::clean($input), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Sanitize a message from webhook (LINE/Facebook/etc).
     * Preserves safe formatting but removes dangerous content.
     */
    public static function message(string $input, int $maxLength = 10000): string
    {
        $sanitized = self::plainText($input);

        // Normalize whitespace (but preserve newlines)
        $sanitized = preg_replace('/[^\S\n]+/', ' ', $sanitized);
        $sanitized = preg_replace('/\n{3,}/', "\n\n", $sanitized);

        // Truncate to max length
        if (mb_strlen($sanitized) > $maxLength) {
            $sanitized = mb_substr($sanitized, 0, $maxLength);
        }

        return trim($sanitized);
    }

    /**
     * Sanitize an email address.
     */
    public static function email(string $input): string
    {
        $sanitized = filter_var(trim($input), FILTER_SANITIZE_EMAIL);
        return $sanitized ?: '';
    }

    /**
     * Sanitize a URL.
     */
    public static function url(string $input): string
    {
        $sanitized = filter_var(trim($input), FILTER_SANITIZE_URL);

        // Only allow http and https protocols
        if ($sanitized && preg_match('/^https?:\/\//i', $sanitized)) {
            return $sanitized;
        }

        return '';
    }

    /**
     * Sanitize a filename for safe storage.
     */
    public static function filename(string $input): string
    {
        // Remove directory traversal attempts
        $input = basename($input);

        // Remove or replace dangerous characters
        $input = preg_replace('/[^\w\-\.]/', '_', $input);

        // Remove multiple dots (prevent extension manipulation)
        $input = preg_replace('/\.{2,}/', '.', $input);

        // Remove leading/trailing dots
        $input = trim($input, '.');

        // Ensure the filename isn't empty
        if (empty($input)) {
            $input = 'unnamed_file';
        }

        return $input;
    }

    /**
     * Sanitize numeric input.
     */
    public static function numeric(mixed $input): ?int
    {
        if (is_numeric($input)) {
            return (int) $input;
        }
        return null;
    }

    /**
     * Sanitize a boolean input.
     */
    public static function boolean(mixed $input): bool
    {
        return filter_var($input, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Sanitize an array of strings.
     */
    public static function array(array $input, string $method = 'clean'): array
    {
        return array_map(function ($item) use ($method) {
            if (is_string($item)) {
                return self::$method($item);
            }
            if (is_array($item)) {
                return self::array($item, $method);
            }
            return $item;
        }, $input);
    }

    /**
     * Check if a string contains potential XSS.
     */
    public static function hasXss(string $input): bool
    {
        $patterns = [
            '/<script/i',
            '/javascript\s*:/i',
            '/on\w+\s*=/i',
            '/<iframe/i',
            '/<object/i',
            '/<embed/i',
            '/data\s*:/i',
            '/vbscript\s*:/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask sensitive data (API keys, tokens, etc).
     */
    public static function mask(string $input, int $visibleChars = 4): string
    {
        $length = strlen($input);

        if ($length <= $visibleChars * 2) {
            return str_repeat('*', $length);
        }

        return substr($input, 0, $visibleChars)
            . str_repeat('*', $length - ($visibleChars * 2))
            . substr($input, -$visibleChars);
    }

    /**
     * Sanitize log data to prevent log injection.
     */
    public static function forLog(string $input): string
    {
        // Remove newlines and carriage returns
        $sanitized = str_replace(["\r", "\n", "\t"], ' ', $input);

        // Remove control characters
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/', '', $sanitized);

        // Limit length for logs
        if (mb_strlen($sanitized) > 1000) {
            $sanitized = mb_substr($sanitized, 0, 1000) . '...[truncated]';
        }

        return $sanitized;
    }
}
