<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'bot_id',
        'customer_profile_id',
        'external_customer_id',
        'channel_type',
        'status',
        'is_handover',
        'assigned_user_id',
        'memory_notes',
        'tags',
        'context',
        'current_flow_id',
        'message_count',
        'last_message_at',
    ];

    protected $casts = [
        'is_handover' => 'boolean',
        'memory_notes' => 'array',
        'tags' => 'array',
        'context' => 'array',
        'last_message_at' => 'datetime',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function currentFlow(): BelongsTo
    {
        return $this->belongsTo(Flow::class, 'current_flow_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
