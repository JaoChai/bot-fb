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
        'webhook_forwarder_enabled',
        'page_id',
        'default_flow_id',
        // OpenRouter API
        'openrouter_api_key',
        // LLM Models (legacy)
        'llm_model',
        'llm_fallback_model',
        // LLM Models (new multi-model)
        'primary_chat_model',
        'fallback_chat_model',
        'decision_model',
        'fallback_decision_model',
        'system_prompt',
        'llm_temperature',
        'llm_max_tokens',
        'context_window',
        // Knowledge Base (RAG) settings
        'kb_enabled',
        'kb_relevance_threshold',
        'kb_max_results',
        // Semantic Router settings
        'use_semantic_router',
        'semantic_router_threshold',
        'semantic_router_fallback',
        // Confidence Cascade settings
        'use_confidence_cascade',
        'cascade_confidence_threshold',
        'cascade_cheap_model',
        'cascade_expensive_model',
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
        'webhook_forwarder_enabled' => 'boolean',
        // KB settings
        'kb_enabled' => 'boolean',
        'kb_relevance_threshold' => 'float',
        'kb_max_results' => 'integer',
        // Semantic Router settings
        'use_semantic_router' => 'boolean',
        'semantic_router_threshold' => 'float',
        // Confidence Cascade settings
        'use_confidence_cascade' => 'boolean',
        'cascade_confidence_threshold' => 'float',
    ];

    protected $hidden = [
        'channel_access_token',
        'channel_secret',
        'openrouter_api_key',
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

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function improvementSessions(): HasMany
    {
        return $this->hasMany(ImprovementSession::class);
    }
}
