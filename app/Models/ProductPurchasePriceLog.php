<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPurchasePriceLog extends Model
{
    protected $fillable = [
        'woo_product_id',
        'supplier_id',
        'supplier_name_raw',
        'unit_price',
        'currency',
        'acquired_at',
        'source',
        'uom',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_price'  => 'decimal:4',
            'acquired_at' => 'date',
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

    public function getSupplierDisplayName(): string
    {
        if ($this->supplier) {
            return $this->supplier->name;
        }
        return $this->supplier_name_raw ?? '—';
    }
}
