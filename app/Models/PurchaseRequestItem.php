<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestItem extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ORDERED   = 'ordered';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'purchase_request_id',
        'woo_product_id',
        'supplier_id',
        'product_name',
        'sku',
        'quantity',
        'needed_by',
        'is_urgent',
        'is_reserved',
        'client_reference',
        'notes',
        'status',
        'purchase_order_item_id',
    ];

    protected function casts(): array
    {
        return [
            'purchase_request_id'   => 'integer',
            'woo_product_id'        => 'integer',
            'supplier_id'           => 'integer',
            'quantity'              => 'decimal:3',
            'needed_by'             => 'date',
            'is_urgent'             => 'boolean',
            'is_reserved'           => 'boolean',
            'purchase_order_item_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->woo_product_id) {
                $product = WooProduct::query()
                    ->select(['id', 'name', 'sku'])
                    ->find($item->woo_product_id);

                if ($product) {
                    if (blank($item->product_name)) {
                        $item->product_name = $product->decoded_name ?? $product->name;
                    }
                    if (blank($item->sku)) {
                        $item->sku = $product->sku;
                    }

                    // auto-fill preferred supplier
                    if (! $item->supplier_id) {
                        $preferred = ProductSupplier::where('woo_product_id', $item->woo_product_id)
                            ->where('is_preferred', true)
                            ->first();
                        if ($preferred) {
                            $item->supplier_id = $preferred->supplier_id;
                        }
                    }
                }
            }
        });
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'woo_product_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }
}
