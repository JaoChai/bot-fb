<?php

namespace App\Models;

use App\Casts\EncryptedWithFallback;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        // LLM Models (from Connection Settings UI)
        'primary_chat_model',
        'fallback_chat_model',
        'system_prompt',
        'llm_temperature',
        'llm_max_tokens',
        'context_window',
        // Knowledge Base (RAG) settings
        'kb_enabled',
        'kb_relevance_threshold',
        'kb_max_results',
        // Auto handover setting
        'auto_handover',
        'auto_delivery_enabled',
        // Semantic Router settings
        'use_semantic_router',
        'semantic_router_threshold',
        'semantic_router_fallback',
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
        // Channel credentials (encrypted at rest, with fallback for legacy plaintext)
        'channel_access_token' => EncryptedWithFallback::class,
        'channel_secret' => EncryptedWithFallback::class,
        // KB settings
        'kb_enabled' => 'boolean',
        'kb_relevance_threshold' => 'float',
        'kb_max_results' => 'integer',
        // Auto handover setting
        'auto_handover' => 'boolean',
        'auto_delivery_enabled' => 'boolean',
        // Semantic Router settings
        'use_semantic_router' => 'boolean',
        'semantic_router_threshold' => 'float',
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

    /**
     * Get admin users assigned to this bot.
     */
    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'admin_bot_assignments')
            ->withPivot('assigned_by', 'created_at')
            ->withTimestamps();
    }

    /**
     * Get admin bot assignments.
     */
    public function adminAssignments(): HasMany
    {
        return $this->hasMany(AdminBotAssignment::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Resolve the chat model from Connection Settings (single source of truth).
     * Primary first, fallback as stand-in; null when neither is configured.
     */
    public function resolvedChatModel(): ?string
    {
        return $this->primary_chat_model ?: $this->fallback_chat_model;
    }
}
