<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /**
     * GET /api/orders
     * Paginated list with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $botIds = $user->bots()->pluck('id');

        $validated = $request->validate([
            'bot_id' => ['sometimes', 'integer', Rule::in($botIds)],
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date',
            'status' => 'sometimes|string',
            'category' => 'sometimes|string',
            'customer_profile_id' => 'sometimes|integer',
            'search' => 'sometimes|string|max:255',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = Order::whereIn('bot_id', $botIds)
            ->with(['items', 'customerProfile:id,display_name,picture_url']);

        if (isset($validated['bot_id'])) {
            $query->where('bot_id', $validated['bot_id']);
        }

        if (isset($validated['start_date'])) {
            $query->where('created_at', '>=', $validated['start_date']);
        }

        if (isset($validated['end_date'])) {
            $endDate = str_contains($validated['end_date'], ' ')
                ? $validated['end_date']
                : $validated['end_date'] . ' 23:59:59';
            $query->where('created_at', '<=', $endDate);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['customer_profile_id'])) {
            $query->where('customer_profile_id', $validated['customer_profile_id']);
        }

        if (isset($validated['category'])) {
            $query->whereHas('items', fn ($q) => $q->where('category', $validated['category']));
        }

        if (isset($validated['search'])) {
            $search = $validated['search'];
            $query->whereHas('items', fn ($q) => $q->where('product_name', 'ilike', "%{$search}%"));
        }

        $orders = $query->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json($orders);
    }

    /**
     * GET /api/orders/summary
     * Aggregated summary with time series.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $botIds = $user->bots()->pluck('id');

        $validated = $request->validate([
            'bot_id' => ['sometimes', 'integer', Rule::in($botIds)],
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date',
        ]);

        $botFilter = isset($validated['bot_id']) ? [$validated['bot_id']] : $botIds->toArray();
        $startDate = $validated['start_date'] ?? now()->subDays(30)->startOfDay()->toDateTimeString();
        $endDate = $validated['end_date'] ?? now()->endOfDay()->toDateTimeString();
        if (!str_contains($endDate, ' ')) {
            $endDate .= ' 23:59:59';
        }

        $cacheKey = sprintf(
            'orders:summary:%d:%s',
            $user->id,
            md5(json_encode([$botFilter, $startDate, $endDate]))
        );

        $data = Cache::remember($cacheKey, 300, function () use ($botFilter, $startDate, $endDate) {
            if (empty($botFilter)) {
                return [
                    'summary' => [
                        'total_orders' => 0,
                        'total_revenue' => 0,
                        'today_orders' => 0,
                        'today_revenue' => 0,
                        'this_week_orders' => 0,
                        'this_week_revenue' => 0,
                        'this_month_orders' => 0,
                        'this_month_revenue' => 0,
                    ],
                    'time_series' => [],
                ];
            }

            $placeholders = implode(',', array_fill(0, count($botFilter), '?'));

            $stats = DB::selectOne("
                WITH order_data AS (
                    SELECT id, total_amount, created_at
                    FROM orders
                    WHERE bot_id IN ({$placeholders})
                ),
                filtered AS (
                    SELECT * FROM order_data
                    WHERE created_at BETWEEN ? AND ?
                ),
                quick_stats AS (
                    SELECT
                        COUNT(*) FILTER (WHERE DATE(created_at) = CURRENT_DATE) as today_orders,
                        COALESCE(SUM(total_amount) FILTER (WHERE DATE(created_at) = CURRENT_DATE), 0) as today_revenue,
                        COUNT(*) FILTER (WHERE created_at >= DATE_TRUNC('week', CURRENT_DATE)) as this_week_orders,
                        COALESCE(SUM(total_amount) FILTER (WHERE created_at >= DATE_TRUNC('week', CURRENT_DATE)), 0) as this_week_revenue,
                        COUNT(*) FILTER (WHERE created_at >= DATE_TRUNC('month', CURRENT_DATE)) as this_month_orders,
                        COALESCE(SUM(total_amount) FILTER (WHERE created_at >= DATE_TRUNC('month', CURRENT_DATE)), 0) as this_month_revenue
                    FROM order_data
                )
                SELECT
                    (SELECT COUNT(*) FROM filtered) as total_orders,
                    (SELECT COALESCE(SUM(total_amount), 0) FROM filtered) as total_revenue,
                    qs.today_orders,
                    qs.today_revenue,
                    qs.this_week_orders,
                    qs.this_week_revenue,
                    qs.this_month_orders,
                    qs.this_month_revenue
                FROM quick_stats qs
            ", [...$botFilter, $startDate, $endDate]);

            // Time series: daily aggregation
            $timeSeries = DB::select("
                SELECT
                    TO_CHAR(created_at, 'YYYY-MM-DD') as date,
                    COUNT(*) as orders,
                    COALESCE(SUM(total_amount), 0) as revenue
                FROM orders
                WHERE bot_id IN ({$placeholders})
                    AND created_at BETWEEN ? AND ?
                GROUP BY TO_CHAR(created_at, 'YYYY-MM-DD')
                ORDER BY date
            ", [...$botFilter, $startDate, $endDate]);

            return [
                'summary' => [
                    'total_orders' => (int) ($stats->total_orders ?? 0),
                    'total_revenue' => (float) ($stats->total_revenue ?? 0),
                    'today_orders' => (int) ($stats->today_orders ?? 0),
                    'today_revenue' => (float) ($stats->today_revenue ?? 0),
                    'this_week_orders' => (int) ($stats->this_week_orders ?? 0),
                    'this_week_revenue' => (float) ($stats->this_week_revenue ?? 0),
                    'this_month_orders' => (int) ($stats->this_month_orders ?? 0),
                    'this_month_revenue' => (float) ($stats->this_month_revenue ?? 0),
                ],
                'time_series' => array_map(fn ($row) => [
                    'date' => $row->date,
                    'orders' => (int) $row->orders,
                    'revenue' => (float) $row->revenue,
                ], $timeSeries),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/orders/by-customer
     * Customer breakdown.
     */
    public function byCustomer(Request $request): JsonResponse
    {
        $user = $request->user();
        $botIds = $user->bots()->pluck('id');

        $validated = $request->validate([
            'bot_id' => ['sometimes', 'integer', Rule::in($botIds)],
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date',
        ]);

        $botFilter = isset($validated['bot_id']) ? [$validated['bot_id']] : $botIds->toArray();
        $startDate = $validated['start_date'] ?? now()->subDays(30)->startOfDay()->toDateTimeString();
        $endDate = $validated['end_date'] ?? now()->endOfDay()->toDateTimeString();
        if (!str_contains($endDate, ' ')) {
            $endDate .= ' 23:59:59';
        }

        $cacheKey = sprintf(
            'orders:by-customer:%d:%s',
            $user->id,
            md5(json_encode([$botFilter, $startDate, $endDate]))
        );

        $data = Cache::remember($cacheKey, 300, function () use ($botFilter, $startDate, $endDate) {
            return Order::query()
                ->select([
                    'customer_profile_id',
                    'customer_profiles.display_name',
                    'customer_profiles.picture_url',
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('COALESCE(SUM(total_amount), 0) as total_spent'),
                    DB::raw('MAX(orders.created_at) as last_order_at'),
                    DB::raw("BOOL_OR(conversations.memory_notes::text ILIKE '%VIP%') as is_vip"),
                ])
                ->join('customer_profiles', 'orders.customer_profile_id', '=', 'customer_profiles.id')
                ->leftJoin('conversations', 'conversations.customer_profile_id', '=', 'customer_profiles.id')
                ->whereIn('orders.bot_id', $botFilter)
                ->whereBetween('orders.created_at', [$startDate, $endDate])
                ->whereNotNull('customer_profile_id')
                ->groupBy('customer_profile_id', 'customer_profiles.display_name', 'customer_profiles.picture_url')
                ->orderByDesc('is_vip')
                ->orderByDesc('total_spent')
                ->limit(100)
                ->get()
                ->map(fn ($row) => [
                    'customer_profile_id' => $row->customer_profile_id,
                    'customer_name' => $row->display_name ?? 'Unknown',
                    'picture_url' => $row->picture_url,
                    'order_count' => (int) $row->order_count,
                    'total_spent' => (float) $row->total_spent,
                    'last_order_at' => $row->last_order_at,
                    'is_vip' => (bool) $row->is_vip,
                ]);
        });

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/orders/by-product
     * Product breakdown.
     */
    public function byProduct(Request $request): JsonResponse
    {
        $user = $request->user();
        $botIds = $user->bots()->pluck('id');

        $validated = $request->validate([
            'bot_id' => ['sometimes', 'integer', Rule::in($botIds)],
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date',
        ]);

        $botFilter = isset($validated['bot_id']) ? [$validated['bot_id']] : $botIds->toArray();
        $startDate = $validated['start_date'] ?? now()->subDays(30)->startOfDay()->toDateTimeString();
        $endDate = $validated['end_date'] ?? now()->endOfDay()->toDateTimeString();
        if (!str_contains($endDate, ' ')) {
            $endDate .= ' 23:59:59';
        }

        $cacheKey = sprintf(
            'orders:by-product:%d:%s',
            $user->id,
            md5(json_encode([$botFilter, $startDate, $endDate]))
        );

        $data = Cache::remember($cacheKey, 300, function () use ($botFilter, $startDate, $endDate) {
            return DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->whereIn('orders.bot_id', $botFilter)
                ->whereBetween('orders.created_at', [$startDate, $endDate])
                ->select([
                    'order_items.product_name',
                    'order_items.category',
                    DB::raw('COALESCE(SUM(order_items.quantity), 0) as quantity_sold'),
                    DB::raw('COALESCE(SUM(order_items.subtotal), 0) as total_revenue'),
                    DB::raw('COUNT(DISTINCT orders.id) as order_count'),
                ])
                ->groupBy('order_items.product_name', 'order_items.category')
                ->orderByDesc('total_revenue')
                ->limit(100)
                ->get()
                ->map(fn ($row) => [
                    'product_name' => $row->product_name,
                    'category' => $row->category,
                    'quantity_sold' => (int) $row->quantity_sold,
                    'total_revenue' => (float) $row->total_revenue,
                    'order_count' => (int) $row->order_count,
                ]);
        });

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/orders/{order}
     * Single order with items, customer, conversation.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();
        $botIds = $user->bots()->pluck('id');

        if (!$botIds->contains($order->bot_id)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $order->load(['items', 'customerProfile:id,display_name,picture_url', 'conversation:id,external_customer_id,channel_type,status']);

        return response()->json(['data' => $order]);
    }

    /**
     * PUT /api/orders/{order}
     * Update status and/or notes only.
     */
    public function update(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();
        $botIds = $user->bots()->pluck('id');

        if (!$botIds->contains($order->bot_id)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['completed', 'cancelled', 'refunded'])],
            'notes' => 'sometimes|nullable|string|max:1000',
        ]);

        $order->update($validated);
        $order->load('items');

        return response()->json(['data' => $order]);
    }
}
