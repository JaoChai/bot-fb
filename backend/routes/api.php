<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BotController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\BotSettingController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FlowController;
use App\Http\Controllers\Api\KnowledgeBaseController;
use App\Http\Controllers\Api\StreamController;
use App\Http\Controllers\Api\UserSearchController;
use App\Http\Controllers\Api\UserSettingController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\AgentApprovalController;
use App\Http\Controllers\Api\ImprovementController;
use App\Http\Controllers\Api\QuickReplyController;
use Illuminate\Http\Request;
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

    // Debug protected endpoint
    Route::get('/debug-auth', function (\Illuminate\Http\Request $request) {
        try {
            $user = $request->user();
            $bots = $user->bots()->with(['settings', 'defaultFlow'])->get();
            $resource = \App\Http\Resources\BotResource::collection($bots);
            $data = $resource->response()->getData(true);

            return response()->json([
                'status' => 'ok',
                'user_id' => $user->id,
                'bot_count' => $bots->count(),
                'resource_ok' => true,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    });

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

    // Dashboard routes
    Route::prefix('dashboard')->group(function () {
        Route::get('/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
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
    });

    // Flow templates (not nested)
    Route::get('/flow-templates', [FlowController::class, 'templates'])->name('flows.templates');

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

        // Media upload for agent messages
        Route::post('/{conversation}/upload', [ConversationController::class, 'uploadMedia'])
            ->middleware('throttle:30,1') // 30 uploads per minute
            ->name('conversations.upload');

        // Conversation assignment routes
        Route::post('/{conversation}/assign', [ConversationController::class, 'assign'])->name('conversations.assign');
        Route::post('/{conversation}/claim', [ConversationController::class, 'claim'])->name('conversations.claim');
        Route::post('/{conversation}/unassign', [ConversationController::class, 'unassign'])->name('conversations.unassign');
    });

    // Evaluation routes (nested under bots)
    Route::prefix('bots/{bot}/evaluations')->group(function () {
        Route::get('/', [EvaluationController::class, 'index'])->name('evaluations.index');
        Route::post('/', [EvaluationController::class, 'store'])->name('evaluations.store');
        Route::get('/{evaluation}', [EvaluationController::class, 'show'])->name('evaluations.show');
        Route::delete('/{evaluation}', [EvaluationController::class, 'destroy'])->name('evaluations.destroy');
        Route::post('/{evaluation}/cancel', [EvaluationController::class, 'cancel'])->name('evaluations.cancel');
        Route::post('/{evaluation}/retry', [EvaluationController::class, 'retry'])->name('evaluations.retry');
        Route::get('/{evaluation}/progress', [EvaluationController::class, 'progress'])->name('evaluations.progress');
        Route::get('/{evaluation}/test-cases', [EvaluationController::class, 'testCases'])->name('evaluations.test-cases');
        Route::get('/{evaluation}/test-cases/{testCase}', [EvaluationController::class, 'testCaseDetail'])->name('evaluations.test-case-detail');
        Route::get('/{evaluation}/report', [EvaluationController::class, 'report'])->name('evaluations.report');
        Route::get('/compare', [EvaluationController::class, 'compare'])->name('evaluations.compare');
    });

    // Evaluation personas (shared across all bots)
    Route::get('/evaluation-personas', [EvaluationController::class, 'personas'])->name('evaluations.personas');

    // Improvement Agent routes (nested under bots)
    Route::post('bots/{bot}/evaluations/{evaluation}/improve', [ImprovementController::class, 'start'])
        ->name('improvements.start');

    Route::prefix('bots/{bot}/improvement-sessions')->group(function () {
        Route::get('/', [ImprovementController::class, 'index'])->name('improvements.index');
        Route::get('/{session}', [ImprovementController::class, 'show'])->name('improvements.show');
        Route::get('/{session}/suggestions', [ImprovementController::class, 'suggestions'])->name('improvements.suggestions');
        Route::patch('/{session}/suggestions/{suggestion}', [ImprovementController::class, 'toggleSuggestion'])
            ->name('improvements.toggle-suggestion');
        Route::post('/{session}/preview', [ImprovementController::class, 'preview'])->name('improvements.preview');
        Route::post('/{session}/apply', [ImprovementController::class, 'apply'])->name('improvements.apply');
        Route::post('/{session}/cancel', [ImprovementController::class, 'cancel'])->name('improvements.cancel');
    });

    // Agent approval routes (HITL - Human-in-the-Loop)
    Route::prefix('agent-approvals')->group(function () {
        Route::get('/{approvalId}', [AgentApprovalController::class, 'show'])->name('agent-approvals.show');
        Route::post('/{approvalId}/approve', [AgentApprovalController::class, 'approve'])->name('agent-approvals.approve');
        Route::post('/{approvalId}/reject', [AgentApprovalController::class, 'reject'])->name('agent-approvals.reject');
    });
});

// Health check endpoint (no rate limiting needed)
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');

// Temporary debug endpoint for KB search
Route::get('/debug-kb-search', function (\Illuminate\Http\Request $request) {
    try {
        $kbId = $request->query('kb_id', 5);
        $query = $request->query('query', 'Nolimit Level Up');
        $apiKey = $request->query('api_key');

        // Get KB info
        $kb = \App\Models\KnowledgeBase::find($kbId);
        if (!$kb) {
            return response()->json(['error' => 'KB not found']);
        }

        // Get documents
        $documents = \App\Models\Document::where('knowledge_base_id', $kbId)
            ->select('id', 'original_filename', 'status')
            ->get();

        // Get chunks with embeddings
        $chunks = \App\Models\DocumentChunk::query()
            ->join('documents', 'documents.id', '=', 'document_chunks.document_id')
            ->where('documents.knowledge_base_id', $kbId)
            ->where('documents.status', 'completed')
            ->select([
                'document_chunks.id',
                'document_chunks.document_id',
                'document_chunks.chunk_index',
                \Illuminate\Support\Facades\DB::raw('LENGTH(document_chunks.content) as content_length'),
                \Illuminate\Support\Facades\DB::raw('document_chunks.embedding IS NOT NULL as has_embedding'),
            ])
            ->get();

        // Try search if we have API key
        $searchResults = null;
        $embeddingError = null;
        if ($apiKey || config('services.openrouter.api_key')) {
            try {
                $embeddingService = app(\App\Services\EmbeddingService::class);
                if ($apiKey) {
                    $embeddingService = $embeddingService->withApiKey($apiKey);
                }

                // Generate embedding for query
                $queryEmbedding = $embeddingService->generate($query);

                // Perform semantic search
                $searchService = app(\App\Services\SemanticSearchService::class);
                $searchResults = $searchService->search($kbId, $query, 5, 0.5, $apiKey);
            } catch (\Throwable $e) {
                $embeddingError = $e->getMessage();
            }
        }

        return response()->json([
            'kb' => ['id' => $kb->id, 'name' => $kb->name],
            'documents' => $documents,
            'chunks' => $chunks,
            'query' => $query,
            'search_results' => $searchResults,
            'embedding_error' => $embeddingError,
            'has_env_api_key' => !empty(config('services.openrouter.api_key')),
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], 500);
    }
});

// Temporary debug endpoint for /api/bots 500 error
Route::get('/debug-bots', function () {
    try {
        // Test database connection
        \Illuminate\Support\Facades\DB::connection()->getPdo();

        // Test Bot model with relationships
        $bot = \App\Models\Bot::with(['settings', 'defaultFlow'])->first();

        // Test BotResource
        $resourceJson = null;
        if ($bot) {
            $resource = new \App\Http\Resources\BotResource($bot);
            $resourceJson = $resource->response()->getData(true);
        }

        return response()->json([
            'status' => 'ok',
            'bot_count' => \App\Models\Bot::count(),
            'db_connection' => 'ok',
            'relationships' => $bot ? [
                'has_settings' => $bot->settings !== null,
                'has_default_flow' => $bot->defaultFlow !== null,
            ] : null,
            'resource_test' => $resourceJson ? 'ok' : 'no_bots',
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => collect(explode("\n", $e->getTraceAsString()))->take(10)->all()
        ], 500);
    }
});

// Debug webhook URL matching
Route::get('/debug-webhook', function () {
    $token = 'X9Xeof5z7rBiQObZlx8LFiAG8m0rLWZF';
    $appUrl = config('app.url');
    $expectedUrl = $appUrl . '/webhook/' . $token;
    $bot = \App\Models\Bot::where('channel_type', 'line')->first();

    return response()->json([
        'app_url' => $appUrl,
        'expected_webhook_url' => $expectedUrl,
        'bot_webhook_url' => $bot?->webhook_url,
        'match' => $expectedUrl === $bot?->webhook_url,
        'bot_id' => $bot?->id,
        'bot_name' => $bot?->name,
    ]);
});

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

// TEMP: Debug endpoint for Decision Model testing (remove after debugging)
Route::post('/debug/decision-model/{botId}', function (Request $request, int $botId) {
    $bot = \App\Models\Bot::find($botId);
    if (!$bot) {
        return response()->json(['error' => 'Bot not found'], 404);
    }

    $message = $request->input('message', 'สวัสดีครับ');
    $decisionModel = $bot->decision_model;
    $fallbackDecisionModel = $bot->fallback_decision_model;

    if (empty($decisionModel)) {
        return response()->json(['error' => 'No decision model configured', 'bot_id' => $botId]);
    }

    $openRouter = app(\App\Services\OpenRouterService::class);
    $hasKB = $bot->kb_enabled && $bot->knowledgeBase;
    $kbNote = $hasKB ? ' (Knowledge Base available for factual queries)' : '';

    $prompt = <<<PROMPT
You are an intent classifier. Analyze the user's message and determine the appropriate intent.
Respond with JSON only: {"intent": "chat|knowledge", "confidence": 0.0-1.0}

Available intents:
- "chat": General conversation, greetings, opinions, casual talk, or when unsure
- "knowledge": Questions requiring factual information, specific data, or documentation{$kbNote}

Classification rules:
- Use "knowledge" for: questions about facts, how-to queries, data lookups, technical questions
- Use "chat" for: greetings (hi, hello), opinions, casual conversation, follow-up responses
- When uncertain, prefer "chat" (safer default)

Respond with JSON only, no explanation.
PROMPT;

    try {
        $result = $openRouter->chat(
            messages: [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $message],
            ],
            model: $decisionModel,
            temperature: 0.1,
            maxTokens: 150,
            useFallback: true,
            fallbackModelOverride: $fallbackDecisionModel
        );

        return response()->json([
            'success' => true,
            'bot_id' => $botId,
            'decision_model' => $decisionModel,
            'message' => $message,
            'raw_response' => $result,
            'content' => $result['content'] ?? null,
            'content_length' => strlen($result['content'] ?? ''),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'bot_id' => $botId,
            'decision_model' => $decisionModel,
        ], 500);
    }
})->name('debug.decision-model');
