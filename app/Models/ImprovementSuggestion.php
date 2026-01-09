<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImprovementSuggestion extends Model
{
    use HasFactory;

    // Type constants
    public const TYPE_SYSTEM_PROMPT = 'system_prompt';
    public const TYPE_KB_CONTENT = 'kb_content';

    // Priority constants
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_LOW = 'low';

    protected $fillable = [
        'session_id',
        'type',
        'priority',
        'confidence_score',
        'title',
        'description',
        'current_value',
        'suggested_value',
        'diff_summary',
        'target_knowledge_base_id',
        'kb_content_title',
        'kb_content_body',
        'related_topics',
        'is_selected',
        'is_applied',
        'applied_at',
        'source_metric',
        'source_test_case_ids',
    ];

    protected $casts = [
        'confidence_score' => 'float',
        'related_topics' => 'array',
        'source_test_case_ids' => 'array',
        'is_selected' => 'boolean',
        'is_applied' => 'boolean',
        'applied_at' => 'datetime',
    ];

    // Relationships
    public function session(): BelongsTo
    {
        return $this->belongsTo(ImprovementSession::class, 'session_id');
    }

    public function targetKnowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'target_knowledge_base_id');
    }

    // Helper methods
    public function isSystemPrompt(): bool
    {
        return $this->type === self::TYPE_SYSTEM_PROMPT;
    }

    public function isKbContent(): bool
    {
        return $this->type === self::TYPE_KB_CONTENT;
    }

    public function isHighPriority(): bool
    {
        return $this->priority === self::PRIORITY_HIGH;
    }

    public function isMediumPriority(): bool
    {
        return $this->priority === self::PRIORITY_MEDIUM;
    }

    public function isLowPriority(): bool
    {
        return $this->priority === self::PRIORITY_LOW;
    }

    public function toggleSelection(): void
    {
        $this->update(['is_selected' => !$this->is_selected]);
    }

    public function markAsApplied(): void
    {
        $this->update([
            'is_applied' => true,
            'applied_at' => now(),
        ]);
    }

    /**
     * Get priority badge variant for frontend
     */
    public function getPriorityVariant(): string
    {
        return match ($this->priority) {
            self::PRIORITY_HIGH => 'destructive',
            self::PRIORITY_MEDIUM => 'warning',
            self::PRIORITY_LOW => 'default',
            default => 'secondary',
        };
    }
}
