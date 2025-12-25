<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bot extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'status',
        'channel_type',
        'channel_access_token',
        'channel_secret',
        'webhook_url',
        'page_id',
        'default_flow_id',
        'llm_model',
        'llm_fallback_model',
        'system_prompt',
        'llm_temperature',
        'llm_max_tokens',
        'context_window',
        // Knowledge Base (RAG) settings
        'kb_enabled',
        'kb_relevance_threshold',
        'kb_max_results',
        // Stats
        'total_conversations',
        'total_messages',
        'last_active_at',
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
        'llm_temperature' => 'float',
        'llm_max_tokens' => 'integer',
        'context_window' => 'integer',
        // KB settings
        'kb_enabled' => 'boolean',
        'kb_relevance_threshold' => 'float',
        'kb_max_results' => 'integer',
    ];

    protected $hidden = [
        'channel_access_token',
        'channel_secret',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function defaultFlow(): BelongsTo
    {
        return $this->belongsTo(Flow::class, 'default_flow_id');
    }

    public function flows(): HasMany
    {
        return $this->hasMany(Flow::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(BotSetting::class);
    }

    public function knowledgeBase(): HasOne
    {
        return $this->hasOne(KnowledgeBase::class);
    }
}
