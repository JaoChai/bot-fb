<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotResponseHours extends Model
{
    protected $fillable = [
        'bot_setting_id',
        'response_hours_enabled',
        'response_hours',
        'response_hours_timezone',
        'offline_message',
    ];

    protected function casts(): array
    {
        return [
            'response_hours_enabled' => 'boolean',
            'response_hours' => 'array',
        ];
    }

    public function botSetting(): BelongsTo
    {
        return $this->belongsTo(BotSetting::class);
    }
}
