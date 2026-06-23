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
        'unit',
        'unit_price',
        'discount_percent',
        'vat_rate',
        'max_discount_allowed',
        'needs_approval',
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
            'vat_rate' => 'decimal:2',
            'max_discount_allowed' => 'decimal:2',
            'needs_approval' => 'boolean',
            'line_subtotal' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    /** Baza fără TVA pentru linia curentă (prețurile includ TVA). */
    public function getLineNetAttribute(): float
    {
        $rate = (float) $this->vat_rate;

        return $rate > 0
            ? (float) $this->line_total / (1 + $rate / 100)
            : (float) $this->line_total;
    }

    /** Valoarea TVA pentru linia curentă. */
    public function getLineVatAttribute(): float
    {
        return (float) $this->line_total - $this->line_net;
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
                    ->select(['id', 'name', 'sku', 'unit'])
                    ->find($item->woo_product_id);

                if ($product) {
                    $item->product_name = $item->product_name ?: $product->decoded_name;
                    $item->sku = $item->sku ?: $product->sku;

                    if (blank($item->unit)) {
                        $item->unit = $product->unit ?: 'buc';
                    }
                }
            }

            if (blank($item->unit)) {
                $item->unit = 'buc';
            }

            // Cota din catalog Woo nu e maintenata (toate 19) → folosim cota standard configurată (21%).
            if (blank($item->vat_rate) || (float) $item->vat_rate <= 0) {
                $item->vat_rate = Offer::defaultVatRate();
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
