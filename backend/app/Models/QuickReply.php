<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickReply extends Model
{
    protected $fillable = [
        'team_id',
        'shortcut',
        'title',
        'content',
        'category',
        'sort_order',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTeam($query, int $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('title');
    }

    public function scopeSearch($query, ?string $search)
    {
        if (! $search) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('shortcut', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%")
                ->orWhere('content', 'like', "%{$search}%");
        });
    }

    public function scopeByShortcut($query, string $shortcut)
    {
        return $query->where('shortcut', 'like', "{$shortcut}%");
    }
}
