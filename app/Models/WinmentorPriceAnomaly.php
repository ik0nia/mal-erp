<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WinmentorPriceAnomaly extends Model
{
    const TYPE_PRICE_SPIKE       = 'price_spike';
    const TYPE_PRICE_DROP        = 'price_drop';
    const TYPE_SUPPLIER_CHANGE   = 'supplier_change';
    const TYPE_POSSIBLE_SKU_REUSE = 'possible_sku_reuse';

    protected $fillable = [
        'sku', 'woo_product_id', 'firma', 'anomaly_type',
        'previous_price', 'new_price', 'price_change_pct',
        'previous_part_id', 'new_part_id',
        'previous_supplier_id', 'new_supplier_id',
        'previous_date', 'new_date',
        'intrare_raw_id', 'reviewed', 'notes', 'alerted_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_price'    => 'decimal:4',
            'new_price'         => 'decimal:4',
            'price_change_pct'  => 'decimal:2',
            'previous_date'     => 'date',
            'new_date'          => 'date',
            'reviewed'          => 'boolean',
            'alerted_at'        => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'woo_product_id');
    }

    public function previousSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'previous_supplier_id');
    }

    public function newSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'new_supplier_id');
    }
}
