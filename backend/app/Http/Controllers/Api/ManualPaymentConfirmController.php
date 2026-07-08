<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\NoPendingPaymentException;
use App\Exceptions\RecentManualConfirmException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Services\Payment\ManualPaymentConfirmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManualPaymentConfirmController extends Controller
{
    public function __construct(
        private ManualPaymentConfirmService $service,
    ) {}

    /**
     * Admin manually confirms a payment, routing it through the bot's output pipeline
     * (Flex + LINE push + plugins → order creation).
     */
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $bot = $conversation->bot;

        // Same policy as replying in chat (agent-message): bot owner only.
        $this->authorize('update', $bot);

        $validated = $request->validate([
            'amount' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:1000000'],
        ]);

        $amount = isset($validated['amount']) ? (float) $validated['amount'] : null;

        try {
            $result = $this->service->confirm($bot, $conversation, $amount, $request->user()->id);
        } catch (NoPendingPaymentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RecentManualConfirmException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => new MessageResource($result['message']),
            'order_created' => $result['order_created'],
        ]);
    }
}
