<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPriceQuote extends Model
{
    protected $fillable = [
        'supplier_id',
        'email_message_id',
        'woo_product_id',
        'product_name_raw',
        'unit_price',
        'currency',
        'min_qty',
        'valid_from',
        'valid_until',
        'quoted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_price'  => 'decimal:2',
            'min_qty'     => 'decimal:2',
            'valid_from'  => 'date',
            'valid_until' => 'date',
            'quoted_at'   => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'woo_product_id');
    }
}
