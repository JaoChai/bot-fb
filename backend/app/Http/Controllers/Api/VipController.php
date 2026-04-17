<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Services\VipDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VipController extends Controller
{
    public function __construct(private VipDetectionService $vipService) {}

    /**
     * List all VIP customers (auto + manual) for a given bot.
     * Aggregates by customer_profile_id across all their conversations.
     */
    public function index(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        $conversations = \App\Models\Conversation::where('bot_id', $bot->id)
            ->whereNotNull('customer_profile_id')
            ->with('customerProfile')
            ->get();

        $rows = [];
        $seen = [];
        foreach ($conversations as $conv) {
            $note = $this->findVipNote($conv->memory_notes ?? []);
            if (! $note) {
                continue;
            }
            $cpId = $conv->customer_profile_id;
            if (isset($seen[$cpId])) {
                continue;
            }
            $seen[$cpId] = true;

            // Recompute stats (same query shape as VipDetectionService)
            $stats = Order::where('customer_profile_id', $cpId)
                ->where('status', 'completed')
                ->selectRaw('COUNT(*) as c, COALESCE(SUM(total_amount), 0) as total, MAX(created_at) as last')
                ->first();

            $rows[] = [
                'customer_profile_id' => $cpId,
                'display_name' => $conv->customerProfile?->display_name,
                'picture_url' => $conv->customerProfile?->picture_url,
                'channel_type' => $conv->customerProfile?->channel_type,
                'order_count' => (int) $stats->c,
                'total_amount' => (float) $stats->total,
                'last_order_at' => $stats->last,
                'note_content' => $note['content'],
                'note_source' => $note['source'] ?? 'vip_auto',
                'bot_id' => $bot->id,
            ];
        }

        return response()->json(['data' => $rows]);
    }

    public function revoke(Request $request, Bot $bot, CustomerProfile $customerProfile): JsonResponse
    {
        $this->authorize('update', $bot);

        $removed = $this->vipService->revokeAutoVip($customerProfile);

        return response()->json([
            'message' => 'VIP status revoked',
            'conversations_updated' => $removed,
        ]);
    }

    public function promote(Request $request, Bot $bot, CustomerProfile $customerProfile): JsonResponse
    {
        $this->authorize('update', $bot);

        $validated = $request->validate([
            'content' => 'required|string|max:5000',
        ]);

        $this->vipService->manualPromote($customerProfile, $validated['content']);

        return response()->json(['message' => 'VIP promoted manually']);
    }

    protected function findVipNote(mixed $notes): ?array
    {
        if (! is_array($notes) || (! empty($notes) && ! array_is_list($notes))) {
            return null;
        }

        foreach ($notes as $note) {
            $source = $note['source'] ?? null;
            if (in_array($source, ['vip_auto', 'vip_manual'], true)) {
                return $note;
            }
        }

        return null;
    }
}
