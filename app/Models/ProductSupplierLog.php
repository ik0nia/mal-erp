<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSupplierLog extends Model
{
    protected $fillable = [
        'woo_product_id',
        'supplier_id',
        'user_id',
        'action',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'woo_product_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
