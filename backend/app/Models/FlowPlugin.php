<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowPlugin extends Model
{
    protected $fillable = [
        'flow_id',
        'type',
        'name',
        'enabled',
        'trigger_condition',
        'config',
    ];

    protected $casts = [
        'config' => 'array',
        'enabled' => 'boolean',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
