<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BotSetting extends Model
{
    protected $fillable = [
        'bot_id',
        'daily_message_limit',
        'per_user_limit',
        'rate_limit_per_minute',
        'max_tokens_per_response',
        'hitl_enabled',
        'hitl_triggers',
        'response_hours_enabled',
        'response_hours',
        'response_hours_timezone',
        'offline_message',
        'welcome_message',
        'fallback_message',
        'rate_limit_bot_message',
        'rate_limit_user_message',
        'typing_indicator',
        'typing_delay_ms',
        'content_filter_enabled',
        'blocked_keywords',
        'analytics_enabled',
        'save_conversations',
        'language',
        'response_style',
        'auto_archive_days',
        // Multiple bubbles feature
        'multiple_bubbles_enabled',
        'multiple_bubbles_min',
        'multiple_bubbles_max',
        'multiple_bubbles_delimiter',
        'wait_multiple_bubbles_enabled',
        'wait_multiple_bubbles_ms',
        // Smart aggregation settings
        'smart_aggregation_enabled',
        'smart_min_wait_ms',
        'smart_max_wait_ms',
        'smart_early_trigger_enabled',
        'smart_per_user_learning_enabled',
        // Reply sticker feature
        'reply_sticker_enabled',
        'reply_sticker_message',
        'reply_sticker_mode',
        'reply_sticker_ai_prompt',
        // Auto-assignment feature
        'auto_assignment_enabled',
        'auto_assignment_mode',
    ];

    protected $casts = [
        'hitl_enabled' => 'boolean',
        'hitl_triggers' => 'array',
        'response_hours_enabled' => 'boolean',
        'response_hours' => 'array',
        'typing_indicator' => 'boolean',
        'content_filter_enabled' => 'boolean',
        'blocked_keywords' => 'array',
        'analytics_enabled' => 'boolean',
        'save_conversations' => 'boolean',
        'multiple_bubbles_enabled' => 'boolean',
        'wait_multiple_bubbles_enabled' => 'boolean',
        'smart_aggregation_enabled' => 'boolean',
        'smart_min_wait_ms' => 'integer',
        'smart_max_wait_ms' => 'integer',
        'smart_early_trigger_enabled' => 'boolean',
        'smart_per_user_learning_enabled' => 'boolean',
        'reply_sticker_enabled' => 'boolean',
        'auto_assignment_enabled' => 'boolean',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function limits(): HasOne
    {
        return $this->hasOne(BotLimits::class);
    }

    public function hitlSettings(): HasOne
    {
        return $this->hasOne(BotHITLSettings::class);
    }

    public function aggregationSettings(): HasOne
    {
        return $this->hasOne(BotAggregationSettings::class);
    }

    public function responseHours(): HasOne
    {
        return $this->hasOne(BotResponseHours::class);
    }
}
