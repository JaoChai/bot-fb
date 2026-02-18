<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    /**
     * Create an order from plugin-extracted variables.
     * Returns null on failure or if no valid amount found (non-blocking).
     */
    public function createFromPluginExtraction(
        Bot $bot,
        Conversation $conversation,
        ?Message $message,
        array $variables
    ): ?Order {
        try {
            $rawAmount = $variables['amount'] ?? null;
            if ($rawAmount === null) {
                return null;
            }

            // Parse amount: strip commas/spaces, cast to float
            $amount = (float) str_replace([',', ' '], '', (string) $rawAmount);
            if ($amount <= 0) {
                return null;
            }

            // Deduplicate: check if order with same conversation_id AND message_id already exists
            if ($message) {
                $existing = Order::where('conversation_id', $conversation->id)
                    ->where('message_id', $message->id)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            } else {
                // Time-based dedup fallback when no message reference
                $existing = Order::where('conversation_id', $conversation->id)
                    ->where('total_amount', $amount)
                    ->where('created_at', '>=', now()->subMinutes(2))
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            return DB::transaction(function () use ($bot, $conversation, $message, $variables, $amount) {
                $order = Order::create([
                    'bot_id' => $bot->id,
                    'conversation_id' => $conversation->id,
                    'customer_profile_id' => $conversation->customer_profile_id,
                    'message_id' => $message?->id,
                    'total_amount' => $amount,
                    'payment_method' => $variables['source_bank'] ?? $variables['payment_method'] ?? null,
                    'status' => 'completed',
                    'channel_type' => $conversation->channel_type,
                    'raw_extraction' => $variables,
                ]);

                // Extract product info
                $productName = $variables['product'] ?? $variables['product_category'] ?? null;
                if ($productName && is_string($productName)) {
                    // Category detection: keyword "เพจ"/"page" → 'page', else → 'nolimit'
                    $category = 'nolimit';
                    if (mb_stripos($productName, 'เพจ') !== false || mb_stripos($productName, 'page') !== false) {
                        $category = 'page';
                    }

                    $order->items()->create([
                        'product_name' => $productName,
                        'category' => $category,
                        'quantity' => 1,
                        'unit_price' => $amount,
                        'subtotal' => $amount,
                    ]);
                }

                return $order->load('items');
            });
        } catch (\Throwable $e) {
            Log::error('OrderService: Failed to create order from plugin extraction', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
