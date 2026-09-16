<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A single redacted, aggregate-only observation recorded while a bot is in
 * `commerce_safety` `shadow` mode. See App\Services\CommerceSafety\ShadowObservationRecorder.
 */
class CommerceSafetyShadowObservation extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Shadow observations are immutable.'));
        static::deleting(fn () => throw new LogicException('Shadow observations are immutable.'));
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
