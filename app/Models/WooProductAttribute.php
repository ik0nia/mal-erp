<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WooProductAttribute extends Model
{
    protected $fillable = [
        'woo_product_id',
        'name',
        'value',
        'woo_attribute_id',
        'is_visible',
        'position',
        'source',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'woo_product_id');
    }
}
