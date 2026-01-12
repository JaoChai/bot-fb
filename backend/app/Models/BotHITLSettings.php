<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotHITLSettings extends Model
{
    protected $table = 'bot_hitl_settings';

    protected $fillable = [
        'bot_setting_id',
        'hitl_enabled',
        'hitl_triggers',
        'auto_assignment_enabled',
        'auto_assignment_mode',
        'lead_recovery_enabled',
        'lead_recovery_timeout_hours',
        'lead_recovery_mode',
        'lead_recovery_message',
        'lead_recovery_max_attempts',
    ];

    protected function casts(): array
    {
        return [
            'hitl_enabled' => 'boolean',
            'hitl_triggers' => 'array',
            'auto_assignment_enabled' => 'boolean',
            'lead_recovery_enabled' => 'boolean',
            'lead_recovery_timeout_hours' => 'integer',
            'lead_recovery_max_attempts' => 'integer',
        ];
    }

    public function botSetting(): BelongsTo
    {
        return $this->belongsTo(BotSetting::class);
    }
}
