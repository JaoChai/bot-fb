<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\BotSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RateLimitService
{
    /**
     * Rate limit result statuses
     */
    public const STATUS_ALLOWED = 'allowed';
    public const STATUS_BOT_DAILY_EXCEEDED = 'bot_daily_exceeded';
    public const STATUS_USER_DAILY_EXCEEDED = 'user_daily_exceeded';

    /**
     * Check if a message is allowed based on rate limits.
     * Returns status and current counts.
     */
    public function checkRateLimit(Bot $bot, string $externalUserId): array
    {
        $settings = $bot->settings;

        // If no settings, allow (defaults will be used)
        if (!$settings) {
            return $this->allowedResponse();
        }

        // Check bot daily limit first
        $botDailyResult = $this->checkBotDailyLimit($bot, $settings);
        if (!$botDailyResult['allowed']) {
            Log::info('Rate limit exceeded: bot daily', [
                'bot_id' => $bot->id,
                'current' => $botDailyResult['current_count'],
                'limit' => $botDailyResult['limit'],
            ]);
            return $botDailyResult;
        }

        // Check per-user daily limit
        $userDailyResult = $this->checkUserDailyLimit($bot, $externalUserId, $settings);
        if (!$userDailyResult['allowed']) {
            Log::info('Rate limit exceeded: user daily', [
                'bot_id' => $bot->id,
                'external_user_id' => $externalUserId,
                'current' => $userDailyResult['current_count'],
                'limit' => $userDailyResult['limit'],
            ]);
            return $userDailyResult;
        }

        return $this->allowedResponse([
            'bot_daily_count' => $botDailyResult['current_count'],
            'user_daily_count' => $userDailyResult['current_count'],
        ]);
    }

    /**
     * Increment counters after message is processed.
     * Call this AFTER successful message processing.
     * Uses atomic increment to prevent race conditions.
     */
    public function incrementCounters(Bot $bot, string $externalUserId): void
    {
        $ttl = $this->getSecondsUntilMidnight();

        // Increment bot daily counter (atomic)
        $botDailyKey = $this->getBotDailyKey($bot->id);
        if (!Cache::has($botDailyKey)) {
            Cache::put($botDailyKey, 1, $ttl);
        } else {
            Cache::increment($botDailyKey);
        }

        // Increment user daily counter (atomic)
        $userDailyKey = $this->getUserDailyKey($bot->id, $externalUserId);
        if (!Cache::has($userDailyKey)) {
            Cache::put($userDailyKey, 1, $ttl);
        } else {
            Cache::increment($userDailyKey);
        }
    }

    /**
     * Get rate limit message for user based on status.
     * Returns null if should be silent (empty string = silent).
     */
    public function getRateLimitMessage(string $status, ?BotSetting $settings = null): ?string
    {
        if (!$settings) {
            return null; // Silent by default
        }

        // Empty string is treated as null (silent mode)
        return match ($status) {
            self::STATUS_BOT_DAILY_EXCEEDED => $settings->rate_limit_bot_message ?: null,
            self::STATUS_USER_DAILY_EXCEEDED => $settings->rate_limit_user_message ?: null,
            default => null,
        };
    }

    /**
     * Check bot daily limit
     */
    private function checkBotDailyLimit(Bot $bot, BotSetting $settings): array
    {
        $limit = $settings->daily_message_limit ?? 1000;

        // If limit is 0, no restriction
        if ($limit === 0) {
            return [
                'allowed' => true,
                'status' => self::STATUS_ALLOWED,
                'current_count' => 0,
                'limit' => 0,
            ];
        }

        $key = $this->getBotDailyKey($bot->id);
        $current = (int) Cache::get($key, 0);

        return [
            'allowed' => $current < $limit,
            'status' => $current >= $limit ? self::STATUS_BOT_DAILY_EXCEEDED : self::STATUS_ALLOWED,
            'current_count' => $current,
            'limit' => $limit,
        ];
    }

    /**
     * Check per-user daily limit
     */
    private function checkUserDailyLimit(Bot $bot, string $externalUserId, BotSetting $settings): array
    {
        $limit = $settings->per_user_limit ?? 100;

        // If limit is 0, no restriction
        if ($limit === 0) {
            return [
                'allowed' => true,
                'status' => self::STATUS_ALLOWED,
                'current_count' => 0,
                'limit' => 0,
            ];
        }

        $key = $this->getUserDailyKey($bot->id, $externalUserId);
        $current = (int) Cache::get($key, 0);

        return [
            'allowed' => $current < $limit,
            'status' => $current >= $limit ? self::STATUS_USER_DAILY_EXCEEDED : self::STATUS_ALLOWED,
            'current_count' => $current,
            'limit' => $limit,
        ];
    }

    /**
     * Generate cache key for bot daily counter
     */
    private function getBotDailyKey(int $botId): string
    {
        return "rate_limit:bot:{$botId}:daily:" . now()->format('Y-m-d');
    }

    /**
     * Generate cache key for user daily counter
     */
    private function getUserDailyKey(int $botId, string $externalUserId): string
    {
        // Hash for consistent key length and to avoid special characters
        $hash = md5($externalUserId);
        return "rate_limit:bot:{$botId}:user:{$hash}:daily:" . now()->format('Y-m-d');
    }

    /**
     * Get seconds until midnight for cache TTL
     */
    private function getSecondsUntilMidnight(): int
    {
        $now = now();
        $seconds = $now->endOfDay()->diffInSeconds($now);
        // Ensure minimum TTL of 1 second to prevent immediate expiration
        return max($seconds, 1);
    }

    /**
     * Build allowed response
     */
    private function allowedResponse(array $extra = []): array
    {
        return array_merge([
            'allowed' => true,
            'status' => self::STATUS_ALLOWED,
        ], $extra);
    }
}
