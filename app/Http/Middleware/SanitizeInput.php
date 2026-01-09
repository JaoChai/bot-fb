<?php

namespace App\Http\Middleware;

use App\Support\Sanitizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInput
{
    /**
     * Fields that should not be sanitized.
     */
    protected array $except = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $input = $request->all();

        $request->merge($this->sanitize($input));

        return $next($request);
    }

    /**
     * Recursively sanitize input data.
     */
    protected function sanitize(array $input, string $prefix = ''): array
    {
        $sanitized = [];

        foreach ($input as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            // Skip fields that shouldn't be sanitized
            if (in_array($key, $this->except) || in_array($fullKey, $this->except)) {
                $sanitized[$key] = $value;
                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = Sanitizer::clean($value);
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value, $fullKey);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
