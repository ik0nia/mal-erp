<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'woo_product_id',
        'product_name',
        'sku',
        'supplier_sku',
        'quantity',
        'unit_price',
        'line_total',
        'notes',
        'purchase_request_item_id',
        'sources_json',
        'received_quantity',
    ];

    protected function casts(): array
    {
        return [
            'purchase_order_id'        => 'integer',
            'woo_product_id'           => 'integer',
            'quantity'                 => 'decimal:3',
            'unit_price'               => 'decimal:4',
            'line_total'               => 'decimal:4',
            'purchase_request_item_id' => 'integer',
            'received_quantity'        => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $qty   = max(0, (float) ($item->quantity ?? 0));
            $price = max(0, (float) ($item->unit_price ?? 0));

            $item->line_total = number_format($qty * $price, 4, '.', '');
        });

        static::saved(function (self $item): void {
            $item->purchaseOrder?->recalculateTotals();
        });

        static::deleted(function (self $item): void {
            $item->purchaseOrder?->recalculateTotals();
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'woo_product_id');
    }

    public function purchaseRequestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class);
    }
}
