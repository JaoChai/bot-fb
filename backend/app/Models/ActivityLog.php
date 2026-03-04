<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    // Activity types
    public const TYPE_HANDOVER_STARTED = 'handover_started';

    public const TYPE_HANDOVER_RESOLVED = 'handover_resolved';

    public const TYPE_BOT_CREATED = 'bot_created';

    public const TYPE_BOT_UPDATED = 'bot_updated';

    public const TYPE_CONVERSATION_STARTED = 'conversation_started';

    protected $fillable = [
        'user_id',
        'bot_id',
        'type',
        'title',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * Log an activity
     */
    public static function log(
        int $userId,
        string $type,
        string $title,
        ?string $description = null,
        ?int $botId = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'user_id' => $userId,
            'bot_id' => $botId,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get recent activities for a user
     */
    public static function getRecentForUser(int $userId, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('user_id', $userId)
            ->with('bot:id,name')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
