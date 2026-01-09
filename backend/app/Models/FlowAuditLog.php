<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowAuditLog extends Model
{
    protected $fillable = [
        'flow_id',
        'user_id',
        'action',
        'changes',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
