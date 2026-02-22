<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceLog extends Model
{
    protected $fillable = [
        'woo_product_id',
        'location_id',
        'old_price',
        'new_price',
        'source',
        'sync_run_id',
        'payload',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'woo_product_id' => 'integer',
            'location_id' => 'integer',
            'sync_run_id' => 'integer',
            'old_price' => 'decimal:4',
            'new_price' => 'decimal:4',
            'payload' => 'array',
            'changed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'woo_product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }
}
