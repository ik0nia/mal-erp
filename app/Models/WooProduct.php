<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WooProduct extends Model
{
    public const SOURCE_WOOCOMMERCE  = 'woocommerce';
    public const SOURCE_WINMENTOR_CSV = 'winmentor_csv';
    public const SOURCE_TOYA_API     = 'toya_api';

    public const TYPE_SHOP       = 'shop';
    public const TYPE_PRODUCTION = 'production';
    public const TYPE_PALLET_FEE = 'pallet_fee';

    public static function productTypeOptions(): array
    {
        return [
            self::TYPE_SHOP       => 'Comercializare',
            self::TYPE_PRODUCTION => 'Producție (intern)',
            self::TYPE_PALLET_FEE => 'Garanție palet',
        ];
    }

    public static function productTypeBadgeColor(string $type): string
    {
        return match ($type) {
            self::TYPE_PRODUCTION => 'warning',
            self::TYPE_PALLET_FEE => 'info',
            default               => 'success',
        };
    }

    protected $fillable = [
        'connection_id',
        'woo_id',
        'type',
        'status',
        'sku',
        'name',
        'winmentor_name',
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
        'qty_per_inner_box',
        'qty_per_carton',
        'cartons_per_pallet',
        'ean_carton',
        'erp_notes',
        'product_type',
        'procurement_type',
        'on_demand_label',
        'is_discontinued',
        'discontinued_reason',
        'min_stock_qty',
        'max_stock_qty',
        'substituted_by_id',
        'safety_stock',
        'reorder_qty',
        'avg_daily_consumption',
        'abc_classification',
        'xyz_classification',
        'replenishment_method',
    ];

    public const PROCUREMENT_STOCK     = 'stock';
    public const PROCUREMENT_ON_DEMAND = 'on_demand';

    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'woo_id' => 'integer',
            'woo_parent_id' => 'integer',
            'manage_stock' => 'boolean',
            'data' => 'array',
            'is_placeholder' => 'boolean',
            'is_discontinued' => 'boolean',
            'min_stock_qty'   => 'decimal:2',
            'max_stock_qty'   => 'decimal:2',
            'safety_stock'    => 'decimal:2',
            'reorder_qty'     => 'decimal:2',
            'avg_daily_consumption' => 'decimal:4',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }

    /** Produsul care înlocuiește acest produs la achiziții viitoare */
    public function substitutedBy(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'substituted_by_id');
    }

    /** Produsele pe care acest produs le înlocuiește */
    public function substitutes(): HasMany
    {
        return $this->hasMany(WooProduct::class, 'substituted_by_id');
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(WooProductAttribute::class, 'woo_product_id')->orderBy('position');
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
                'supplier_product_name',
                'supplier_package_sku',
                'supplier_package_ean',
                'purchase_price',
                'currency',
                'purchase_uom',
                'conversion_factor',
                'lead_days',
                'incoterms',
                'price_includes_transport',
                'min_order_qty',
                'order_multiple',
                'po_max_qty',
                'date_start',
                'date_end',
                'over_delivery_tolerance',
                'under_delivery_tolerance',
                'is_preferred',
                'notes',
                'last_purchase_date',
                'last_purchase_price',
            ])
            ->withTimestamps();
    }

    public function offerItems(): HasMany
    {
        return $this->hasMany(OfferItem::class, 'woo_product_id');
    }

    protected function decodedName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => html_entity_decode((string) $this->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }
}
