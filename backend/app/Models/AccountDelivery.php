<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountDelivery extends Model
{
    public const STATUS_RESERVING = 'reserving';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_DELIVERING = 'delivering';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'bot_id', 'conversation_id', 'slip_verification_id', 'status',
        'amount', 'confirmed_by', 'delivered_at', 'last_reminded_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'delivered_at' => 'datetime',
        'last_reminded_at' => 'datetime',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AccountDeliveryItem::class);
    }
}
