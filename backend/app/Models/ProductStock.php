<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductStock extends Model
{
    use HasFactory;

    public const STOCK_CACHE_KEY = 'product_stocks:all';

    protected $fillable = [
        'name',
        'slug',
        'aliases',
        'in_stock',
        'manual_off',
        'available_count',
        'display_order',
        'stock_code',
        'delivery_method',
    ];

    protected $casts = [
        'aliases' => 'array',
        'in_stock' => 'boolean',
        'manual_off' => 'boolean',
        'available_count' => 'integer',
    ];
}
