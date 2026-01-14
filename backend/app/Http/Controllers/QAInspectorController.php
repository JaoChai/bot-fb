<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyPromptSuggestionRequest;
use App\Http\Requests\UpdateQAInspectorSettingsRequest;
use App\Http\Resources\QAEvaluationLogDetailResource;
use App\Http\Resources\QAEvaluationLogResource;
use App\Http\Resources\QAInspectorSettingsResource;
use App\Http\Resources\QAWeeklyReportDetailResource;
use App\Http\Resources\QAWeeklyReportResource;
use App\Jobs\GenerateWeeklyReportJob;
use App\Models\Bot;
use App\Models\Flow;
use App\Models\QAEvaluationLog;
use App\Models\QAWeeklyReport;
use App\Services\QAInspector\PromptSuggestionApplier;
use App\Services\QAInspector\QAInspectorService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class QAInspectorController extends Controller
{
    public function __construct(
        private QAInspectorService $qaInspectorService,
        private PromptSuggestionApplier $promptSuggestionApplier,
    ) {}

    /**
     * Get QA Inspector settings for a bot
     */
    public function getSettings(Bot $bot): QAInspectorSettingsResource
    {
        $this->authorize('view', $bot);

        return new QAInspectorSettingsResource($bot);
    }

    /**
     * Update QA Inspector settings for a bot
     */
    public function updateSettings(UpdateQAInspectorSettingsRequest $request, Bot $bot): QAInspectorSettingsResource
    {
        $this->authorize('update', $bot);

        $bot->update($request->validated());
        $bot->refresh();

        // Broadcast settings update for realtime sync
        event(new \App\Events\BotSettingsUpdated($bot, 'qa_inspector'));

        return new QAInspectorSettingsResource($bot);
    }

    /**
     * List evaluation logs for a bot
     * GET /bots/{bot}/qa-inspector/logs
     */
    public function getLogs(Request $request, Bot $bot): AnonymousResourceCollection
    {
        $this->authorize('view', $bot);

        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'is_flagged' => ['nullable', 'boolean'],
            'issue_type' => ['nullable', 'string', 'max:50'],
            'min_score' => ['nullable', 'numeric', 'between:0,1'],
            'max_score' => ['nullable', 'numeric', 'between:0,1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = QAEvaluationLog::byBot($bot->id)
            ->with(['conversation', 'message', 'flow'])
            ->orderBy('created_at', 'desc');

        // Apply filters
        if ($request->has('is_flagged')) {
            $query->where('is_flagged', $request->boolean('is_flagged'));
        }

        if ($request->filled('issue_type')) {
            $query->where('issue_type', $request->input('issue_type'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to') . ' 23:59:59');
        }

        if ($request->filled('min_score')) {
            $query->where('overall_score', '>=', $request->input('min_score'));
        }

        if ($request->filled('max_score')) {
            $query->where('overall_score', '<=', $request->input('max_score'));
        }

        $perPage = min($request->integer('per_page', 20), 100);

        return QAEvaluationLogResource::collection($query->paginate($perPage));
    }

    /**
     * Get single evaluation log detail
     * GET /bots/{bot}/qa-inspector/logs/{log}
     */
    public function getLog(Bot $bot, QAEvaluationLog $log): QAEvaluationLogDetailResource
    {
        $this->authorize('view', $bot);

        // Ensure log belongs to this bot
        if ($log->bot_id !== $bot->id) {
            abort(404);
        }

        return new QAEvaluationLogDetailResource($log->load(['conversation', 'message', 'flow']));
    }

    /**
     * Get dashboard stats
     * GET /bots/{bot}/qa-inspector/stats
     */
    public function getStats(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        $period = $request->input('period', '7d');
        $stats = $this->qaInspectorService->calculateDashboardStats($bot, $period);

        return response()->json(['data' => $stats]);
    }

    /**
     * List weekly reports for a bot
     * GET /bots/{bot}/qa-inspector/reports
     */
    public function getReports(Request $request, Bot $bot): AnonymousResourceCollection
    {
        $this->authorize('view', $bot);

        $reports = QAWeeklyReport::byBot($bot->id)
            ->latest('week_start')
            ->paginate($request->integer('per_page', 10));

        return QAWeeklyReportResource::collection($reports);
    }

    /**
     * Get single weekly report detail
     * GET /bots/{bot}/qa-inspector/reports/{report}
     */
    public function getReport(Bot $bot, QAWeeklyReport $report): QAWeeklyReportDetailResource
    {
        $this->authorize('view', $bot);

        if ($report->bot_id !== $bot->id) {
            abort(404);
        }

        return new QAWeeklyReportDetailResource($report);
    }

    /**
     * Manually trigger report generation
     * POST /bots/{bot}/qa-inspector/reports/generate
     */
    public function generateReport(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        $request->validate([
            'week_start' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $weekStart = $request->filled('week_start')
            ? Carbon::parse($request->input('week_start'))->startOfWeek()
            : Carbon::now()->subWeek()->startOfWeek();

        // Check if report already exists and is generating
        $existing = QAWeeklyReport::byBot($bot->id)
            ->byWeek($weekStart->toDateString())
            ->first();

        if ($existing && $existing->status === QAWeeklyReport::STATUS_GENERATING) {
            return response()->json([
                'data' => [
                    'report_id' => $existing->id,
                    'status' => 'generating',
                    'message' => 'Report is already being generated.',
                ],
            ]);
        }

        // Create placeholder record
        $report = QAWeeklyReport::updateOrCreate(
            ['bot_id' => $bot->id, 'week_start' => $weekStart->toDateString()],
            [
                'week_end' => $weekStart->copy()->endOfWeek()->toDateString(),
                'status' => QAWeeklyReport::STATUS_GENERATING,
            ]
        );

        // Dispatch job
        GenerateWeeklyReportJob::dispatch($bot, $weekStart);

        return response()->json([
            'data' => [
                'report_id' => $report->id,
                'status' => 'generating',
                'message' => 'Report generation started. You will be notified when complete.',
            ],
        ], 202);
    }

    /**
     * Apply a prompt suggestion from a weekly report to a flow
     * POST /bots/{bot}/qa-inspector/reports/{report}/suggestions/{index}/apply
     */
    public function applySuggestion(
        ApplyPromptSuggestionRequest $request,
        Bot $bot,
        QAWeeklyReport $report,
        int $index
    ): JsonResponse {
        $this->authorize('update', $bot);

        // Ensure report belongs to this bot
        if ($report->bot_id !== $bot->id) {
            abort(404);
        }

        // Get the flow (already validated by FormRequest to belong to bot)
        $flow = Flow::findOrFail($request->input('flow_id'));

        // Apply the suggestion
        $result = $this->promptSuggestionApplier->apply(
            $report,
            $index,
            $flow,
            $request->shouldForce()
        );

        // Return conflict response with 409 status
        if (isset($result['conflict']) && $result['conflict']) {
            return response()->json([
                'data' => [
                    'conflict' => true,
                    'message' => $result['message'],
                    'expected' => $result['expected'] ?? null,
                    'actual' => $result['actual'] ?? null,
                    'can_force' => $result['can_force'] ?? false,
                ],
            ], 409);
        }

        // Return error response
        if (!$result['success']) {
            return response()->json([
                'data' => [
                    'success' => false,
                    'message' => $result['message'],
                    'error_code' => $result['error_code'] ?? null,
                ],
            ], 422);
        }

        // Return success response
        return response()->json([
            'data' => [
                'success' => true,
                'message' => $result['message'],
                'flow_id' => $result['flow_id'],
                'updated_at' => $result['updated_at'],
                'force_applied' => $result['force_applied'] ?? false,
            ],
        ]);
    }
}
