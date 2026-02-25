<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Supplier extends Model
{
    protected $fillable = [
        'name',
        'contact_person',
        'email',
        'phone',
        'address',
        'vat_number',
        'reg_number',
        'bank_account',
        'bank_name',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(WooProduct::class, 'product_suppliers', 'supplier_id', 'woo_product_id')
            ->using(ProductSupplier::class)
            ->withPivot([
                'supplier_sku',
                'purchase_price',
                'currency',
                'lead_days',
                'min_order_qty',
                'is_preferred',
                'notes',
            ])
            ->withTimestamps();
    }
}
