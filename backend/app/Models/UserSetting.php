<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class UserSetting extends Model
{
    protected $fillable = [
        'user_id',
        'openrouter_api_key',
        'openrouter_model',
        // Cost limits
        'max_daily_cost',
        'max_monthly_cost',
        'cost_alert_enabled',
        'cost_alert_threshold',
        'line_channel_secret',
        'line_channel_access_token',
        'easyslip_api_token',
        'quiet_hours_enabled',
        'quiet_hours_start',
        'quiet_hours_end',
    ];

    /**
     * Encrypt sensitive fields when storing in database.
     */
    protected $casts = [
        'openrouter_api_key' => 'encrypted',
        'line_channel_secret' => 'encrypted',
        'line_channel_access_token' => 'encrypted',
        'easyslip_api_token' => 'encrypted',
        // Cost limits
        'max_daily_cost' => 'decimal:2',
        'max_monthly_cost' => 'decimal:2',
        'cost_alert_enabled' => 'boolean',
        'cost_alert_threshold' => 'integer',
        'quiet_hours_enabled' => 'boolean',
    ];

    /**
     * Hidden fields - never expose in JSON responses.
     */
    protected $hidden = [
        'openrouter_api_key',
        'line_channel_secret',
        'line_channel_access_token',
        'easyslip_api_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ตอนนี้อยู่ในช่วงเวลาเงียบแจ้งเตือนซ้ำหรือไม่ — ไม่มี settings row ใช้ default เงียบ 23:00–08:00
     * รองรับช่วงข้ามเที่ยงคืน (start > end) และเทียบแบบ H:i (Postgres คืน HH:MM:SS)
     */
    public static function quietNow(?self $settings): bool
    {
        if (! ($settings?->quiet_hours_enabled ?? true)) {
            return false;
        }

        $start = substr($settings?->quiet_hours_start ?? '23:00', 0, 5);
        $end = substr($settings?->quiet_hours_end ?? '08:00', 0, 5);
        if ($start === $end) {
            return false;
        }

        $now = now()->format('H:i');

        return $start < $end
            ? ($now >= $start && $now < $end)
            : ($now >= $start || $now < $end);
    }

    /**
     * Safely get OpenRouter API key, handling decryption errors.
     * Returns null if decryption fails (e.g., APP_KEY changed).
     */
    public function getOpenRouterApiKey(): ?string
    {
        try {
            return $this->openrouter_api_key;
        } catch (DecryptException $e) {
            Log::warning('Failed to decrypt OpenRouter API key', [
                'user_id' => $this->user_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if OpenRouter is configured.
     */
    public function hasOpenRouterKey(): bool
    {
        return ! empty($this->getOpenRouterApiKey());
    }

    /**
     * Safely get EasySlip API token, handling decryption errors.
     * Returns null if decryption fails (e.g., APP_KEY changed).
     */
    public function getEasySlipApiToken(): ?string
    {
        try {
            return $this->easyslip_api_token;
        } catch (DecryptException $e) {
            Log::warning('Failed to decrypt EasySlip API token', [
                'user_id' => $this->user_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if EasySlip is configured.
     */
    public function hasEasySlipToken(): bool
    {
        return ! empty($this->getEasySlipApiToken());
    }

    /**
     * Check if LINE is configured.
     */
    public function hasLineCredentials(): bool
    {
        return ! empty($this->line_channel_secret) && ! empty($this->line_channel_access_token);
    }

    /**
     * Get masked API key for display (show last 4 chars only).
     */
    public function getMaskedOpenRouterKeyAttribute(): ?string
    {
        if (empty($this->openrouter_api_key)) {
            return null;
        }

        return '••••••••'.substr($this->openrouter_api_key, -4);
    }

    /**
     * Get masked LINE secret for display.
     */
    public function getMaskedLineSecretAttribute(): ?string
    {
        if (empty($this->line_channel_secret)) {
            return null;
        }

        return '••••••••'.substr($this->line_channel_secret, -4);
    }

    /**
     * Get masked LINE token for display.
     */
    public function getMaskedLineTokenAttribute(): ?string
    {
        if (empty($this->line_channel_access_token)) {
            return null;
        }

        return '••••••••'.substr($this->line_channel_access_token, -8);
    }

    /**
     * Get masked EasySlip token for display (show last 4 chars only).
     */
    public function getMaskedEasyslipTokenAttribute(): ?string
    {
        if (empty($this->easyslip_api_token)) {
            return null;
        }

        return '••••••••'.substr($this->easyslip_api_token, -4);
    }
}
