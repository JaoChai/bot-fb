<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlipVerification extends Model
{
    protected $fillable = [
        'bot_id',
        'conversation_id',
        'message_id',
        'trans_ref',
        'amount',
        'receiver_account',
        'status',
        'raw_response',
    ];

    protected $casts = [
        'raw_response' => 'array',
        'amount' => 'float',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
