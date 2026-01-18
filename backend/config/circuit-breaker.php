<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Configuration
    |--------------------------------------------------------------------------
    |
    | Configure circuit breaker behavior for external services.
    | States: CLOSED (normal) -> OPEN (failing) -> HALF_OPEN (testing)
    |
    */

    'enabled' => env('CIRCUIT_BREAKER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Service-specific Settings
    |--------------------------------------------------------------------------
    */

    'services' => [
        'database' => [
            // Number of failures before circuit opens
            'failure_threshold' => (int) env('CIRCUIT_BREAKER_DB_FAILURE_THRESHOLD', 5),

            // Seconds to wait before attempting recovery (half-open state)
            'recovery_timeout' => (int) env('CIRCUIT_BREAKER_DB_RECOVERY_TIMEOUT', 30),

            // Successful calls needed to close circuit from half-open
            'success_threshold' => (int) env('CIRCUIT_BREAKER_DB_SUCCESS_THRESHOLD', 2),
        ],

        'cache' => [
            'failure_threshold' => (int) env('CIRCUIT_BREAKER_CACHE_FAILURE_THRESHOLD', 3),
            'recovery_timeout' => (int) env('CIRCUIT_BREAKER_CACHE_RECOVERY_TIMEOUT', 15),
            'success_threshold' => (int) env('CIRCUIT_BREAKER_CACHE_SUCCESS_THRESHOLD', 1),
        ],

        'openrouter' => [
            'failure_threshold' => (int) env('CIRCUIT_BREAKER_OPENROUTER_FAILURE_THRESHOLD', 5),
            'recovery_timeout' => (int) env('CIRCUIT_BREAKER_OPENROUTER_RECOVERY_TIMEOUT', 60),
            'success_threshold' => (int) env('CIRCUIT_BREAKER_OPENROUTER_SUCCESS_THRESHOLD', 2),
        ],

        'openrouter_models' => [
            'failure_threshold' => (int) env('CIRCUIT_BREAKER_OPENROUTER_MODELS_FAILURE_THRESHOLD', 3),
            'recovery_timeout' => (int) env('CIRCUIT_BREAKER_OPENROUTER_MODELS_RECOVERY_TIMEOUT', 60),
            'success_threshold' => (int) env('CIRCUIT_BREAKER_OPENROUTER_MODELS_SUCCESS_THRESHOLD', 2),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for circuit breaker state keys in cache.
    |
    */

    'cache_prefix' => 'circuit_breaker',
];
