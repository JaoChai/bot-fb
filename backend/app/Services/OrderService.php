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
     * Normalize a raw product name into standard name, variant, and category.
     *
     * @return array{name: string, variant: string|null, category: string}
     */
    public static function normalizeProductName(string $raw): array
    {
        $lower = mb_strtolower($raw);

        // Extract variant
        $variant = null;
        if (mb_strpos($lower, 'ผูกบัตร') !== false) {
            $variant = 'ผูกบัตร';
        } elseif (mb_strpos($lower, 'เติมเงิน') !== false) {
            $variant = 'เติมเงิน';
        }

        // Product detection (order matters: specific before generic)
        if (mb_strpos($lower, 'ไก่') !== false || mb_strpos($lower, 'เฟสไก่') !== false || mb_strpos($lower, 'g3d') !== false) {
            return ['name' => 'G3D', 'variant' => $variant, 'category' => 'g3d'];
        }
        if (mb_strpos($lower, 'เพจ') !== false || mb_strpos($lower, 'page') !== false || mb_strpos($lower, 'fanpage') !== false) {
            return ['name' => 'Page', 'variant' => null, 'category' => 'page'];
        }
        if (mb_strpos($lower, 'bm') !== false || mb_strpos($lower, 'บีเอ็ม') !== false || mb_strpos($lower, 'บัญชีธุรกิจ') !== false) {
            return ['name' => 'Nolimit BM', 'variant' => $variant, 'category' => 'nolimit'];
        }
        if (mb_strpos($lower, 'personal') !== false || mb_strpos($lower, 'ส่วนตัว') !== false) {
            return ['name' => 'Nolimit Personal', 'variant' => $variant, 'category' => 'nolimit'];
        }
        if (mb_strpos($lower, 'nolimit') !== false || mb_strpos($lower, 'โนลิมิต') !== false) {
            return ['name' => 'Nolimit', 'variant' => $variant, 'category' => 'nolimit'];
        }

        return ['name' => 'Unknown', 'variant' => null, 'category' => 'nolimit'];
    }

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

                // Extract product info and normalize
                $rawProduct = $variables['product'] ?? $variables['product_category'] ?? null;
                if ($rawProduct && is_string($rawProduct)) {
                    $normalized = self::normalizeProductName($rawProduct);

                    $order->items()->create([
                        'product_name' => $normalized['name'],
                        'category' => $normalized['category'],
                        'variant' => $normalized['variant'],
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
