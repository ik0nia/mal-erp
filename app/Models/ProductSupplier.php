<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductSupplier extends Pivot
{
    protected $table = 'product_suppliers';

    public $incrementing = true;

    protected $fillable = [
        'woo_product_id',
        'supplier_id',
        'supplier_sku',
        'purchase_price',
        'currency',
        'lead_days',
        'min_order_qty',
        'is_preferred',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'woo_product_id'  => 'integer',
            'supplier_id'     => 'integer',
            'purchase_price'  => 'decimal:4',
            'lead_days'       => 'integer',
            'min_order_qty'   => 'decimal:3',
            'is_preferred'    => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'woo_product_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
