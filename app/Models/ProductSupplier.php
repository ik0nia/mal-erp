<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'po_max_qty',
        'is_preferred',
        'notes',
        'order_multiple',
        'purchase_uom',
        'conversion_factor',
        'supplier_product_name',
        'supplier_package_sku',
        'supplier_package_ean',
        'incoterms',
        'price_includes_transport',
        'date_start',
        'date_end',
        'over_delivery_tolerance',
        'under_delivery_tolerance',
        'last_purchase_date',
        'last_purchase_price',
    ];

    protected function casts(): array
    {
        return [
            'woo_product_id'  => 'integer',
            'supplier_id'     => 'integer',
            'purchase_price'  => 'decimal:4',
            'lead_days'       => 'integer',
            'min_order_qty'   => 'decimal:3',
            'po_max_qty'      => 'decimal:3',
            'is_preferred'    => 'boolean',
            'order_multiple'          => 'decimal:3',
            'conversion_factor'       => 'decimal:4',
            'price_includes_transport' => 'boolean',
            'date_start'              => 'date',
            'date_end'                => 'date',
            'over_delivery_tolerance' => 'decimal:2',
            'under_delivery_tolerance' => 'decimal:2',
            'last_purchase_date'      => 'date',
            'last_purchase_price'     => 'decimal:4',
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

    public function priceBreaks(): HasMany
    {
        return $this->hasMany(ProductSupplierPriceBreak::class)->orderBy('min_qty');
    }

    public function getBestPriceForQty(float $qty): ?ProductSupplierPriceBreak
    {
        return ProductSupplierPriceBreak::getBestPrice($this->id, $qty);
    }

    public function roundToOrderMultiple(float $qty): float
    {
        if (!$this->order_multiple || $this->order_multiple <= 0) {
            return $qty;
        }
        $multiple = (float) $this->order_multiple;
        return ceil($qty / $multiple) * $multiple;
    }
}
