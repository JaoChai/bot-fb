<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The authoritative pending-aggregation group for one conversation (Wave 2
 * #4b). One row per conversation (unique FK); survives any Redis outage.
 */
class MessageAggregationGroup extends Model
{
    protected $fillable = [
        'conversation_id',
        'group_id',
        'message_ids',
        'started_at',
        'due_at',
    ];

    protected function casts(): array
    {
        return [
            'message_ids' => 'array',
            'started_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }
}
