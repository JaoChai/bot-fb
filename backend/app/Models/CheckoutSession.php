<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutSession extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $casts = [
        'revision' => 'integer',
        'items' => 'array',
        'total_minor' => 'integer',
        'requirements' => 'array',
        'accepted' => 'array',
        'presented_at' => 'datetime',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function challengeMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'challenge_message_id');
    }

    public function settledEvent(): BelongsTo
    {
        return $this->belongsTo(VerifiedPaymentEvent::class, 'settled_event_id');
    }
}
