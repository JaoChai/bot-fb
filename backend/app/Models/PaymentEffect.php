<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEffect extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $casts = [
        'attempt_count' => 'integer',
        'plugin_id' => 'integer',
        'claimed_at' => 'datetime',
        'transport_started_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(VerifiedPaymentEvent::class, 'event_id');
    }
}
