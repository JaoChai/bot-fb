<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

/**
 * Semantic Cache for RAG responses.
 *
 * Stores query-response pairs with vector embeddings for semantic similarity search.
 * When a similar query comes in, returns the cached response instead of calling the LLM.
 */
class RagCache extends Model
{
    use HasNeighbors;

    protected $table = 'rag_cache';

    public $timestamps = false;

    protected $fillable = [
        'bot_id',
        'query_text',
        'query_normalized',
        'query_embedding',
        'response',
        'metadata',
        'hit_count',
        'last_hit_at',
        'created_at',
        'expires_at',
    ];

    protected $casts = [
        'query_embedding' => Vector::class,
        'metadata' => 'array',
        'hit_count' => 'integer',
        'last_hit_at' => 'datetime',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the bot that owns this cache entry.
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * Scope: Get non-expired cache entries.
     */
    public function scopeNotExpired($query)
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Scope: Get cache entries for a specific bot.
     */
    public function scopeForBot($query, int $botId)
    {
        return $query->where('bot_id', $botId);
    }

    /**
     * Increment hit count and update last_hit_at.
     */
    public function recordHit(): void
    {
        $this->increment('hit_count');
        $this->update(['last_hit_at' => now()]);
    }

    /**
     * Check if this cache entry has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Normalize a query string for exact matching.
     */
    public static function normalizeQuery(string $query): string
    {
        // Lowercase
        $normalized = mb_strtolower($query);

        // Remove extra whitespace
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        // Trim
        $normalized = trim($normalized);

        // Limit length
        return mb_substr($normalized, 0, 500);
    }
}
