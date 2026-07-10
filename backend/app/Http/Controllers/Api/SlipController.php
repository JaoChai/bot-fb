<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SlipResource;
use App\Models\SlipVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SlipController extends Controller
{
    // สถานะที่ถือว่าเงินเข้าจริง: passed (EasySlip ผ่าน) + manual_confirmed (แอดมินยืนยันเอง)
    private const MONEY_IN = ['passed', 'manual_confirmed'];

    private const ABNORMAL = ['fake', 'wrong_account', 'duplicate', 'amount_mismatch', 'no_pending_order'];

    private const SYSTEM_ERROR = ['unreadable', 'api_error', 'config_error', 'image_download_failed', 'pending'];

    // รวมทุกสถานะที่รู้จัก (whitelist สำหรับ filter) — derive จาก 3 กลุ่มข้างบน กัน desync
    private const STATUSES = [...self::MONEY_IN, ...self::ABNORMAL, ...self::SYSTEM_ERROR];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isOwner()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $botIds = $user->bots()->pluck('id');

        $validated = $request->validate([
            'bot_id' => ['sometimes', 'integer', Rule::in($botIds)],
            'status' => 'sometimes|string',
            'date_from' => 'sometimes|date',
            'date_to' => 'sometimes|date',
            'search' => 'sometimes|string|max:255',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = SlipVerification::whereIn('bot_id', $botIds);

        if (isset($validated['bot_id'])) {
            $query->where('bot_id', $validated['bot_id']);
        }

        if (isset($validated['date_from'])) {
            $query->where('created_at', '>=', $validated['date_from']);
        }

        if (isset($validated['date_to'])) {
            $query->where('created_at', '<=', $validated['date_to']);
        }

        // summary จาก scope bot+date เท่านั้น (ก่อน filter status/search) ด้วย clone
        $summaryBase = clone $query;
        $summary = [
            'total_amount_passed' => (float) (clone $summaryBase)->whereIn('status', self::MONEY_IN)->sum('amount'),
            'count_total' => (clone $summaryBase)->count(),
            'count_abnormal' => (clone $summaryBase)->whereIn('status', self::ABNORMAL)->count(),
            'count_system_error' => (clone $summaryBase)->whereIn('status', self::SYSTEM_ERROR)->count(),
        ];

        $query->with(['conversation:id,customer_profile_id', 'conversation.customerProfile:id,display_name']);

        if (isset($validated['status'])) {
            $statuses = array_values(array_intersect(
                explode(',', $validated['status']),
                self::STATUSES,
            ));
            $query->whereIn('status', $statuses ?: ['__none__']);
        }

        if (isset($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('trans_ref', 'ilike', "%{$search}%")
                    ->orWhereHas('conversation.customerProfile', fn ($c) => $c->where('display_name', 'ilike', "%{$search}%"));
            });
        }

        $paginator = $query->orderByDesc('created_at')->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'data' => SlipResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'summary' => $summary,
            ],
        ]);
    }
}
