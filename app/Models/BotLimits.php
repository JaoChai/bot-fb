<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotLimits extends Model
{
    protected $fillable = [
        'bot_setting_id',
        'daily_message_limit',
        'per_user_limit',
        'rate_limit_per_minute',
        'max_tokens_per_response',
        'rate_limit_bot_message',
        'rate_limit_user_message',
    ];

    protected function casts(): array
    {
        return [
            'daily_message_limit' => 'integer',
            'per_user_limit' => 'integer',
            'rate_limit_per_minute' => 'integer',
            'max_tokens_per_response' => 'integer',
        ];
    }

    public function botSetting(): BelongsTo
    {
        return $this->belongsTo(BotSetting::class);
    }
}
