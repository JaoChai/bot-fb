<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'offline_message',
        'welcome_message',
        'fallback_message',
        'typing_indicator',
        'typing_delay_ms',
        'content_filter_enabled',
        'blocked_keywords',
        'analytics_enabled',
        'save_conversations',
        'language',
        'response_style',
        'auto_archive_days',
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
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
