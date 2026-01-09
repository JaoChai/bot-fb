<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Bot\BotController;
use App\Http\Controllers\Bot\BotSettingsController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Flow\FlowController;
use App\Http\Controllers\KnowledgeBase\KnowledgeBaseController;
use App\Http\Controllers\Settings\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest Routes
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/', function () {
        return redirect('/login');
    });

    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | Bot Management Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('bots')->name('bots.')->group(function () {
        // Bot CRUD
        Route::get('/', [BotController::class, 'index'])->name('index');
        Route::get('/{bot}', [BotController::class, 'show'])->name('show');
        Route::get('/{bot}/edit', [BotController::class, 'edit'])->name('edit');
        Route::put('/{bot}', [BotController::class, 'update'])->name('update');
        Route::delete('/{bot}', [BotController::class, 'destroy'])->name('destroy');

        // Bot Settings
        Route::prefix('{bot}/settings')->name('settings.')->group(function () {
            // Credentials
            Route::get('/credentials', [BotSettingsController::class, 'credentials'])->name('credentials');
            Route::put('/credentials', [BotSettingsController::class, 'updateCredentials'])->name('credentials.update');

            // AI Settings
            Route::get('/ai', [BotSettingsController::class, 'aiSettings'])->name('ai');
            Route::put('/ai', [BotSettingsController::class, 'updateAiSettings'])->name('ai.update');

            // Notifications
            Route::get('/notifications', [BotSettingsController::class, 'notifications'])->name('notifications');
            Route::put('/notifications', [BotSettingsController::class, 'updateNotifications'])->name('notifications.update');

            // Test Connection
            Route::post('/test-connection', [BotSettingsController::class, 'testConnection'])->name('test-connection');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Chat Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/', [ChatController::class, 'index'])->name('index');
        Route::get('/conversations/{conversation}', [ChatController::class, 'show'])->name('show');
        Route::get('/conversations/{conversation}/messages', [ChatController::class, 'loadMoreMessages'])->name('messages');
        Route::post('/conversations/{conversation}/send', [ChatController::class, 'sendMessage'])->name('send');
        Route::post('/conversations/{conversation}/hitl', [ChatController::class, 'toggleHitl'])->name('hitl');
        Route::get('/conversations/{conversation}/customer', [ChatController::class, 'customerDetails'])->name('customer');
    });

    /*
    |--------------------------------------------------------------------------
    | Knowledge Base Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('knowledge-base')->name('knowledge-base.')->group(function () {
        Route::get('/', [KnowledgeBaseController::class, 'index'])->name('index');
        Route::get('/{knowledgeBase}', [KnowledgeBaseController::class, 'show'])->name('show');
        Route::post('/', [KnowledgeBaseController::class, 'store'])->name('store');
        Route::put('/{knowledgeBase}', [KnowledgeBaseController::class, 'update'])->name('update');
        Route::delete('/{knowledgeBase}', [KnowledgeBaseController::class, 'destroy'])->name('destroy');
        Route::post('/{knowledgeBase}/documents', [KnowledgeBaseController::class, 'uploadDocument'])->name('documents.upload');
        Route::delete('/{knowledgeBase}/documents/{document}', [KnowledgeBaseController::class, 'deleteDocument'])->name('documents.destroy');
        Route::post('/{knowledgeBase}/documents/{document}/retry', [KnowledgeBaseController::class, 'retryDocument'])->name('documents.retry');
        Route::post('/{knowledgeBase}/search', [KnowledgeBaseController::class, 'search'])->name('search');
    });

    /*
    |--------------------------------------------------------------------------
    | Flow Routes (SSE streaming page uses Inertia)
    |--------------------------------------------------------------------------
    */
    Route::prefix('flows')->name('flows.')->group(function () {
        Route::get('/', [FlowController::class, 'index'])->name('index');
        Route::get('/create', [FlowController::class, 'create'])->name('create');
        Route::post('/', [FlowController::class, 'store'])->name('store');
        Route::get('/{flow}', [FlowController::class, 'show'])->name('show');
        Route::put('/{flow}', [FlowController::class, 'update'])->name('update');
        Route::delete('/{flow}', [FlowController::class, 'destroy'])->name('destroy');
        Route::post('/{flow}/duplicate', [FlowController::class, 'duplicate'])->name('duplicate');
    });

    /*
    |--------------------------------------------------------------------------
    | Settings Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::put('/profile', [SettingsController::class, 'updateProfile'])->name('profile');
        Route::put('/notifications', [SettingsController::class, 'updateNotifications'])->name('notifications');
    });
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

    // Facebook webhook fallback - GET for verification, POST for events
    Route::get('/facebook/{token}', [\App\Http\Controllers\Webhook\FacebookWebhookController::class, 'verify']);
    Route::post('/facebook/{token}', [\App\Http\Controllers\Webhook\FacebookWebhookController::class, 'handle']);
});
