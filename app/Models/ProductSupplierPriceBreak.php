<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSupplierPriceBreak extends Model
{
    protected $fillable = [
        'product_supplier_id',
        'min_qty',
        'max_qty',
        'unit_price',
        'discount_percent',
        'valid_from',
        'valid_until',
    ];

    protected $casts = [
        'min_qty'          => 'decimal:3',
        'max_qty'          => 'decimal:3',
        'unit_price'       => 'decimal:4',
        'discount_percent' => 'decimal:2',
        'valid_from'       => 'date',
        'valid_until'      => 'date',
    ];

    public function productSupplier(): BelongsTo
    {
        return $this->belongsTo(ProductSupplier::class);
    }

    /**
     * Get the effective price for a given quantity and date.
     */
    public static function getBestPrice(int $productSupplierId, float $qty, ?\Carbon\Carbon $date = null): ?self
    {
        $date = $date ?? now();

        return static::where('product_supplier_id', $productSupplierId)
            ->where('min_qty', '<=', $qty)
            ->where(fn ($q) => $q->whereNull('max_qty')->orWhere('max_qty', '>=', $qty))
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date))
            ->orderByDesc('min_qty')
            ->first();
    }
}
