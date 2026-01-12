<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadRecoveryLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'bot_id',
        'attempt_number',
        'message_mode',
        'message_sent',
        'sent_at',
        'delivery_status',
        'error_message',
        'customer_responded',
        'responded_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'customer_responded' => 'boolean',
        'responded_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
