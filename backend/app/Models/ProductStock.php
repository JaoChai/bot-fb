<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductStock extends Model
{
    public const STOCK_CACHE_KEY = 'product_stocks:all';

    protected $fillable = [
        'name',
        'slug',
        'aliases',
        'in_stock',
        'display_order',
    ];

    protected $casts = [
        'aliases' => 'array',
        'in_stock' => 'boolean',
    ];
}
