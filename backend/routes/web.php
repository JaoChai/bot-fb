<?php

use App\Http\Controllers\Webhook\LINEWebhookController;
use App\Http\Controllers\Webhook\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle incoming webhooks from messaging platforms.
| They are exempt from CSRF protection (see bootstrap/app.php).
| Rate limited to prevent abuse.
|
*/

Route::prefix('webhook')->middleware('throttle.webhook')->group(function () {
    // LINE webhook - POST /webhook/{token}
    Route::post('/{token}', [LINEWebhookController::class, 'handle'])
        ->name('webhook.line');

    // Telegram webhook - POST /webhook/telegram/{token}
    Route::post('/telegram/{token}', [TelegramWebhookController::class, 'handle'])
        ->name('webhook.telegram');
});
