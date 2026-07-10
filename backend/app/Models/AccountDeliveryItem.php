<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountDeliveryItem extends Model
{
    public const KIND_STOCK = 'stock';

    public const KIND_SUPPORT_LINK = 'support_link';

    public const KIND_MANUAL = 'manual';

    public const ST_RESERVED = 'reserved';

    public const ST_DELIVERED = 'delivered';

    public const ST_SHORTAGE = 'shortage';

    public const ST_UNMAPPED = 'unmapped';

    public const ST_RETURNED = 'returned';

    protected $fillable = [
        'account_delivery_id', 'product_name', 'stock_code', 'kind',
        'qty', 'stock_item_id', 'status',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(AccountDelivery::class, 'account_delivery_id');
    }
}
