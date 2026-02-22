<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferItem extends Model
{
    protected $fillable = [
        'offer_id',
        'woo_product_id',
        'position',
        'product_name',
        'sku',
        'quantity',
        'unit_price',
        'discount_percent',
        'line_subtotal',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'offer_id' => 'integer',
            'woo_product_id' => 'integer',
            'position' => 'integer',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'line_subtotal' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $item->quantity = max(0, (float) ($item->quantity ?? 0));
            $item->unit_price = max(0, (float) ($item->unit_price ?? 0));
            $item->discount_percent = min(100, max(0, (float) ($item->discount_percent ?? 0)));

            $lineSubtotal = $item->quantity * $item->unit_price;
            $lineTotal = $lineSubtotal * (1 - ($item->discount_percent / 100));

            $item->line_subtotal = number_format($lineSubtotal, 4, '.', '');
            $item->line_total = number_format($lineTotal, 4, '.', '');

            if ($item->woo_product_id) {
                $product = WooProduct::query()
                    ->select(['id', 'name', 'sku'])
                    ->find($item->woo_product_id);

                if ($product) {
                    $item->product_name = $item->product_name ?: $product->decoded_name;
                    $item->sku = $item->sku ?: $product->sku;
                }
            }
        });

        static::saved(function (self $item): void {
            $item->offer?->recalculateTotals();
        });

        static::deleted(function (self $item): void {
            $item->offer?->recalculateTotals();
        });
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'woo_product_id');
    }
}
