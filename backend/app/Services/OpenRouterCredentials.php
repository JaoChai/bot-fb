<?php

namespace App\Services;

use App\Exceptions\OpenRouterException;

/**
 * The only reader of the OpenRouter API key. The key lives in one place, the
 * OPENROUTER_API_KEY environment variable, and every class that makes an HTTP call
 * to OpenRouter asks here instead of reading config itself.
 *
 * Reads config on every call rather than caching in a property: several consumers
 * are container singletons, and tests change the config after construction.
 */
final class OpenRouterCredentials
{
    public function isConfigured(): bool
    {
        return $this->rawKey() !== '';
    }

    /**
     * @throws OpenRouterException when the key is not set. OpenRouterException, not a
     *                             RuntimeException, so customer-facing paths reply with
     *                             the standard error message instead of failing into retries.
     */
    public function key(): string
    {
        $key = $this->rawKey();

        if ($key === '') {
            throw new OpenRouterException('OPENROUTER_API_KEY is not set', 401);
        }

        return $key;
    }

    private function rawKey(): string
    {
        return trim(config_string('services.openrouter.api_key'));
    }
}
