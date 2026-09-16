<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PromptDeployment extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['previous_prompt'];

    protected $casts = [
        'previous_prompt' => 'encrypted',
        'applied_at' => 'datetime',
        'apply_cache_verified_at' => 'datetime',
        'rolled_back_at' => 'datetime',
        'rollback_cache_verified_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];
}
