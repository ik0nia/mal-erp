<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WooProduct extends Model
{
    public const SOURCE_WOOCOMMERCE = 'woocommerce';
    public const SOURCE_WINMENTOR_CSV = 'winmentor_csv';

    protected $fillable = [
        'connection_id',
        'woo_id',
        'type',
        'status',
        'sku',
        'name',
        'slug',
        'short_description',
        'description',
        'regular_price',
        'sale_price',
        'price',
        'stock_status',
        'manage_stock',
        'woo_parent_id',
        'main_image_url',
        'data',
        'source',
        'is_placeholder',
        'unit',
        'brand',
        'weight',
        'dim_length',
        'dim_width',
        'dim_height',
        'erp_notes',
    ];

    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'woo_id' => 'integer',
            'woo_parent_id' => 'integer',
            'manage_stock' => 'boolean',
            'data' => 'array',
            'is_placeholder' => 'boolean',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            WooCategory::class,
            'woo_product_category',
            'woo_product_id',
            'woo_category_id'
        );
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class, 'woo_product_id');
    }

    public function priceLogs(): HasMany
    {
        return $this->hasMany(ProductPriceLog::class, 'woo_product_id');
    }

    public function dailyStockMetrics(): HasMany
    {
        return $this->hasMany(DailyStockMetric::class, 'reference_product_id', 'sku');
    }

    public function latestDailyStockMetric(): HasOne
    {
        return $this->hasOne(DailyStockMetric::class, 'reference_product_id', 'sku')
            ->ofMany('day', 'max');
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'product_suppliers', 'woo_product_id', 'supplier_id')
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

    public function offerItems(): HasMany
    {
        return $this->hasMany(OfferItem::class, 'woo_product_id');
    }

    public function getDecodedNameAttribute(): string
    {
        return html_entity_decode((string) $this->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
