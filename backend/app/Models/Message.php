<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class Message extends Model
{
    use HasNeighbors;

    protected $fillable = [
        'conversation_id',
        'sender',
        'content',
        'type',
        'media_url',
        'media_type',
        'media_metadata',
        'model_used',
        'prompt_tokens',
        'completion_tokens',
        'cost',
        'external_message_id',
        'reply_to_message_id',
        'embedding',
        'sentiment',
        'intents',
    ];

    protected $casts = [
        'media_metadata' => 'array',
        'cost' => 'decimal:6',
        'embedding' => Vector::class,
        'intents' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
