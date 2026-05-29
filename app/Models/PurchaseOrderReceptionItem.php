<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderReceptionItem extends Model
{
    protected $table = 'purchase_order_reception_items';

    protected $fillable = [
        'reception_id',
        'order_item_id',
        'received_quantity',
        'received_note',
        'invoice_position',
    ];

    protected function casts(): array
    {
        return [
            'reception_id'      => 'integer',
            'order_item_id'     => 'integer',
            'received_quantity'  => 'decimal:3',
        ];
    }

    public function reception(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderReception::class, 'reception_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'order_item_id');
    }
}
