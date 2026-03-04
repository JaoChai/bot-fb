<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AgentApprovalController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BotController;
use App\Http\Controllers\Api\BotSettingController;
use App\Http\Controllers\Api\ConversationAssignmentController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationMessageController;
use App\Http\Controllers\Api\ConversationNoteController;
use App\Http\Controllers\Api\ConversationTagController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FlowController;
use App\Http\Controllers\Api\FlowPluginController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\KnowledgeBaseController;
use App\Http\Controllers\Api\LeadRecoveryController;
use App\Http\Controllers\Api\ModelController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\QuickReplyController;
use App\Http\Controllers\Api\StreamController;
use App\Http\Controllers\Api\UserSearchController;
use App\Http\Controllers\Api\UserSettingController;
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

    // Analytics routes
    Route::prefix('analytics')->group(function () {
        Route::get('/costs', [AnalyticsController::class, 'costs'])->name('analytics.costs');
        Route::get('/cache', [AnalyticsController::class, 'cacheStats'])->name('analytics.cache');
        Route::delete('/cache', [AnalyticsController::class, 'clearCache'])->name('analytics.cache.clear');
    });

    // Dashboard routes
    Route::prefix('dashboard')->group(function () {
        Route::get('/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
    });

    // Order routes
    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/summary', [OrderController::class, 'summary'])->name('orders.summary');
        Route::get('/by-customer', [OrderController::class, 'byCustomer'])->name('orders.by-customer');
        Route::get('/by-product', [OrderController::class, 'byProduct'])->name('orders.by-product');
        Route::get('/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::put('/{order}', [OrderController::class, 'update'])->name('orders.update');
    });

    // User search route (Owner only)
    Route::get('/users/search', [UserSearchController::class, 'search'])->name('users.search');

    // User Settings routes
    Route::prefix('settings')->group(function () {
        Route::get('/', [UserSettingController::class, 'show'])->name('settings.show');
        Route::put('/openrouter', [UserSettingController::class, 'updateOpenRouter'])->name('settings.openrouter.update');
        Route::put('/line', [UserSettingController::class, 'updateLine'])->name('settings.line.update');
        Route::post('/test-openrouter', [UserSettingController::class, 'testOpenRouter'])->name('settings.openrouter.test');
        Route::post('/test-line', [UserSettingController::class, 'testLine'])->name('settings.line.test');
        Route::delete('/openrouter', [UserSettingController::class, 'clearOpenRouter'])->name('settings.openrouter.clear');
        Route::delete('/line', [UserSettingController::class, 'clearLine'])->name('settings.line.clear');
    });

    // Quick Replies routes
    Route::prefix('quick-replies')->group(function () {
        Route::get('/', [QuickReplyController::class, 'index'])->name('quick-replies.index');
        Route::get('/search', [QuickReplyController::class, 'search'])->name('quick-replies.search');
        Route::post('/', [QuickReplyController::class, 'store'])->name('quick-replies.store');
        Route::get('/{quick_reply}', [QuickReplyController::class, 'show'])->name('quick-replies.show');
        Route::put('/{quick_reply}', [QuickReplyController::class, 'update'])->name('quick-replies.update');
        Route::delete('/{quick_reply}', [QuickReplyController::class, 'destroy'])->name('quick-replies.destroy');
        Route::post('/{quick_reply}/toggle', [QuickReplyController::class, 'toggle'])->name('quick-replies.toggle');
        Route::post('/reorder', [QuickReplyController::class, 'reorder'])->name('quick-replies.reorder');
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

        // LINE connection test endpoint
        Route::post('/{bot}/test-line', [BotController::class, 'testLineConnection'])
            ->middleware('throttle:10,1') // 10 requests per minute per user
            ->name('bots.test-line');

        // Telegram connection test endpoint
        Route::post('/{bot}/test-telegram', [BotController::class, 'testTelegramConnection'])
            ->middleware('throttle:10,1') // 10 requests per minute per user
            ->name('bots.test-telegram');

        // Bot credentials reveal endpoint (owner only)
        Route::get('/{bot}/credentials', [BotController::class, 'credentials'])
            ->name('bots.credentials');

        // Lead recovery routes
        Route::get('/{bot}/lead-recovery/stats', [LeadRecoveryController::class, 'getStats'])
            ->name('bots.lead-recovery.stats');
        Route::get('/{bot}/lead-recovery/logs', [LeadRecoveryController::class, 'getLogs'])
            ->name('bots.lead-recovery.logs');

        // Bot settings routes
        Route::get('/{bot}/settings', [BotSettingController::class, 'show'])->name('bots.settings.show');
        Route::put('/{bot}/settings', [BotSettingController::class, 'update'])->name('bots.settings.update');
        Route::patch('/{bot}/settings', [BotSettingController::class, 'update'])->name('bots.settings.patch');

        // Bot admin management routes (Owner only)
        Route::get('/{bot}/admins', [AdminController::class, 'index'])->name('bots.admins.index');
        Route::post('/{bot}/admins', [AdminController::class, 'store'])->name('bots.admins.store');
        Route::delete('/{bot}/admins/{user}', [AdminController::class, 'destroy'])->name('bots.admins.destroy');
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

        // Flow test endpoint for Chat Emulator
        Route::post('/{flow}/test', [FlowController::class, 'test'])
            ->middleware('throttle.bot-test')
            ->name('flows.test');

        // Flow plugin routes
        Route::prefix('/{flow}/plugins')->group(function () {
            Route::get('/', [FlowPluginController::class, 'index'])->name('flows.plugins.index');
            Route::post('/', [FlowPluginController::class, 'store'])->name('flows.plugins.store');
            Route::put('/{plugin}', [FlowPluginController::class, 'update'])->name('flows.plugins.update');
            Route::delete('/{plugin}', [FlowPluginController::class, 'destroy'])->name('flows.plugins.destroy');
        });
    });

    // Flow templates (not nested)
    Route::get('/flow-templates', [FlowController::class, 'templates'])->name('flows.templates');

    // Models endpoint (dynamic model discovery)
    Route::get('/models', [ModelController::class, 'index'])->name('models.index');

    // Knowledge Base routes (standalone, not nested under bots)
    Route::prefix('knowledge-bases')->group(function () {
        Route::get('/', [KnowledgeBaseController::class, 'index'])->name('kb.index');
        Route::post('/', [KnowledgeBaseController::class, 'store'])->name('kb.store');
        Route::get('/{knowledgeBase}', [KnowledgeBaseController::class, 'show'])->name('kb.show');
        Route::put('/{knowledgeBase}', [KnowledgeBaseController::class, 'update'])->name('kb.update');
        Route::delete('/{knowledgeBase}', [KnowledgeBaseController::class, 'destroy'])->name('kb.destroy');
        Route::post('/{knowledgeBase}/search', [KnowledgeBaseController::class, 'search'])->name('kb.search');

        // Document routes with upload rate limiting
        Route::get('/{knowledgeBase}/documents', [DocumentController::class, 'index'])->name('kb.documents.index');
        Route::post('/{knowledgeBase}/documents', [DocumentController::class, 'store'])
            ->middleware('throttle.uploads')
            ->name('kb.documents.store');
        Route::get('/{knowledgeBase}/documents/{document}', [DocumentController::class, 'show'])->name('kb.documents.show');
        Route::post('/{knowledgeBase}/documents/{document}/reprocess', [DocumentController::class, 'reprocess'])->name('kb.documents.reprocess');
        Route::delete('/{knowledgeBase}/documents/{document}', [DocumentController::class, 'destroy'])->name('kb.documents.destroy');
    });

    // Conversation routes (nested under bots)
    Route::prefix('bots/{bot}/conversations')->group(function () {
        // Static routes MUST come before wildcard routes
        // Otherwise /{conversation} will match "tags", "stats", etc.
        Route::get('/', [ConversationController::class, 'index'])->name('conversations.index');
        Route::get('/stats', [ConversationController::class, 'stats'])->name('conversations.stats');
        Route::get('/tags', [ConversationTagController::class, 'index'])->name('conversations.tags.all');
        Route::post('/bulk-tags', [ConversationTagController::class, 'bulkStore'])->name('conversations.bulk-tags');
        Route::post('/clear-context-all', [ConversationController::class, 'clearContextAll'])
            ->middleware('throttle:10,1') // 10 requests per minute
            ->name('conversations.clear-context-all');

        // Wildcard routes (must come after static routes)
        Route::get('/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::put('/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');
        Route::post('/{conversation}/close', [ConversationController::class, 'close'])->name('conversations.close');
        Route::post('/{conversation}/reopen', [ConversationController::class, 'reopen'])->name('conversations.reopen');
        Route::post('/{conversation}/clear-context', [ConversationController::class, 'clearContext'])->name('conversations.clear-context');

        // Message routes
        Route::get('/{conversation}/messages', [ConversationMessageController::class, 'index'])->name('conversations.messages');
        Route::post('/{conversation}/mark-as-read', [ConversationMessageController::class, 'markAsRead'])->name('conversations.mark-as-read');
        Route::post('/{conversation}/agent-message', [ConversationMessageController::class, 'store'])
            ->middleware('throttle:60,1') // 60 messages per minute per user
            ->name('conversations.agent-message');
        Route::post('/{conversation}/upload', [ConversationMessageController::class, 'upload'])
            ->middleware('throttle:30,1') // 30 uploads per minute
            ->name('conversations.upload');

        // Note routes
        Route::get('/{conversation}/notes', [ConversationNoteController::class, 'index'])->name('conversations.notes.index');
        Route::post('/{conversation}/notes', [ConversationNoteController::class, 'store'])->name('conversations.notes.store');
        Route::put('/{conversation}/notes/{noteId}', [ConversationNoteController::class, 'update'])->name('conversations.notes.update');
        Route::delete('/{conversation}/notes/{noteId}', [ConversationNoteController::class, 'destroy'])->name('conversations.notes.destroy');

        // Tag routes (conversation-specific)
        Route::post('/{conversation}/tags', [ConversationTagController::class, 'store'])->name('conversations.tags.store');
        Route::delete('/{conversation}/tags/{tag}', [ConversationTagController::class, 'destroy'])->name('conversations.tags.destroy');

        // Assignment and handover routes
        Route::post('/{conversation}/toggle-handover', [ConversationAssignmentController::class, 'toggleHandover'])->name('conversations.toggle-handover');
        Route::post('/{conversation}/assign', [ConversationAssignmentController::class, 'assign'])->name('conversations.assign');
        Route::post('/{conversation}/claim', [ConversationAssignmentController::class, 'claim'])->name('conversations.claim');
        Route::post('/{conversation}/unassign', [ConversationAssignmentController::class, 'unassign'])->name('conversations.unassign');
    });

    // Agent approval routes (HITL - Human-in-the-Loop)
    Route::prefix('agent-approvals')->group(function () {
        Route::get('/{approvalId}', [AgentApprovalController::class, 'show'])->name('agent-approvals.show');
        Route::post('/{approvalId}/approve', [AgentApprovalController::class, 'approve'])->name('agent-approvals.approve');
        Route::post('/{approvalId}/reject', [AgentApprovalController::class, 'reject'])->name('agent-approvals.reject');
    });
});

// Health check endpoints (no rate limiting needed)
Route::get('/health', [HealthController::class, 'index'])->name('health');
Route::get('/health/detailed', [HealthController::class, 'detailed'])
    ->middleware(['auth:sanctum'])
    ->name('health.detailed');

// Broadcasting authentication endpoint
Broadcast::routes(['middleware' => ['auth:sanctum']]);

/*
|--------------------------------------------------------------------------
| Webhook Routes (Public - no auth required)
|--------------------------------------------------------------------------
|
| These routes handle incoming webhooks from messaging platforms.
| They are public endpoints that platforms call to deliver messages.
| Moved here so they work with custom domain proxy (/api/*)
|
*/

Route::prefix('webhook')->middleware('throttle.webhook')->withoutMiddleware(['auth:sanctum'])->group(function () {
    // LINE webhook - POST /api/webhook/{token}
    Route::post('/{token}', [\App\Http\Controllers\Webhook\LINEWebhookController::class, 'handle'])
        ->name('webhook.line');

    // Telegram webhook - POST /api/webhook/telegram/{token}
    Route::post('/telegram/{token}', [\App\Http\Controllers\Webhook\TelegramWebhookController::class, 'handle'])
        ->name('webhook.telegram');

    // Facebook webhook - GET for verification, POST for events
    Route::get('/facebook/{token}', [\App\Http\Controllers\Webhook\FacebookWebhookController::class, 'verify'])
        ->name('webhook.facebook.verify');
    Route::post('/facebook/{token}', [\App\Http\Controllers\Webhook\FacebookWebhookController::class, 'handle'])
        ->name('webhook.facebook');
});

/*
|--------------------------------------------------------------------------
| Streaming Routes (Outside auth middleware for SSE support)
|--------------------------------------------------------------------------
|
| These routes handle authentication manually to support Server-Sent Events.
| Normal middleware interferes with SSE streaming responses.
|
*/

Route::post('/bots/{botId}/flows/{flowId}/stream', [StreamController::class, 'streamTest'])
    ->middleware('throttle.bot-test')
    ->name('flows.stream');
