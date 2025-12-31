<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'subscription_plan',
        'subscription_expires_at',
        'timezone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'subscription_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function bots(): HasMany
    {
        return $this->hasMany(Bot::class);
    }

    public function knowledgeBases(): HasMany
    {
        return $this->hasMany(KnowledgeBase::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    /**
     * Get user settings or create default if not exists.
     */
    public function getOrCreateSettings(): UserSetting
    {
        return $this->settings ?? $this->settings()->create([
            'openrouter_model' => 'openai/gpt-4o-mini',
        ]);
    }

    public function isSubscriptionActive(): bool
    {
        if ($this->subscription_plan === 'free') {
            return true;
        }

        return $this->subscription_expires_at && $this->subscription_expires_at->isFuture();
    }

    /**
     * Check if user is an owner.
     */
    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Check if user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Get admin bot assignments.
     */
    public function adminAssignments(): HasMany
    {
        return $this->hasMany(AdminBotAssignment::class);
    }

    /**
     * Get bots assigned to this admin.
     */
    public function assignedBots(): BelongsToMany
    {
        return $this->belongsToMany(Bot::class, 'admin_bot_assignments')
            ->withPivot('assigned_by', 'created_at')
            ->withTimestamps();
    }

    /**
     * Get all bots accessible by this user.
     * Owner: own bots, Admin: assigned bots
     */
    public function accessibleBots()
    {
        if ($this->isOwner()) {
            return $this->bots();
        }

        return $this->assignedBots();
    }

    /**
     * Check if user can access a specific bot.
     */
    public function canAccessBot(Bot $bot): bool
    {
        // Owner can access their own bots
        if ($this->isOwner() && $this->id === $bot->user_id) {
            return true;
        }

        // Admin can access assigned bots
        return $this->assignedBots()->where('bots.id', $bot->id)->exists();
    }
}
