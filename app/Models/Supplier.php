<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'name',
        'logo_url',
        'website_url',
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

    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class)->withTimestamps();
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
