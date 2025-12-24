<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'temperature',
        'max_tokens',
        'agentic_mode',
        'max_tool_calls',
        'enabled_tools',
        'knowledge_base_id',
        'kb_top_k',
        'kb_similarity_threshold',
        'language',
        'is_default',
    ];

    protected $casts = [
        'temperature' => 'decimal:2',
        'kb_similarity_threshold' => 'decimal:3',
        'agentic_mode' => 'boolean',
        'is_default' => 'boolean',
        'enabled_tools' => 'array',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'current_flow_id');
    }
}
