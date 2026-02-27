<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WooOrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'woo_item_id',
        'woo_product_id',
        'name',
        'sku',
        'quantity',
        'price',
        'subtotal',
        'total',
        'tax',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'order_id'       => 'integer',
            'woo_item_id'    => 'integer',
            'woo_product_id' => 'integer',
            'quantity'       => 'integer',
            'price'          => 'decimal:4',
            'subtotal'       => 'decimal:2',
            'total'          => 'decimal:2',
            'tax'            => 'decimal:2',
            'data'           => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(WooOrder::class, 'order_id');
    }
}
