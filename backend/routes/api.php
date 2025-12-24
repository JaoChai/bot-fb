<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BotController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application.
| These routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group.
|
*/

// Public routes (no authentication required)
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
});

// Protected routes (authentication required)
Route::middleware('auth:sanctum')->group(function () {

    // Auth routes
    Route::prefix('auth')->group(function () {
        Route::get('/user', [AuthController::class, 'user'])->name('auth.user');
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
        Route::get('/tokens', [AuthController::class, 'tokens'])->name('auth.tokens');
        Route::delete('/tokens/{tokenId}', [AuthController::class, 'revokeToken'])->name('auth.tokens.revoke');
    });

    // Bot routes
    Route::prefix('bots')->group(function () {
        Route::get('/', [BotController::class, 'index'])->name('bots.index');
        Route::post('/', [BotController::class, 'store'])->name('bots.store');
        Route::get('/{bot}', [BotController::class, 'show'])->name('bots.show');
        Route::put('/{bot}', [BotController::class, 'update'])->name('bots.update');
        Route::delete('/{bot}', [BotController::class, 'destroy'])->name('bots.destroy');
        Route::get('/{bot}/webhook-url', [BotController::class, 'webhookUrl'])->name('bots.webhook-url');
        Route::post('/{bot}/regenerate-webhook', [BotController::class, 'regenerateWebhook'])->name('bots.regenerate-webhook');
        Route::post('/{bot}/test', [BotController::class, 'test'])->name('bots.test');
    });

    // Future routes will be added here:
    // - Flows CRUD
    // - Conversations
    // - Messages
    // - Knowledge Bases
    // - Settings
});

// Health check endpoint
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');
