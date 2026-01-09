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
    ];

    protected function casts(): array
    {
        return [
            'hitl_enabled' => 'boolean',
            'hitl_triggers' => 'array',
            'auto_assignment_enabled' => 'boolean',
        ];
    }

    public function botSetting(): BelongsTo
    {
        return $this->belongsTo(BotSetting::class);
    }
}
