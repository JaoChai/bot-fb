<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

/**
 * SemanticRoute Model
 *
 * Stores example phrases with embeddings for fast intent classification
 * using vector similarity instead of expensive LLM calls.
 *
 * @property int $id
 * @property int|null $bot_id
 * @property string $intent
 * @property string $language
 * @property string $example_phrase
 * @property Vector $embedding
 * @property float $weight
 * @property bool $is_active
 */
class SemanticRoute extends Model
{
    use HasNeighbors;

    protected $fillable = [
        'bot_id',
        'intent',
        'language',
        'example_phrase',
        'embedding',
        'weight',
        'is_active',
    ];

    protected $casts = [
        'embedding' => Vector::class,
        'weight' => 'float',
        'is_active' => 'boolean',
    ];

    /**
     * Get the bot that owns this route.
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * Scope to get routes for a specific bot (including global routes).
     */
    public function scopeForBot($query, ?int $botId)
    {
        return $query->where(function ($q) use ($botId) {
            $q->whereNull('bot_id') // Global routes
              ->orWhere('bot_id', $botId); // Bot-specific routes
        })->where('is_active', true);
    }

    /**
     * Scope to filter by intent.
     */
    public function scopeForIntent($query, string $intent)
    {
        return $query->where('intent', $intent);
    }

    /**
     * Scope to filter by language.
     */
    public function scopeForLanguage($query, string $language)
    {
        return $query->where('language', $language);
    }

    /**
     * Scope to get only active routes.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
