<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\NoPendingPaymentException;
use App\Exceptions\RecentManualConfirmException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Services\CommerceSafety\MoneyMinor;
use App\Services\CommerceSafety\SafetyScope;
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

        $scoped = in_array(app(SafetyScope::class)->mode($bot), ['enforce', 'hold'], true);
        $amountRules = $scoped
            ? ['sometimes', 'nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value) && ! is_string($value)) {
                    $fail('The amount must be a plain decimal with at most two fractional digits.');

                    return;
                }
                try {
                    $minor = MoneyMinor::fromDecimal((string) $value);
                } catch (\InvalidArgumentException) {
                    $fail('The amount must be a plain decimal with at most two fractional digits.');

                    return;
                }
                if ($minor <= 0 || $minor > 100000000) {
                    $fail('The amount must be between 0.01 and 1000000.00.');
                }
            }]
            : ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:1000000'];
        $validated = $request->validate(['amount' => $amountRules]);

        $amount = isset($validated['amount']) && ! $scoped
            ? (float) $validated['amount']
            : ($validated['amount'] ?? null);

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
