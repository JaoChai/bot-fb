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
        'bot_auto_enable_at',
        'assigned_user_id',
        'memory_notes',
        'tags',
        'context',
        'current_flow_id',
        'message_count',
        'unread_count',
        'last_message_at',
    ];

    protected $casts = [
        'is_handover' => 'boolean',
        'memory_notes' => 'array',
        'tags' => 'array',
        'context' => 'array',
        'last_message_at' => 'datetime',
        'bot_auto_enable_at' => 'datetime',
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

    // Query scopes for common patterns

    /**
     * Scope a query to only include active conversations.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include conversations in handover mode.
     */
    public function scopeHandover($query)
    {
        return $query->where('is_handover', true);
    }

    /**
     * Scope a query to filter by bot.
     */
    public function scopeForBot($query, int $botId)
    {
        return $query->where('bot_id', $botId);
    }

    /**
     * Scope a query to filter by channel type.
     */
    public function scopeOfChannel($query, string $channelType)
    {
        return $query->where('channel_type', $channelType);
    }

    /**
     * Scope a query to filter by assigned user.
     */
    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_user_id', $userId);
    }

    /**
     * Scope a query to order by most recent message.
     */
    public function scopeRecentFirst($query)
    {
        return $query->orderByDesc('last_message_at');
    }

    /**
     * Scope a query to only include conversations with unread messages.
     */
    public function scopeUnread($query)
    {
        return $query->where('unread_count', '>', 0);
    }

    /**
     * Scope a query to only include conversations needing bot auto-enable.
     */
    public function scopeNeedsBotAutoEnable($query)
    {
        return $query->where('is_handover', true)
            ->whereNotNull('bot_auto_enable_at')
            ->where('bot_auto_enable_at', '<=', now());
    }

    /**
     * Check if bot auto-enable timer is active.
     */
    public function hasBotAutoEnableTimer(): bool
    {
        return $this->is_handover && $this->bot_auto_enable_at !== null;
    }

    /**
     * Get remaining seconds until bot auto-enables.
     */
    public function getBotAutoEnableRemainingSeconds(): ?int
    {
        if (! $this->hasBotAutoEnableTimer()) {
            return null;
        }

        $remaining = $this->bot_auto_enable_at->diffInSeconds(now(), false);

        return $remaining > 0 ? null : abs($remaining);
    }
}
