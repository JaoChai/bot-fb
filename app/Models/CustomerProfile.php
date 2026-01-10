<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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

    protected $appends = ['avatar_url'];

    /**
     * Get the avatar_url attribute (alias for picture_url)
     */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->picture_url;
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function messages(): HasManyThrough
    {
        return $this->hasManyThrough(
            Message::class,
            Conversation::class,
            'customer_profile_id',
            'conversation_id'
        );
    }
}
