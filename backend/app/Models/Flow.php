<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Flow extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'bot_id',
        'name',
        'description',
        'system_prompt',
        'model',
        'fallback_model',
        'decision_model',
        'fallback_decision_model',
        'temperature',
        'max_tokens',
        'agentic_mode',
        'max_tool_calls',
        // Agent Safety
        'agent_timeout_seconds',
        'agent_max_cost_per_request',
        'hitl_enabled',
        'hitl_dangerous_actions',
        'enabled_tools',
        'language',
        'is_default',
        // Second AI
        'second_ai_enabled',
        'second_ai_options',
    ];

    protected $casts = [
        'temperature' => 'decimal:2',
        'agentic_mode' => 'boolean',
        'is_default' => 'boolean',
        'enabled_tools' => 'array',
        // Agent Safety
        'agent_timeout_seconds' => 'integer',
        'agent_max_cost_per_request' => 'decimal:4',
        'hitl_enabled' => 'boolean',
        'hitl_dangerous_actions' => 'array',
        // Second AI
        'second_ai_enabled' => 'boolean',
        'second_ai_options' => 'array',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function knowledgeBases(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeBase::class)
            ->withPivot(['kb_top_k', 'kb_similarity_threshold'])
            ->withTimestamps();
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'current_flow_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function plugins(): HasMany
    {
        return $this->hasMany(FlowPlugin::class);
    }
}
