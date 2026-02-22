<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStock extends Model
{
    protected $fillable = [
        'woo_product_id',
        'location_id',
        'quantity',
        'price',
        'source',
        'sync_run_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'woo_product_id' => 'integer',
            'location_id' => 'integer',
            'sync_run_id' => 'integer',
            'quantity' => 'decimal:3',
            'price' => 'decimal:4',
            'synced_at' => 'datetime',
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
