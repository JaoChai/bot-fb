<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'bot_id',
        'conversation_id',
        'customer_profile_id',
        'message_id',
        'total_amount',
        'payment_method',
        'status',
        'channel_type',
        'raw_extraction',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'raw_extraction' => 'array',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeForBot($query, int $botId)
    {
        return $query->where('bot_id', $botId);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
