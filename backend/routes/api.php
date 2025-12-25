<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BotController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FlowController;
use App\Http\Controllers\Api\KnowledgeBaseController;
use Illuminate\Support\Facades\Broadcast;
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

// Public routes with auth rate limiting (stricter limits)
Route::prefix('auth')->middleware('throttle.auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
});

// Protected routes (authentication required) with API rate limiting
Route::middleware(['auth:sanctum', 'throttle.api'])->group(function () {

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

        // Bot test endpoint with stricter rate limiting
        Route::post('/{bot}/test', [BotController::class, 'test'])
            ->middleware('throttle.bot-test')
            ->name('bots.test');
    });

    // Flow routes (nested under bots)
    Route::prefix('bots/{bot}/flows')->group(function () {
        Route::get('/', [FlowController::class, 'index'])->name('flows.index');
        Route::post('/', [FlowController::class, 'store'])->name('flows.store');
        Route::get('/{flow}', [FlowController::class, 'show'])->name('flows.show');
        Route::put('/{flow}', [FlowController::class, 'update'])->name('flows.update');
        Route::delete('/{flow}', [FlowController::class, 'destroy'])->name('flows.destroy');
        Route::post('/{flow}/set-default', [FlowController::class, 'setDefault'])->name('flows.set-default');
        Route::post('/{flow}/duplicate', [FlowController::class, 'duplicate'])->name('flows.duplicate');
    });

    // Flow templates (not nested)
    Route::get('/flow-templates', [FlowController::class, 'templates'])->name('flows.templates');

    // Knowledge Base routes (nested under bots)
    Route::prefix('bots/{bot}/knowledge-base')->group(function () {
        Route::get('/', [KnowledgeBaseController::class, 'show'])->name('kb.show');
        Route::put('/', [KnowledgeBaseController::class, 'update'])->name('kb.update');
        Route::post('/search', [KnowledgeBaseController::class, 'search'])->name('kb.search');

        // Document routes with upload rate limiting
        Route::get('/documents', [DocumentController::class, 'index'])->name('kb.documents.index');
        Route::post('/documents', [DocumentController::class, 'store'])
            ->middleware('throttle.uploads')
            ->name('kb.documents.store');
        Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('kb.documents.show');
        Route::post('/documents/{document}/reprocess', [DocumentController::class, 'reprocess'])->name('kb.documents.reprocess');
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('kb.documents.destroy');
    });

    // Future routes will be added here:
    // - Conversations
    // - Messages
    // - Settings
});

// Health check endpoint (no rate limiting needed)
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');

// Broadcasting authentication endpoint
Broadcast::routes(['middleware' => ['auth:sanctum']]);
