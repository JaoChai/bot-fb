<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerProfile extends Model
{
    protected $fillable = [
        'external_id',
        'channel_type',
        'display_name',
        'picture_url',
        'phone',
        'email',
        'interaction_count',
        'first_interaction_at',
        'last_interaction_at',
        'metadata',
        'tags',
        'notes',
    ];

    protected $casts = [
        'first_interaction_at' => 'datetime',
        'last_interaction_at' => 'datetime',
        'metadata' => 'array',
        'tags' => 'array',
    ];

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
