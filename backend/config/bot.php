<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bot Fallback Messages
    |--------------------------------------------------------------------------
    |
    | Messages sent to users when the system encounters errors and cannot
    | generate a normal response. These ensure users always receive feedback.
    |
    */

    'fallback_message' => env('BOT_FALLBACK_MESSAGE', 'ขออภัยครับ ระบบกำลังมีปัญหาชั่วคราว กรุณาลองใหม่ในอีกสักครู่'),

    'fallback_message_en' => env('BOT_FALLBACK_MESSAGE_EN', 'Sorry, we are experiencing temporary issues. Please try again shortly.'),

    /*
    |--------------------------------------------------------------------------
    | Fallback Behavior
    |--------------------------------------------------------------------------
    */

    // Whether to send fallback message when circuit breaker is open
    'send_fallback_on_circuit_open' => env('BOT_SEND_FALLBACK_ON_CIRCUIT_OPEN', true),

    // Whether to log fallback events to Sentry
    'log_fallback_to_sentry' => env('BOT_LOG_FALLBACK_TO_SENTRY', true),
];
