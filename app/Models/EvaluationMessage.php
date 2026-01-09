<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'test_case_id',
        'turn_number',
        'role',
        'content',
        'rag_metadata',
        'model_metadata',
        'turn_scores',
    ];

    protected $casts = [
        'rag_metadata' => 'array',
        'model_metadata' => 'array',
        'turn_scores' => 'array',
        'turn_number' => 'integer',
    ];

    // Role constants
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_SYSTEM = 'system';

    public function testCase(): BelongsTo
    {
        return $this->belongsTo(EvaluationTestCase::class, 'test_case_id');
    }

    // Helper methods
    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    public function isAssistant(): bool
    {
        return $this->role === self::ROLE_ASSISTANT;
    }

    public function isSystem(): bool
    {
        return $this->role === self::ROLE_SYSTEM;
    }

    public function getRetrievedChunks(): array
    {
        return $this->rag_metadata['chunks'] ?? [];
    }

    public function getModelUsed(): ?string
    {
        return $this->model_metadata['model'] ?? null;
    }

    public function getTokensUsed(): int
    {
        $metadata = $this->model_metadata ?? [];
        return ($metadata['prompt_tokens'] ?? 0) + ($metadata['completion_tokens'] ?? 0);
    }
}
