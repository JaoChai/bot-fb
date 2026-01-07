<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\BotSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ResponseHoursService
{
    /**
     * Response hours result statuses
     */
    public const STATUS_ALLOWED = 'allowed';
    public const STATUS_FEATURE_DISABLED = 'feature_disabled';
    public const STATUS_OUTSIDE_HOURS = 'outside_hours';
    public const STATUS_DAY_CLOSED = 'day_closed';

    /**
     * Check if the current time is within the bot's response hours.
     *
     * @param Bot $bot
     * @return array{allowed: bool, status: string, current_time?: string, timezone?: string, day?: string}
     */
    public function checkResponseHours(Bot $bot): array
    {
        $settings = $bot->settings;

        // If no settings or feature disabled, allow
        if (!$settings || !$settings->response_hours_enabled) {
            return $this->allowedResponse(self::STATUS_FEATURE_DISABLED);
        }

        $responseHours = $settings->response_hours;

        // If empty response_hours, allow (24/7 mode)
        if (empty($responseHours) || !is_array($responseHours)) {
            return $this->allowedResponse(self::STATUS_ALLOWED);
        }

        // Get current time in bot's configured timezone
        $timezone = $this->getTimezone($settings);
        $now = Carbon::now($timezone);
        $currentTime = $now->format('H:i');
        $dayKey = $this->getDayKey($now);

        // If day not in config, return closed
        if (!isset($responseHours[$dayKey])) {
            Log::debug('Response hours: day closed', [
                'bot_id' => $bot->id,
                'day' => $dayKey,
                'timezone' => $timezone,
            ]);

            return [
                'allowed' => false,
                'status' => self::STATUS_DAY_CLOSED,
                'current_time' => $currentTime,
                'timezone' => $timezone,
                'day' => $dayKey,
            ];
        }

        $slots = $responseHours[$dayKey];

        // If slots is not an array or empty, day is closed
        if (!is_array($slots) || empty($slots)) {
            return [
                'allowed' => false,
                'status' => self::STATUS_DAY_CLOSED,
                'current_time' => $currentTime,
                'timezone' => $timezone,
                'day' => $dayKey,
            ];
        }

        // Check if current time is within any slot
        foreach ($slots as $slot) {
            if ($this->isWithinSlot($currentTime, $slot)) {
                return $this->allowedResponse(self::STATUS_ALLOWED, [
                    'current_time' => $currentTime,
                    'timezone' => $timezone,
                    'day' => $dayKey,
                ]);
            }
        }

        // Outside all slots
        Log::debug('Response hours: outside hours', [
            'bot_id' => $bot->id,
            'current_time' => $currentTime,
            'day' => $dayKey,
            'timezone' => $timezone,
            'slots' => $slots,
        ]);

        return [
            'allowed' => false,
            'status' => self::STATUS_OUTSIDE_HOURS,
            'current_time' => $currentTime,
            'timezone' => $timezone,
            'day' => $dayKey,
        ];
    }

    /**
     * Get the offline message from bot settings.
     * Returns null if settings is null or offline_message is empty (silent mode).
     *
     * @param BotSetting|null $settings
     * @return string|null
     */
    public function getOfflineMessage(?BotSetting $settings): ?string
    {
        if (!$settings) {
            return null;
        }

        // Empty string or null = silent mode
        return $settings->offline_message ?: null;
    }

    /**
     * Check if the current time is within a time slot.
     * Handles overnight slots (e.g., 22:00-02:00).
     *
     * @param string $currentTime H:i format
     * @param array{start: string, end: string} $slot
     * @return bool
     */
    protected function isWithinSlot(string $currentTime, array $slot): bool
    {
        if (!isset($slot['start'], $slot['end'])) {
            return false;
        }

        $start = $slot['start'];
        $end = $slot['end'];

        // Handle overnight crossing (end < start, e.g., 22:00-02:00)
        if ($end < $start) {
            // Time is valid if: currentTime >= start OR currentTime < end
            return $currentTime >= $start || $currentTime < $end;
        }

        // Normal case: start <= currentTime < end
        // Using < for end to make 18:00 mean "up to but not including 18:00"
        // Or use <= if you want 18:00 to be inclusive
        return $currentTime >= $start && $currentTime < $end;
    }

    /**
     * Get the lowercase day key from a Carbon date.
     *
     * @param Carbon $date
     * @return string mon, tue, wed, thu, fri, sat, sun
     */
    protected function getDayKey(Carbon $date): string
    {
        return strtolower($date->format('D'));
    }

    /**
     * Get the timezone from bot settings or fallback to default.
     *
     * @param BotSetting|null $settings
     * @return string
     */
    protected function getTimezone(?BotSetting $settings): string
    {
        $timezone = $settings?->response_hours_timezone;

        if (!$timezone) {
            return 'Asia/Bangkok';
        }

        // Validate timezone
        try {
            new \DateTimeZone($timezone);
            return $timezone;
        } catch (\Exception $e) {
            Log::warning('Invalid timezone in bot settings, using default', [
                'invalid_timezone' => $timezone,
                'default' => 'Asia/Bangkok',
            ]);
            return 'Asia/Bangkok';
        }
    }

    /**
     * Build allowed response.
     *
     * @param string $status
     * @param array $extra
     * @return array
     */
    private function allowedResponse(string $status = self::STATUS_ALLOWED, array $extra = []): array
    {
        return array_merge([
            'allowed' => true,
            'status' => $status,
        ], $extra);
    }
}
