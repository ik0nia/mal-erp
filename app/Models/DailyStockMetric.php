<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyStockMetric extends Model
{
    protected $fillable = [
        'day',
        'woo_product_id',
        'opening_total_qty',
        'closing_total_qty',
        'opening_available_qty',
        'closing_available_qty',
        'opening_sell_price',
        'closing_sell_price',
        'daily_total_variation',
        'daily_available_variation',
        'closing_sales_value',
        'daily_sales_value_variation',
        'min_available_qty',
        'max_available_qty',
        'snapshots_count',
        'first_snapshot_at',
        'last_snapshot_at',
    ];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'woo_product_id' => 'integer',
            'opening_total_qty' => 'decimal:3',
            'closing_total_qty' => 'decimal:3',
            'opening_available_qty' => 'decimal:3',
            'closing_available_qty' => 'decimal:3',
            'opening_sell_price' => 'decimal:4',
            'closing_sell_price' => 'decimal:4',
            'daily_total_variation' => 'decimal:3',
            'daily_available_variation' => 'decimal:3',
            'closing_sales_value' => 'decimal:4',
            'daily_sales_value_variation' => 'decimal:4',
            'min_available_qty' => 'decimal:3',
            'max_available_qty' => 'decimal:3',
            'snapshots_count' => 'integer',
            'first_snapshot_at' => 'datetime',
            'last_snapshot_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'woo_product_id');
    }
}
