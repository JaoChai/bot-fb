<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    /**
     * Encrypt sensitive fields when storing in database.
     */
    protected $casts = [
        'openrouter_api_key' => 'encrypted',
        'line_channel_secret' => 'encrypted',
        'line_channel_access_token' => 'encrypted',
        // Cost limits
        'max_daily_cost' => 'decimal:2',
        'max_monthly_cost' => 'decimal:2',
        'cost_alert_enabled' => 'boolean',
        'cost_alert_threshold' => 'integer',
    ];

    /**
     * Hidden fields - never expose in JSON responses.
     */
    protected $hidden = [
        'openrouter_api_key',
        'line_channel_secret',
        'line_channel_access_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Safely get OpenRouter API key, handling decryption errors.
     * Returns null if decryption fails (e.g., APP_KEY changed).
     */
    public function getOpenRouterApiKey(): ?string
    {
        try {
            return $this->openrouter_api_key;
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to decrypt OpenRouter API key', [
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
}
