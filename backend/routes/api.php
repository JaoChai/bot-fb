<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BotController;
use App\Http\Controllers\Api\BotSettingController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FlowController;
use App\Http\Controllers\Api\KnowledgeBaseController;
use App\Http\Controllers\Api\StreamController;
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
    });

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

        // Bot settings routes
        Route::get('/{bot}/settings', [BotSettingController::class, 'show'])->name('bots.settings.show');
        Route::put('/{bot}/settings', [BotSettingController::class, 'update'])->name('bots.settings.update');
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
    });

    // Flow templates (not nested)
    Route::get('/flow-templates', [FlowController::class, 'templates'])->name('flows.templates');

    // Knowledge Bases list (all user's KBs for Flow multi-select)
    Route::get('/knowledge-bases', [KnowledgeBaseController::class, 'index'])->name('kb.index');

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

    // Conversation routes (nested under bots)
    Route::prefix('bots/{bot}/conversations')->group(function () {
        Route::get('/', [ConversationController::class, 'index'])->name('conversations.index');
        Route::get('/stats', [ConversationController::class, 'stats'])->name('conversations.stats');
        Route::get('/tags', [ConversationController::class, 'getAllTags'])->name('conversations.tags.all');
        Route::post('/bulk-tags', [ConversationController::class, 'bulkAddTags'])->name('conversations.bulk-tags');
        Route::get('/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::put('/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');
        Route::get('/{conversation}/messages', [ConversationController::class, 'messages'])->name('conversations.messages');
        Route::post('/{conversation}/close', [ConversationController::class, 'close'])->name('conversations.close');
        Route::post('/{conversation}/reopen', [ConversationController::class, 'reopen'])->name('conversations.reopen');
        Route::post('/{conversation}/toggle-handover', [ConversationController::class, 'toggleHandover'])->name('conversations.toggle-handover');
        Route::post('/{conversation}/mark-as-read', [ConversationController::class, 'markAsRead'])->name('conversations.mark-as-read');
        Route::post('/{conversation}/clear-context', [ConversationController::class, 'clearContext'])->name('conversations.clear-context');

        // Notes/Memory routes
        Route::get('/{conversation}/notes', [ConversationController::class, 'getNotes'])->name('conversations.notes.index');
        Route::post('/{conversation}/notes', [ConversationController::class, 'addNote'])->name('conversations.notes.store');
        Route::put('/{conversation}/notes/{noteId}', [ConversationController::class, 'updateNote'])->name('conversations.notes.update');
        Route::delete('/{conversation}/notes/{noteId}', [ConversationController::class, 'deleteNote'])->name('conversations.notes.destroy');

        // Tag routes
        Route::post('/{conversation}/tags', [ConversationController::class, 'addTags'])->name('conversations.tags.store');
        Route::delete('/{conversation}/tags/{tag}', [ConversationController::class, 'removeTag'])->name('conversations.tags.destroy');

        // HITL Agent message route (rate limited to prevent spam)
        Route::post('/{conversation}/agent-message', [ConversationController::class, 'sendAgentMessage'])
            ->middleware('throttle:60,1') // 60 messages per minute per user
            ->name('conversations.agent-message');
    });
});

// Health check endpoint (no rate limiting needed)
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');

// Debug endpoint - TEMPORARY for diagnosing 500 error
Route::get('/debug-schema', function () {
    try {
        $hasColumn = \Illuminate\Support\Facades\Schema::hasColumn('conversations', 'context_cleared_at');
        $migrations = \Illuminate\Support\Facades\DB::table('migrations')
            ->where('migration', 'like', '%context_cleared%')
            ->pluck('migration');

        return response()->json([
            'has_context_cleared_at' => $hasColumn,
            'related_migrations' => $migrations,
            'last_migration' => \Illuminate\Support\Facades\DB::table('migrations')->orderByDesc('id')->first(),
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], 500);
    }
});

// Debug conversations - TEMPORARY - test full controller flow
Route::get('/debug-conversations/{botId}', function ($botId, \Illuminate\Http\Request $request) {
    try {
        $bot = \App\Models\Bot::findOrFail($botId);

        // Simulate EXACT same flow as ConversationController@index
        $query = $bot->conversations()
            ->with(['customerProfile', 'assignedUser']);

        // Sorting - same as controller
        $sortField = $request->input('sort_by', 'last_message_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $allowedSortFields = ['last_message_at', 'created_at', 'message_count', 'status'];

        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('last_message_at');
        }

        // Status counts
        $statusCounts = $bot->conversations()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $conversations = $query->paginate($request->input('per_page', 20));

        // Test the EXACT resource output
        $resource = \App\Http\Resources\ConversationResource::collection($conversations)
            ->additional([
                'meta' => [
                    'status_counts' => [
                        'active' => $statusCounts['active'] ?? 0,
                        'closed' => $statusCounts['closed'] ?? 0,
                        'handover' => $statusCounts['handover'] ?? 0,
                        'total' => array_sum($statusCounts),
                    ],
                ],
            ]);

        // Force resolve to catch any serialization errors
        return $resource->toResponse($request);
    } catch (\Throwable $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => collect(explode("\n", $e->getTraceAsString()))->take(15)->toArray(),
        ], 500);
    }
});

// Broadcasting authentication endpoint
Broadcast::routes(['middleware' => ['auth:sanctum']]);

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
