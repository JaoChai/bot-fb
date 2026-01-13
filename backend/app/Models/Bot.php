<?php

namespace App\Models;

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
        // LLM Models (legacy) - API key now in User Settings
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
        // Auto handover setting
        'auto_handover',
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
        // QA Inspector settings
        'qa_inspector_enabled',
        'qa_realtime_model',
        'qa_realtime_fallback_model',
        'qa_analysis_model',
        'qa_analysis_fallback_model',
        'qa_report_model',
        'qa_report_fallback_model',
        'qa_score_threshold',
        'qa_sampling_rate',
        'qa_report_schedule',
        'qa_notifications',
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
        'llm_temperature' => 'float',
        'llm_max_tokens' => 'integer',
        'context_window' => 'integer',
        'webhook_forwarder_enabled' => 'boolean',
        // Channel credentials (encrypted at rest, with fallback for legacy plaintext)
        'channel_access_token' => \App\Casts\EncryptedWithFallback::class,
        'channel_secret' => \App\Casts\EncryptedWithFallback::class,
        // KB settings
        'kb_enabled' => 'boolean',
        'kb_relevance_threshold' => 'float',
        'kb_max_results' => 'integer',
        // Auto handover setting
        'auto_handover' => 'boolean',
        // Semantic Router settings
        'use_semantic_router' => 'boolean',
        'semantic_router_threshold' => 'float',
        // Confidence Cascade settings
        'use_confidence_cascade' => 'boolean',
        'cascade_confidence_threshold' => 'float',
        // QA Inspector settings
        'qa_inspector_enabled' => 'boolean',
        'qa_score_threshold' => 'decimal:2',
        'qa_sampling_rate' => 'integer',
        'qa_notifications' => 'array',
    ];

    protected $hidden = [
        // Credentials are now visible for debugging
        // 'channel_access_token',
        // 'channel_secret',
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

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function improvementSessions(): HasMany
    {
        return $this->hasMany(ImprovementSession::class);
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

    public function qaEvaluationLogs(): HasMany
    {
        return $this->hasMany(QAEvaluationLog::class);
    }

    public function qaWeeklyReports(): HasMany
    {
        return $this->hasMany(QAWeeklyReport::class);
    }
}
