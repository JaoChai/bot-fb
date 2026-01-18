<?php

/**
 * Config Helper Functions
 *
 * Helper functions for safely retrieving config values with proper null coalescing.
 * These functions handle the case where config() returns null instead of the default value.
 *
 * Usage:
 *   $apiKey = config_string('services.openrouter.api_key');
 *   $model = config_string('llm-models.default', 'gpt-4');
 *   $maxTokens = config_int('rag.max_tokens', 1000);
 *   $providers = config_array('services.ai.providers', []);
 */

if (!function_exists('config_string')) {
    /**
     * Get a string config value with null coalescing.
     *
     * @param string $key The config key to retrieve
     * @param string $default The default value if config is null
     * @return string
     */
    function config_string(string $key, string $default = ''): string
    {
        $value = config($key);

        if ($value === null) {
            return $default;
        }

        return (string) $value;
    }
}

if (!function_exists('config_int')) {
    /**
     * Get an integer config value with null coalescing.
     *
     * @param string $key The config key to retrieve
     * @param int $default The default value if config is null
     * @return int
     */
    function config_int(string $key, int $default = 0): int
    {
        $value = config($key);

        if ($value === null) {
            return $default;
        }

        return (int) $value;
    }
}

if (!function_exists('config_float')) {
    /**
     * Get a float config value with null coalescing.
     *
     * @param string $key The config key to retrieve
     * @param float $default The default value if config is null
     * @return float
     */
    function config_float(string $key, float $default = 0.0): float
    {
        $value = config($key);

        if ($value === null) {
            return $default;
        }

        return (float) $value;
    }
}

if (!function_exists('config_bool')) {
    /**
     * Get a boolean config value with null coalescing.
     *
     * @param string $key The config key to retrieve
     * @param bool $default The default value if config is null
     * @return bool
     */
    function config_bool(string $key, bool $default = false): bool
    {
        $value = config($key);

        if ($value === null) {
            return $default;
        }

        return (bool) $value;
    }
}

if (!function_exists('config_array')) {
    /**
     * Get an array config value with null coalescing.
     *
     * @param string $key The config key to retrieve
     * @param array<mixed> $default The default value if config is null
     * @return array<mixed>
     */
    function config_array(string $key, array $default = []): array
    {
        $value = config($key);

        if ($value === null || !is_array($value)) {
            return $default;
        }

        return $value;
    }
}
