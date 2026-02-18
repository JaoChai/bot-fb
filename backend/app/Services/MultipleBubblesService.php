<?php

namespace App\Services;

use App\Jobs\SendDelayedBubbleJob;
use App\Models\Bot;
use Illuminate\Support\Facades\Log;

class MultipleBubblesService
{
    public function __construct(
        protected LINEService $lineService,
        protected PaymentFlexService $paymentFlexService,
    ) {}

    /**
     * Check if multiple bubbles is enabled for this bot.
     */
    public function isEnabled(Bot $bot): bool
    {
        return $bot->settings?->multiple_bubbles_enabled ?? false;
    }

    /**
     * Get the delimiter to use for splitting messages.
     */
    public function getDelimiter(Bot $bot): string
    {
        return $bot->settings?->multiple_bubbles_delimiter ?? '|||';
    }

    /**
     * Get the delay between bubbles in milliseconds.
     */
    public function getDelayMs(Bot $bot): int
    {
        if (! $bot->settings?->wait_multiple_bubbles_enabled) {
            return 0;
        }

        return $bot->settings->wait_multiple_bubbles_ms ?? 1500;
    }

    /**
     * Build prompt instruction for the LLM.
     * This is appended to the system prompt when multiple bubbles is enabled.
     */
    public function buildPromptInstruction(Bot $bot): string
    {
        $settings = $bot->settings;
        if (! $settings || ! $settings->multiple_bubbles_enabled) {
            return '';
        }

        $delimiter = $settings->multiple_bubbles_delimiter ?? '|||';
        $min = $settings->multiple_bubbles_min ?? 1;
        $max = $settings->multiple_bubbles_max ?? 3;

        return <<<INSTRUCTION

## Response Format Instruction
Split your response into {$min}-{$max} separate message bubbles for a natural conversation flow.
Use the delimiter "{$delimiter}" (without quotes) to separate each bubble.
Each bubble should be complete and self-contained (like separate chat messages).
Do NOT include the delimiter at the start or end - only between bubbles.
If your response is short (under 50 characters), use just one bubble (no delimiter needed).

Example with 2 bubbles:
"Hello! Welcome to our service.{$delimiter}How can I help you today?"

Example with 3 bubbles:
"Great question!{$delimiter}Let me explain that for you.{$delimiter}Here's what you need to know..."

IMPORTANT: ข้อมูลสำคัญทั้งหมด (เลขบัญชี, ราคา, ลิงก์) ต้องอยู่ใน response นี้ ห้ามบอกว่า "เดี๋ยวส่งให้" หรือ "รอสักครู่"
INSTRUCTION;
    }

    /**
     * Parse response content into bubbles.
     * Returns array of strings (message contents).
     */
    public function parseIntoBubbles(string $content, Bot $bot): array
    {
        $settings = $bot->settings;

        // If not enabled, return single bubble
        if (! $settings || ! $settings->multiple_bubbles_enabled) {
            return [$content];
        }

        $delimiter = $settings->multiple_bubbles_delimiter ?? '|||';
        $max = $settings->multiple_bubbles_max ?? 3;

        // Split by delimiter
        $bubbles = explode($delimiter, $content);

        // Clean up: trim whitespace, remove empty bubbles
        $bubbles = array_values(array_filter(
            array_map('trim', $bubbles),
            fn ($b) => ! empty($b)
        ));

        // If splitting resulted in no bubbles, return original content
        if (empty($bubbles)) {
            return [$content];
        }

        // Respect LINE's 5 message limit and configured max
        $limit = min(5, $max);

        if (count($bubbles) > $limit) {
            // Merge overflow bubbles into the last kept bubble instead of silently truncating
            $kept = array_slice($bubbles, 0, $limit);
            $overflow = array_slice($bubbles, $limit);
            $kept[$limit - 1] .= "\n" . implode("\n", $overflow);

            Log::warning('Multiple bubbles exceeded limit, merged overflow into last bubble', [
                'original_count' => count($bubbles),
                'limit' => $limit,
                'overflow_count' => count($overflow),
            ]);

            return $kept;
        }

        return $bubbles;
    }

    /**
     * Transform text bubbles into Flex messages where applicable.
     * Each bubble is independently checked for payment content.
     *
     * @param  array<string>  $bubbles
     * @return array<string|array>  Bubbles with payment texts converted to Flex arrays
     */
    public function transformBubbles(array $bubbles): array
    {
        return array_map(
            fn (string $bubble) => $this->paymentFlexService->tryConvertToFlex($bubble),
            $bubbles
        );
    }

    /**
     * Send bubbles to user via LINE.
     * First bubble uses reply (fast), subsequent dispatched as async jobs with delays.
     *
     * @return bool True if at least the first bubble was sent/dispatched
     */
    public function sendBubbles(
        Bot $bot,
        string $userId,
        ?string $replyToken,
        array $bubbles
    ): bool {
        if (empty($bubbles)) {
            return false;
        }

        // Transform text bubbles to Flex messages where applicable
        $bubbles = $this->transformBubbles($bubbles);

        $delayMs = $this->getDelayMs($bot);
        $totalBubbles = count($bubbles);

        try {
            // First bubble: send with fallback (try reply first, then push if token expired)
            // Uses retry key for idempotency (LINE best practice)
            $retryKey = $this->lineService->generateRetryKey();
            $this->lineService->replyWithFallback($bot, $replyToken, $userId, [$bubbles[0]], $retryKey);

            // If only one bubble, we're done
            if ($totalBubbles === 1) {
                return true;
            }

            // Subsequent bubbles: dispatch as delayed jobs (non-blocking)
            for ($i = 1; $i < $totalBubbles; $i++) {
                // Cumulative delay: bubble 2 at t+delayMs, bubble 3 at t+2*delayMs, etc.
                $cumulativeDelayMs = $delayMs * $i;

                if (is_array($bubbles[$i])) {
                    // Flex message: send immediately via push (delayed job only supports text)
                    $pushRetryKey = $this->lineService->generateRetryKey();
                    $this->lineService->push($bot, $userId, [$bubbles[$i]], $pushRetryKey);
                } elseif ($cumulativeDelayMs > 0) {
                    // Text bubble with delay: async dispatch - PHP returns immediately
                    SendDelayedBubbleJob::dispatch(
                        $bot,
                        $userId,
                        $bubbles[$i],
                        $i + 1, // 1-indexed for logging
                        $totalBubbles
                    )->onQueue('webhooks')
                     ->delay(now()->addMilliseconds($cumulativeDelayMs));

                    Log::debug('Dispatched delayed bubble job', [
                        'bot_id' => $bot->id,
                        'user_id' => $userId,
                        'bubble_index' => $i + 1,
                        'total_bubbles' => $totalBubbles,
                        'delay_ms' => $cumulativeDelayMs,
                    ]);
                } else {
                    // Text bubble no delay - send immediately via push with retry key
                    $pushRetryKey = $this->lineService->generateRetryKey();
                    $this->lineService->push($bot, $userId, [$bubbles[$i]], $pushRetryKey);
                }
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send bubbles', [
                'bot_id' => $bot->id,
                'user_id' => $userId,
                'bubble_count' => $totalBubbles,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
