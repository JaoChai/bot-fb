<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Webhook Routes - MOVED TO api.php
|--------------------------------------------------------------------------
|
| Webhook routes have been moved to routes/api.php so they work with
| custom domain proxy that only routes /api/* paths.
|
| New paths:
| - LINE: POST /api/webhook/{token}
| - Telegram: POST /api/webhook/telegram/{token}
|
*/

/*
|--------------------------------------------------------------------------
| Legacy Webhook Fallback Routes
|--------------------------------------------------------------------------
|
| These routes provide backward compatibility for webhooks configured
| with the old URL format (without /api/ prefix). They forward requests
| to the correct controllers.
|
*/

Route::prefix('webhook')->group(function () {
    // LINE webhook fallback - POST /webhook/{token}
    Route::post('/{token}', [\App\Http\Controllers\Webhook\LINEWebhookController::class, 'handle']);

    // Telegram webhook fallback - POST /webhook/telegram/{token}
    Route::post('/telegram/{token}', [\App\Http\Controllers\Webhook\TelegramWebhookController::class, 'handle']);
});
