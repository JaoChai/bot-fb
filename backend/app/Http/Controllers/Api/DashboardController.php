<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Evaluation;
use App\Models\ImprovementSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard summary data
     * GET /api/dashboard/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $botIds = Bot::where('user_id', $user->id)->pluck('id');

        // Get aggregated summary using CTE for efficiency
        $summaryStats = $this->getSummaryStats($botIds);

        // Get bots with their metrics
        $bots = $this->getBotsWithMetrics($user->id);

        // Get alerts
        $alerts = $this->getAlerts($user->id, $botIds);

        // Get recent activity
        $recentActivity = ActivityLog::getRecentForUser($user->id, 10);

        return response()->json([
            'data' => [
                'summary' => $summaryStats,
                'bots' => $bots,
                'alerts' => $alerts,
                'recent_activity' => $recentActivity->map(fn ($activity) => [
                    'id' => $activity->id,
                    'type' => $activity->type,
                    'title' => $activity->title,
                    'description' => $activity->description,
                    'bot_id' => $activity->bot_id,
                    'bot_name' => $activity->bot?->name,
                    'metadata' => $activity->metadata,
                    'created_at' => $activity->created_at->toISOString(),
                ]),
            ],
        ]);
    }

    /**
     * Get aggregated summary statistics
     */
    protected function getSummaryStats($botIds): array
    {
        if ($botIds->isEmpty()) {
            return [
                'total_bots' => 0,
                'active_bots' => 0,
                'total_conversations' => 0,
                'active_conversations' => 0,
                'handover_conversations' => 0,
                'messages_today' => 0,
            ];
        }

        $botIdsStr = $botIds->implode(',');

        $stats = DB::selectOne("
            WITH bot_stats AS (
                SELECT
                    COUNT(*) as total_bots,
                    COUNT(*) FILTER (WHERE status = 'active') as active_bots
                FROM bots
                WHERE id IN ({$botIdsStr}) AND deleted_at IS NULL
            ),
            conv_stats AS (
                SELECT
                    COUNT(*) as total_conversations,
                    COUNT(*) FILTER (WHERE status = 'active') as active_conversations,
                    COUNT(*) FILTER (WHERE status = 'handover') as handover_conversations
                FROM conversations
                WHERE bot_id IN ({$botIdsStr}) AND deleted_at IS NULL
            ),
            msg_today AS (
                SELECT COUNT(*) as messages_today
                FROM messages m
                INNER JOIN conversations c ON m.conversation_id = c.id
                WHERE c.bot_id IN ({$botIdsStr})
                    AND c.deleted_at IS NULL
                    AND m.created_at >= CURRENT_DATE
                    AND m.created_at < CURRENT_DATE + INTERVAL '1 day'
            )
            SELECT
                bs.total_bots,
                bs.active_bots,
                cs.total_conversations,
                cs.active_conversations,
                cs.handover_conversations,
                mt.messages_today
            FROM bot_stats bs
            CROSS JOIN conv_stats cs
            CROSS JOIN msg_today mt
        ");

        return [
            'total_bots' => (int) ($stats->total_bots ?? 0),
            'active_bots' => (int) ($stats->active_bots ?? 0),
            'total_conversations' => (int) ($stats->total_conversations ?? 0),
            'active_conversations' => (int) ($stats->active_conversations ?? 0),
            'handover_conversations' => (int) ($stats->handover_conversations ?? 0),
            'messages_today' => (int) ($stats->messages_today ?? 0),
        ];
    }

    /**
     * Get bots with their metrics
     */
    protected function getBotsWithMetrics(int $userId): array
    {
        $bots = Bot::where('user_id', $userId)
            ->withCount([
                'conversations',
                'conversations as active_conversations_count' => fn ($q) => $q->where('status', 'active'),
                'conversations as handover_count' => fn ($q) => $q->where('status', 'handover'),
            ])
            ->with(['evaluations' => fn ($q) => $q->latest('completed_at')->limit(1)])
            ->get();

        return $bots->map(function ($bot) {
            // Get messages today for this bot
            $messagesToday = DB::selectOne("
                SELECT COUNT(*) as count
                FROM messages m
                INNER JOIN conversations c ON m.conversation_id = c.id
                WHERE c.bot_id = ?
                    AND c.deleted_at IS NULL
                    AND m.created_at >= CURRENT_DATE
                    AND m.created_at < CURRENT_DATE + INTERVAL '1 day'
            ", [$bot->id]);

            $latestEval = $bot->evaluations->first();

            return [
                'id' => $bot->id,
                'name' => $bot->name,
                'status' => $bot->status,
                'channel_type' => $bot->channel_type,
                'last_active_at' => $bot->last_active_at?->toISOString(),
                'conversation_count' => $bot->conversations_count,
                'active_conversations' => $bot->active_conversations_count,
                'handover_count' => $bot->handover_count,
                'messages_today' => (int) ($messagesToday->count ?? 0),
                'latest_evaluation' => $latestEval ? [
                    'id' => $latestEval->id,
                    'overall_score' => $latestEval->overall_score,
                    'status' => $latestEval->status,
                    'completed_at' => $latestEval->completed_at?->toISOString(),
                ] : null,
            ];
        })->toArray();
    }

    /**
     * Get alerts (handover conversations, running evaluations, pending improvements)
     */
    protected function getAlerts(int $userId, $botIds): array
    {
        if ($botIds->isEmpty()) {
            return [
                'handover_conversations' => [],
                'running_evaluations' => [],
                'pending_improvements' => [],
            ];
        }

        // Handover conversations
        $handoverConversations = Conversation::whereIn('bot_id', $botIds)
            ->where('status', 'handover')
            ->with(['bot:id,name', 'customerProfile:id,display_name'])
            ->orderBy('updated_at', 'asc') // Oldest waiting first
            ->limit(5)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'bot_id' => $c->bot_id,
                'bot_name' => $c->bot->name,
                'customer_name' => $c->customerProfile?->display_name ?? $c->external_customer_id,
                'waiting_since' => $c->updated_at->toISOString(),
            ]);

        // Running evaluations
        $runningEvaluations = Evaluation::whereIn('bot_id', $botIds)
            ->whereIn('status', [
                Evaluation::STATUS_PENDING,
                Evaluation::STATUS_GENERATING_TESTS,
                Evaluation::STATUS_RUNNING,
                Evaluation::STATUS_EVALUATING,
            ])
            ->with('bot:id,name')
            ->orderBy('created_at', 'asc')
            ->limit(5)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'bot_id' => $e->bot_id,
                'bot_name' => $e->bot->name,
                'status' => $e->status,
                'progress_percent' => $e->total_test_cases > 0
                    ? round(($e->completed_test_cases / $e->total_test_cases) * 100)
                    : 0,
                'name' => $e->name ?? "Evaluation #{$e->id}",
            ]);

        // Pending improvements (suggestions ready)
        $pendingImprovements = ImprovementSession::whereIn('bot_id', $botIds)
            ->where('status', ImprovementSession::STATUS_SUGGESTIONS_READY)
            ->with('bot:id,name')
            ->withCount('suggestions')
            ->orderBy('created_at', 'asc')
            ->limit(5)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'bot_id' => $s->bot_id,
                'bot_name' => $s->bot->name,
                'status' => $s->status,
                'suggestions_count' => $s->suggestions_count,
            ]);

        return [
            'handover_conversations' => $handoverConversations,
            'running_evaluations' => $runningEvaluations,
            'pending_improvements' => $pendingImprovements,
        ];
    }
}
