<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImprovementSessionResource;
use App\Http\Resources\ImprovementSuggestionResource;
use App\Jobs\Improvement\ApplyImprovementsJob;
use App\Jobs\Improvement\RunImprovementAgentJob;
use App\Models\ActivityLog;
use App\Models\Bot;
use App\Models\Evaluation;
use App\Models\ImprovementSession;
use App\Models\ImprovementSuggestion;
use App\Services\Improvement\ImprovementAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImprovementController extends Controller
{
    public function __construct(
        protected ImprovementAgentService $agentService
    ) {}

    /**
     * List all improvement sessions for a bot
     * GET /bots/{bot}/improvement-sessions
     */
    public function index(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        $sessions = $bot->improvementSessions()
            ->with(['evaluation', 'reEvaluation'])
            ->withCount('suggestions')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 10));

        return response()->json([
            'data' => ImprovementSessionResource::collection($sessions),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
            ],
        ]);
    }

    /**
     * Start improvement session from an evaluation
     * POST /bots/{bot}/evaluations/{evaluation}/improve
     */
    public function start(Bot $bot, Evaluation $evaluation): JsonResponse
    {
        $this->authorize('view', $bot);

        // Validate evaluation is completed
        if ($evaluation->status !== Evaluation::STATUS_COMPLETED) {
            return response()->json([
                'error' => 'Evaluation must be completed before starting improvement',
            ], 422);
        }

        // Check if there's already an active session
        $activeSession = ImprovementSession::where('evaluation_id', $evaluation->id)
            ->whereNotIn('status', [
                ImprovementSession::STATUS_COMPLETED,
                ImprovementSession::STATUS_FAILED,
                ImprovementSession::STATUS_CANCELLED,
            ])
            ->first();

        if ($activeSession) {
            return response()->json([
                'error' => 'An improvement session is already in progress',
                'session' => new ImprovementSessionResource($activeSession),
            ], 409);
        }

        // Start new session
        $user = Auth::user();
        $session = $this->agentService->startSession($evaluation, $user);

        // Dispatch analysis job
        RunImprovementAgentJob::dispatch($session, $user->id);

        // Log activity
        ActivityLog::log(
            userId: $user->id,
            type: ActivityLog::TYPE_IMPROVEMENT_STARTED,
            title: 'เริ่ม AI Improvement',
            description: "วิเคราะห์จากการประเมิน #{$evaluation->id}",
            botId: $bot->id,
            metadata: ['session_id' => $session->id, 'evaluation_id' => $evaluation->id]
        );

        return response()->json([
            'message' => 'Improvement session started',
            'data' => new ImprovementSessionResource($session),
        ], 201);
    }

    /**
     * Get improvement session details
     * GET /bots/{bot}/improvement-sessions/{session}
     */
    public function show(Bot $bot, ImprovementSession $session): JsonResponse
    {
        $this->authorize('view', $bot);
        $this->validateSessionBelongsToBot($session, $bot);

        $session->load(['evaluation', 'reEvaluation', 'suggestions']);

        // Check if re-evaluation is completed
        if ($session->isReEvaluating() && $session->reEvaluation) {
            if ($session->reEvaluation->status === Evaluation::STATUS_COMPLETED) {
                $this->agentService->completeSession($session);
                $session->refresh();
            }
        }

        return response()->json([
            'data' => new ImprovementSessionResource($session),
        ]);
    }

    /**
     * List suggestions for a session
     * GET /bots/{bot}/improvement-sessions/{session}/suggestions
     */
    public function suggestions(Bot $bot, ImprovementSession $session): JsonResponse
    {
        $this->authorize('view', $bot);
        $this->validateSessionBelongsToBot($session, $bot);

        $suggestions = $session->suggestions()
            ->orderBy('priority', 'asc') // high first
            ->orderBy('confidence_score', 'desc')
            ->get();

        return response()->json([
            'data' => ImprovementSuggestionResource::collection($suggestions),
        ]);
    }

    /**
     * Toggle suggestion selection
     * PATCH /bots/{bot}/improvement-sessions/{session}/suggestions/{suggestion}
     */
    public function toggleSuggestion(
        Bot $bot,
        ImprovementSession $session,
        ImprovementSuggestion $suggestion
    ): JsonResponse {
        $this->authorize('view', $bot);
        $this->validateSessionBelongsToBot($session, $bot);

        if ($suggestion->session_id !== $session->id) {
            return response()->json([
                'error' => 'Suggestion does not belong to this session',
            ], 403);
        }

        if (!$session->isSuggestionsReady()) {
            return response()->json([
                'error' => 'Cannot modify suggestions in current session state',
            ], 422);
        }

        $suggestion->toggleSelection();

        return response()->json([
            'data' => new ImprovementSuggestionResource($suggestion),
        ]);
    }

    /**
     * Preview all changes
     * POST /bots/{bot}/improvement-sessions/{session}/preview
     */
    public function preview(Bot $bot, ImprovementSession $session): JsonResponse
    {
        $this->authorize('view', $bot);
        $this->validateSessionBelongsToBot($session, $bot);

        $selectedSuggestions = $session->getSelectedSuggestions();

        $preview = [
            'prompt_changes' => [],
            'kb_additions' => [],
            'summary' => [
                'total_selected' => $selectedSuggestions->count(),
                'prompt_updates' => 0,
                'kb_documents' => 0,
            ],
        ];

        foreach ($selectedSuggestions as $suggestion) {
            if ($suggestion->isSystemPrompt()) {
                $preview['prompt_changes'][] = [
                    'id' => $suggestion->id,
                    'title' => $suggestion->title,
                    'current' => $suggestion->current_value,
                    'suggested' => $suggestion->suggested_value,
                    'diff_summary' => $suggestion->diff_summary,
                ];
                $preview['summary']['prompt_updates']++;
            } elseif ($suggestion->isKbContent()) {
                $preview['kb_additions'][] = [
                    'id' => $suggestion->id,
                    'title' => $suggestion->kb_content_title,
                    'content' => $suggestion->kb_content_body,
                    'related_topics' => $suggestion->related_topics,
                ];
                $preview['summary']['kb_documents']++;
            }
        }

        return response()->json(['data' => $preview]);
    }

    /**
     * Apply selected suggestions
     * POST /bots/{bot}/improvement-sessions/{session}/apply
     */
    public function apply(Bot $bot, ImprovementSession $session): JsonResponse
    {
        $this->authorize('view', $bot);
        $this->validateSessionBelongsToBot($session, $bot);

        if (!$session->isSuggestionsReady()) {
            return response()->json([
                'error' => 'Session is not ready for applying changes',
            ], 422);
        }

        $selectedCount = $session->getSelectedSuggestionsCount();
        if ($selectedCount === 0) {
            return response()->json([
                'error' => 'No suggestions selected to apply',
            ], 422);
        }

        // Dispatch apply job
        $user = Auth::user();
        ApplyImprovementsJob::dispatch($session, $user->id);

        // Log activity
        ActivityLog::log(
            userId: $user->id,
            type: ActivityLog::TYPE_IMPROVEMENT_APPLIED,
            title: 'ปรับปรุง Bot',
            description: "นำ {$selectedCount} คำแนะนำไปใช้",
            botId: $bot->id,
            metadata: ['session_id' => $session->id, 'suggestions_count' => $selectedCount]
        );

        return response()->json([
            'message' => 'Applying improvements and starting re-evaluation',
            'data' => new ImprovementSessionResource($session->fresh()),
        ]);
    }

    /**
     * Cancel improvement session
     * POST /bots/{bot}/improvement-sessions/{session}/cancel
     */
    public function cancel(Bot $bot, ImprovementSession $session): JsonResponse
    {
        $this->authorize('view', $bot);
        $this->validateSessionBelongsToBot($session, $bot);

        if ($session->isCompleted() || $session->isFailed() || $session->isCancelled()) {
            return response()->json([
                'error' => 'Session cannot be cancelled in current state',
            ], 422);
        }

        $this->agentService->cancelSession($session);

        return response()->json([
            'message' => 'Improvement session cancelled',
            'data' => new ImprovementSessionResource($session->fresh()),
        ]);
    }

    /**
     * Validate that session belongs to the bot
     */
    protected function validateSessionBelongsToBot(ImprovementSession $session, Bot $bot): void
    {
        if ($session->bot_id !== $bot->id) {
            abort(403, 'Session does not belong to this bot');
        }
    }
}
