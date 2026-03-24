<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailEntity extends Model
{
    // Tipuri de entități extrase de AI
    const TYPE_PRODUCT_PRICE    = 'product_price';
    const TYPE_DELIVERY_DATE    = 'delivery_date';
    const TYPE_INVOICE_NUMBER   = 'invoice_number';
    const TYPE_PAYMENT_TERMS    = 'payment_terms';
    const TYPE_DISCOUNT         = 'discount';
    const TYPE_QUANTITY         = 'quantity';
    const TYPE_OUT_OF_STOCK     = 'out_of_stock';
    const TYPE_PRICE_INCREASE   = 'price_increase';
    const TYPE_GENERAL_MENTION  = 'general_mention';

    protected $fillable = [
        'email_message_id',
        'entity_type',
        'raw_text',
        'woo_product_id',
        'product_name_raw',
        'amount',
        'currency',
        'unit',
        'date_value',
        'confidence',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'confidence' => 'integer',
            'date_value' => 'date',
            'metadata'   => 'array',
        ];
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
