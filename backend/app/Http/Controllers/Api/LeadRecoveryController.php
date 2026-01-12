<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\LeadRecoveryLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LeadRecoveryController extends Controller
{
    /**
     * Get lead recovery statistics for a bot.
     *
     * @OA\Get(
     *     path="/api/bots/{botId}/lead-recovery/stats",
     *     summary="Get lead recovery statistics",
     *     description="Returns lead recovery statistics including response rates and daily breakdown.",
     *     operationId="getLeadRecoveryStats",
     *     tags={"Lead Recovery"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="botId",
     *         in="path",
     *         description="Bot ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="period",
     *         in="query",
     *         description="Time period (day, week, month)",
     *         required=false,
     *         @OA\Schema(type="string", enum={"day", "week", "month"}, default="week")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="total_sent", type="integer"),
     *                 @OA\Property(property="total_responded", type="integer"),
     *                 @OA\Property(property="response_rate", type="number"),
     *                 @OA\Property(property="by_mode", type="object"),
     *                 @OA\Property(property="daily_breakdown", type="array", @OA\Items(type="object"))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Bot not found")
     * )
     */
    public function getStats(Request $request, int $botId): JsonResponse
    {
        try {
            $bot = Bot::findOrFail($botId);
            $this->authorize('view', $bot);

            $validated = $request->validate([
                'period' => 'sometimes|in:day,week,month',
            ]);

            $period = $validated['period'] ?? 'week';
            $dateRange = $this->getDateRange($period);

            // Get base query for the period
            $logsQuery = LeadRecoveryLog::where('bot_id', $botId)
                ->whereBetween('sent_at', [$dateRange['start'], $dateRange['end']]);

            // Calculate totals
            $totalSent = (clone $logsQuery)->count();
            $totalResponded = (clone $logsQuery)->where('customer_responded', true)->count();
            $responseRate = $totalSent > 0 ? round(($totalResponded / $totalSent) * 100, 2) : 0;

            // Breakdown by mode (static/ai)
            $byMode = (clone $logsQuery)
                ->selectRaw('message_mode, COUNT(*) as sent, SUM(CASE WHEN customer_responded = true THEN 1 ELSE 0 END) as responded')
                ->groupBy('message_mode')
                ->get()
                ->mapWithKeys(function ($item) {
                    return [
                        $item->message_mode => [
                            'sent' => (int) $item->sent,
                            'responded' => (int) $item->responded,
                            'response_rate' => $item->sent > 0
                                ? round(($item->responded / $item->sent) * 100, 2)
                                : 0,
                        ],
                    ];
                })
                ->toArray();

            // Daily breakdown
            $dailyBreakdown = (clone $logsQuery)
                ->selectRaw("DATE(sent_at) as date, COUNT(*) as sent, SUM(CASE WHEN customer_responded = true THEN 1 ELSE 0 END) as responded")
                ->groupBy(DB::raw('DATE(sent_at)'))
                ->orderBy('date')
                ->get()
                ->mapWithKeys(function ($item) {
                    return [
                        $item->date => [
                            'sent' => (int) $item->sent,
                            'responded' => (int) $item->responded,
                        ],
                    ];
                })
                ->toArray();

            return response()->json([
                'data' => [
                    'total_sent' => $totalSent,
                    'total_responded' => $totalResponded,
                    'response_rate' => $responseRate,
                    'by_mode' => $byMode,
                    'daily_breakdown' => $dailyBreakdown,
                    'period' => [
                        'type' => $period,
                        'start' => $dateRange['start']->toISOString(),
                        'end' => $dateRange['end']->toISOString(),
                    ],
                ],
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Bot not found'], 404);
        } catch (\Throwable $e) {
            Log::error('LeadRecoveryController::getStats error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    /**
     * Get paginated lead recovery logs for a bot.
     *
     * @OA\Get(
     *     path="/api/bots/{botId}/lead-recovery/logs",
     *     summary="Get lead recovery logs",
     *     description="Returns paginated lead recovery logs with customer information.",
     *     operationId="getLeadRecoveryLogs",
     *     tags={"Lead Recovery"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="botId",
     *         in="path",
     *         description="Bot ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         required=false,
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Items per page (max 100)",
     *         required=false,
     *         @OA\Schema(type="integer", default=20)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="current_page", type="integer"),
     *                 @OA\Property(property="per_page", type="integer"),
     *                 @OA\Property(property="total", type="integer"),
     *                 @OA\Property(property="last_page", type="integer")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Bot not found")
     * )
     */
    public function getLogs(Request $request, int $botId): JsonResponse
    {
        try {
            $bot = Bot::findOrFail($botId);
            $this->authorize('view', $bot);

            $validated = $request->validate([
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
            ]);

            $perPage = $validated['per_page'] ?? 20;

            $logs = LeadRecoveryLog::where('bot_id', $botId)
                ->with(['conversation.customerProfile'])
                ->orderByDesc('sent_at')
                ->paginate($perPage);

            $transformedLogs = $logs->getCollection()->map(function ($log) {
                $customer = $log->conversation?->customerProfile;

                return [
                    'id' => $log->id,
                    'conversation_id' => $log->conversation_id,
                    'attempt_number' => $log->attempt_number,
                    'message_mode' => $log->message_mode,
                    'message_sent' => $log->message_sent,
                    'sent_at' => $log->sent_at?->toISOString(),
                    'delivery_status' => $log->delivery_status,
                    'customer_responded' => $log->customer_responded,
                    'responded_at' => $log->responded_at?->toISOString(),
                    'customer' => $customer ? [
                        'name' => $customer->display_name,
                        'external_id' => $customer->external_id,
                    ] : null,
                ];
            });

            return response()->json([
                'data' => $transformedLogs,
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                    'last_page' => $logs->lastPage(),
                ],
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Bot not found'], 404);
        } catch (\Throwable $e) {
            Log::error('LeadRecoveryController::getLogs error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    /**
     * Get date range based on period type.
     */
    private function getDateRange(string $period): array
    {
        $end = Carbon::now();

        $start = match ($period) {
            'day' => Carbon::now()->startOfDay(),
            'week' => Carbon::now()->subDays(7)->startOfDay(),
            'month' => Carbon::now()->subDays(30)->startOfDay(),
            default => Carbon::now()->subDays(7)->startOfDay(),
        };

        return [
            'start' => $start,
            'end' => $end,
        ];
    }
}
