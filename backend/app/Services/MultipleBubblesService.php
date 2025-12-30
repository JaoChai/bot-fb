<?php

namespace App\Services;

use App\Models\Bot;
use Illuminate\Support\Facades\Log;

class MultipleBubblesService
{
    public function __construct(
        protected LINEService $lineService
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

        return array_slice($bubbles, 0, $limit);
    }

    /**
     * Send bubbles to user via LINE.
     * First bubble uses reply (fast), subsequent use push with delays.
     *
     * @return bool True if at least the first bubble was sent
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

        $delayMs = $this->getDelayMs($bot);

        try {
            // First bubble: use reply if we have a token (faster, within LINE's window)
            if ($replyToken) {
                $this->lineService->reply($bot, $replyToken, [$bubbles[0]]);
            } else {
                $this->lineService->push($bot, $userId, [$bubbles[0]]);
            }

            // If only one bubble, we're done
            if (count($bubbles) === 1) {
                return true;
            }

            // Subsequent bubbles: use push with optional delay
            for ($i = 1; $i < count($bubbles); $i++) {
                if ($delayMs > 0) {
                    usleep($delayMs * 1000); // Convert ms to microseconds
                }

                $this->lineService->push($bot, $userId, [$bubbles[$i]]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send bubbles', [
                'bot_id' => $bot->id,
                'user_id' => $userId,
                'bubble_count' => count($bubbles),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
