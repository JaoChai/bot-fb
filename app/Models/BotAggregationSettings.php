<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotAggregationSettings extends Model
{
    protected $fillable = [
        'bot_setting_id',
        'multiple_bubbles_enabled',
        'multiple_bubbles_min',
        'multiple_bubbles_max',
        'multiple_bubbles_delimiter',
        'wait_multiple_bubbles_enabled',
        'wait_multiple_bubbles_ms',
        'smart_aggregation_enabled',
        'smart_min_wait_ms',
        'smart_max_wait_ms',
        'smart_early_trigger_enabled',
        'smart_per_user_learning_enabled',
    ];

    protected function casts(): array
    {
        return [
            'multiple_bubbles_enabled' => 'boolean',
            'multiple_bubbles_min' => 'integer',
            'multiple_bubbles_max' => 'integer',
            'wait_multiple_bubbles_enabled' => 'boolean',
            'wait_multiple_bubbles_ms' => 'integer',
            'smart_aggregation_enabled' => 'boolean',
            'smart_min_wait_ms' => 'integer',
            'smart_max_wait_ms' => 'integer',
            'smart_early_trigger_enabled' => 'boolean',
            'smart_per_user_learning_enabled' => 'boolean',
        ];
    }

    public function botSetting(): BelongsTo
    {
        return $this->belongsTo(BotSetting::class);
    }
}
