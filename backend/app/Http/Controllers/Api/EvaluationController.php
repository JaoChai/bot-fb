<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EvaluationResource;
use App\Http\Resources\EvaluationTestCaseResource;
use App\Http\Resources\EvaluationReportResource;
use App\Jobs\Evaluation\RunEvaluationJob;
use App\Models\Bot;
use App\Models\Evaluation;
use App\Services\Evaluation\EvaluationService;
use App\Services\Evaluation\PersonaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EvaluationController extends Controller
{
    public function __construct(
        protected EvaluationService $evaluationService,
        protected PersonaService $personaService
    ) {}

    /**
     * List evaluations for a bot
     */
    public function index(Request $request, Bot $bot): AnonymousResourceCollection
    {
        $this->authorize('view', $bot);

        $evaluations = $bot->evaluations()
            ->with(['flow:id,name', 'report'])
            ->when($request->flow_id, fn($q, $flowId) => $q->where('flow_id', $flowId))
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 15);

        return EvaluationResource::collection($evaluations);
    }

    /**
     * Create and start a new evaluation
     */
    public function store(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        $validated = $request->validate([
            'flow_id' => 'required|exists:flows,id',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'personas' => 'nullable|array',
            'personas.*' => 'string',
            'judge_model' => 'nullable|string',
            'test_count' => 'nullable|integer|min:10|max:100',
            'include_multi_turn' => 'nullable|boolean',
            'include_edge_cases' => 'nullable|boolean',
        ]);

        // Verify flow belongs to bot
        $flow = $bot->flows()->findOrFail($validated['flow_id']);

        // Create evaluation
        $evaluation = $this->evaluationService->createEvaluation(
            bot: $bot,
            flow: $flow,
            user: $request->user(),
            config: $validated
        );

        // Dispatch background job
        RunEvaluationJob::dispatch($evaluation, $request->user()->id);

        return response()->json([
            'message' => 'Evaluation started',
            'data' => new EvaluationResource($evaluation),
        ], 201);
    }

    /**
     * Get evaluation details
     */
    public function show(Bot $bot, Evaluation $evaluation): EvaluationResource
    {
        $this->authorize('view', $bot);
        $this->ensureEvaluationBelongsToBot($evaluation, $bot);

        $evaluation->load(['flow:id,name', 'report']);

        return new EvaluationResource($evaluation);
    }

    /**
     * Delete an evaluation
     */
    public function destroy(Bot $bot, Evaluation $evaluation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->ensureEvaluationBelongsToBot($evaluation, $bot);

        // Cannot delete running evaluations
        if ($evaluation->isRunning()) {
            return response()->json([
                'message' => 'Cannot delete a running evaluation. Cancel it first.',
            ], 422);
        }

        $evaluation->delete();

        return response()->json([
            'message' => 'Evaluation deleted',
        ]);
    }

    /**
     * Cancel a running evaluation
     */
    public function cancel(Bot $bot, Evaluation $evaluation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->ensureEvaluationBelongsToBot($evaluation, $bot);

        if (!$evaluation->isRunning()) {
            return response()->json([
                'message' => 'Evaluation is not running',
            ], 422);
        }

        $this->evaluationService->cancelEvaluation($evaluation);

        return response()->json([
            'message' => 'Evaluation cancelled',
            'data' => new EvaluationResource($evaluation->fresh()),
        ]);
    }

    /**
     * Retry a failed evaluation
     */
    public function retry(Request $request, Bot $bot, Evaluation $evaluation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->ensureEvaluationBelongsToBot($evaluation, $bot);

        if (!$evaluation->isFailed()) {
            return response()->json([
                'message' => 'Only failed evaluations can be retried',
            ], 422);
        }

        // Dispatch retry job
        RunEvaluationJob::dispatch($evaluation, $request->user()->id);

        return response()->json([
            'message' => 'Evaluation retry started',
            'data' => new EvaluationResource($evaluation->fresh()),
        ]);
    }

    /**
     * Get test cases for an evaluation
     */
    public function testCases(Request $request, Bot $bot, Evaluation $evaluation): AnonymousResourceCollection
    {
        $this->authorize('view', $bot);
        $this->ensureEvaluationBelongsToBot($evaluation, $bot);

        $testCases = $evaluation->testCases()
            ->with('messages')
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->persona_key, fn($q, $key) => $q->where('persona_key', $key))
            ->orderBy('id')
            ->paginate($request->per_page ?? 20);

        return EvaluationTestCaseResource::collection($testCases);
    }

    /**
     * Get single test case detail
     */
    public function testCaseDetail(Bot $bot, Evaluation $evaluation, int $testCaseId): EvaluationTestCaseResource
    {
        $this->authorize('view', $bot);
        $this->ensureEvaluationBelongsToBot($evaluation, $bot);

        $testCase = $evaluation->testCases()
            ->with('messages')
            ->findOrFail($testCaseId);

        return new EvaluationTestCaseResource($testCase);
    }

    /**
     * Get evaluation report
     */
    public function report(Bot $bot, Evaluation $evaluation): JsonResponse
    {
        $this->authorize('view', $bot);
        $this->ensureEvaluationBelongsToBot($evaluation, $bot);

        if (!$evaluation->isCompleted()) {
            return response()->json([
                'message' => 'Evaluation is not completed yet',
                'status' => $evaluation->status,
            ], 422);
        }

        $report = $evaluation->report;

        if (!$report) {
            return response()->json([
                'message' => 'Report not available',
            ], 404);
        }

        return response()->json([
            'data' => new EvaluationReportResource($report),
        ]);
    }

    /**
     * Get progress for a running evaluation
     */
    public function progress(Bot $bot, Evaluation $evaluation): JsonResponse
    {
        $this->authorize('view', $bot);
        $this->ensureEvaluationBelongsToBot($evaluation, $bot);

        return response()->json([
            'data' => $this->evaluationService->getProgress($evaluation),
        ]);
    }

    /**
     * Get available personas
     */
    public function personas(): JsonResponse
    {
        return response()->json([
            'data' => $this->personaService->getPersonasForDisplay(),
        ]);
    }

    /**
     * Compare multiple evaluations
     */
    public function compare(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        $validated = $request->validate([
            'evaluation_ids' => 'required|array|min:2|max:5',
            'evaluation_ids.*' => 'integer|exists:evaluations,id',
        ]);

        $evaluations = Evaluation::whereIn('id', $validated['evaluation_ids'])
            ->where('bot_id', $bot->id)
            ->where('status', Evaluation::STATUS_COMPLETED)
            ->with(['flow:id,name', 'report'])
            ->orderBy('completed_at')
            ->get();

        if ($evaluations->count() < 2) {
            return response()->json([
                'message' => 'At least 2 completed evaluations are required for comparison',
            ], 422);
        }

        $comparison = [
            'evaluations' => EvaluationResource::collection($evaluations),
            'trend' => $this->calculateTrend($evaluations),
        ];

        return response()->json(['data' => $comparison]);
    }

    /**
     * Calculate trend across evaluations
     */
    protected function calculateTrend($evaluations): array
    {
        $metrics = ['answer_relevancy', 'faithfulness', 'role_adherence', 'context_precision', 'task_completion', 'overall'];
        $trend = [];

        foreach ($metrics as $metric) {
            $key = $metric === 'overall' ? 'overall_score' : $metric;
            $values = $evaluations->map(function ($eval) use ($key) {
                if ($key === 'overall_score') {
                    return $eval->overall_score;
                }
                return $eval->metric_scores[$key] ?? null;
            })->filter()->values();

            if ($values->count() >= 2) {
                $first = $values->first();
                $last = $values->last();
                $trend[$metric] = [
                    'start' => $first,
                    'end' => $last,
                    'change' => round($last - $first, 4),
                    'direction' => $last > $first ? 'up' : ($last < $first ? 'down' : 'stable'),
                ];
            }
        }

        return $trend;
    }

    /**
     * Ensure evaluation belongs to bot
     */
    protected function ensureEvaluationBelongsToBot(Evaluation $evaluation, Bot $bot): void
    {
        if ($evaluation->bot_id !== $bot->id) {
            abort(404, 'Evaluation not found');
        }
    }
}
