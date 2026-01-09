<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'evaluation_id',
        'executive_summary',
        'strengths',
        'weaknesses',
        'recommendations',
        'prompt_suggestions',
        'kb_gaps',
        'historical_comparison',
    ];

    protected $casts = [
        'strengths' => 'array',
        'weaknesses' => 'array',
        'recommendations' => 'array',
        'prompt_suggestions' => 'array',
        'kb_gaps' => 'array',
        'historical_comparison' => 'array',
    ];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    // Helper methods
    public function getStrengthsList(): array
    {
        return $this->strengths ?? [];
    }

    public function getWeaknessesList(): array
    {
        return $this->weaknesses ?? [];
    }

    public function getRecommendationsList(): array
    {
        return $this->recommendations ?? [];
    }

    public function getPromptSuggestionsList(): array
    {
        return $this->prompt_suggestions ?? [];
    }

    public function getKbGapsList(): array
    {
        return $this->kb_gaps ?? [];
    }

    public function hasImprovedSinceLast(): ?bool
    {
        $comparison = $this->historical_comparison;
        if (!$comparison || !isset($comparison['previous_score'], $comparison['current_score'])) {
            return null;
        }
        return $comparison['current_score'] > $comparison['previous_score'];
    }

    public function getScoreChange(): ?float
    {
        $comparison = $this->historical_comparison;
        if (!$comparison || !isset($comparison['previous_score'], $comparison['current_score'])) {
            return null;
        }
        return round($comparison['current_score'] - $comparison['previous_score'], 4);
    }
}
