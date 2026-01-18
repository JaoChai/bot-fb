<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class Message extends Model
{
    use HasFactory, HasNeighbors;

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
        'webhook_event_id',
        'is_redelivery',
        'event_timestamp',
        'reply_to_message_id',
        'embedding',
        'sentiment',
        'intents',
        'metadata',
        // Enhanced usage tracking (OpenRouter Best Practice)
        'cached_tokens',
        'reasoning_tokens',
        'reasoning_content',
    ];

    protected $casts = [
        'media_metadata' => 'array',
        'cost' => 'decimal:6',
        'embedding' => Vector::class,
        'intents' => 'array',
        'is_redelivery' => 'boolean',
        'event_timestamp' => 'integer',
        'metadata' => 'array',
        // Enhanced usage tracking (OpenRouter Best Practice)
        'cached_tokens' => 'integer',
        'reasoning_tokens' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    // Query scopes for common patterns

    /**
     * Scope a query to only include messages from bot.
     */
    public function scopeFromBot($query)
    {
        return $query->where('sender', 'bot');
    }

    /**
     * Scope a query to only include messages from user.
     */
    public function scopeFromUser($query)
    {
        return $query->where('sender', 'user');
    }

    /**
     * Scope a query to only include messages from agent.
     */
    public function scopeFromAgent($query)
    {
        return $query->where('sender', 'agent');
    }

    /**
     * Scope a query to only include messages with cost data.
     */
    public function scopeWithCost($query)
    {
        return $query->whereNotNull('cost');
    }

    /**
     * Scope a query to order by creation time descending.
     */
    public function scopeRecentFirst($query)
    {
        return $query->orderByDesc('created_at');
    }

    /**
     * Scope a query to filter by date range.
     */
    public function scopeInDateRange($query, $from, $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }
}
