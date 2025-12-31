<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminBotAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bot_id',
        'assigned_by',
    ];

    /**
     * Get the admin user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the bot.
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * Get the user who assigned this admin.
     */
    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
