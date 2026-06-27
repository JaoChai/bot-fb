<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Conversation;
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

        $conversations = Conversation::where('bot_id', $bot->id)
            ->whereNotNull('customer_profile_id')
            ->with('customerProfile')
            ->get();

        // First pass: collect unique VIP customers (dedup by customer_profile_id).
        $vipConvs = [];
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
            $vipConvs[] = ['conv' => $conv, 'note' => $note];
        }

        // Single grouped aggregate keyed by customer_profile_id — collapses the per-conversation N+1.
        // (Same filter/aggregate shape as VipDetectionService.)
        $statsByCustomer = Order::whereIn('customer_profile_id', array_keys($seen))
            ->where('status', 'completed')
            ->groupBy('customer_profile_id')
            ->selectRaw('customer_profile_id, COUNT(*) as c, COALESCE(SUM(total_amount), 0) as total, MAX(created_at) as last')
            ->get()
            ->keyBy('customer_profile_id');

        $rows = [];
        foreach ($vipConvs as ['conv' => $conv, 'note' => $note]) {
            $cpId = $conv->customer_profile_id;
            $stats = $statsByCustomer->get($cpId);

            $rows[] = [
                'customer_profile_id' => $cpId,
                'display_name' => $conv->customerProfile?->display_name,
                'picture_url' => $conv->customerProfile?->picture_url,
                'channel_type' => $conv->customerProfile?->channel_type,
                'order_count' => (int) ($stats->c ?? 0),
                'total_amount' => (float) ($stats->total ?? 0),
                'last_order_at' => $stats->last ?? null,
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

        $belongsToBot = Conversation::where('bot_id', $bot->id)
            ->where('customer_profile_id', $customerProfile->id)
            ->exists();
        abort_if(! $belongsToBot, 404, 'Customer not found for this bot.');

        $removed = $this->vipService->revokeAutoVip($customerProfile);

        return response()->json([
            'message' => 'VIP status revoked',
            'conversations_updated' => $removed,
        ]);
    }

    public function promote(Request $request, Bot $bot, CustomerProfile $customerProfile): JsonResponse
    {
        $this->authorize('update', $bot);

        $belongsToBot = Conversation::where('bot_id', $bot->id)
            ->where('customer_profile_id', $customerProfile->id)
            ->exists();
        abort_if(! $belongsToBot, 404, 'Customer not found for this bot.');

        $validated = $request->validate([
            'content' => 'required|string|max:2000',
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
