<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'name',
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
        'conditions',
        'is_active',
        'buyer_id',
        'po_approval_threshold',
        'default_markup',
    ];

    protected function casts(): array
    {
        return [
            'conditions'            => 'array',
            'is_active'             => 'boolean',
            'buyer_id'              => 'integer',
            'po_approval_threshold' => 'decimal:2',
            'default_markup'        => 'decimal:2',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function buyers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'supplier_buyers')->orderBy('name');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(EmailMessage::class)->orderByDesc('sent_at');
    }

    public function priceQuotes(): HasMany
    {
        return $this->hasMany(SupplierPriceQuote::class)->orderByDesc('quoted_at');
    }

    public function feeds(): HasMany
    {
        return $this->hasMany(SupplierFeed::class);
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
                'po_max_qty',
                'is_preferred',
                'notes',
            ])
            ->withTimestamps();
    }
}
