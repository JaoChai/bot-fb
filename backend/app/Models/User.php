<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
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
}
