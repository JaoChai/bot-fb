<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VerifiedPaymentEvent extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $casts = [
        'amount_minor' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Verified payment events are immutable.'));
        static::deleting(fn () => throw new LogicException('Verified payment events are immutable.'));
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function slipVerification(): BelongsTo
    {
        return $this->belongsTo(SlipVerification::class);
    }

    public function receiptMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'receipt_message_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
