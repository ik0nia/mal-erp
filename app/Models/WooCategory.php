<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WooCategory extends Model
{
    protected $fillable = [
        'connection_id',
        'woo_id',
        'name',
        'slug',
        'description',
        'parent_woo_id',
        'parent_id',
        'image_url',
        'menu_order',
        'count',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'woo_id' => 'integer',
            'parent_woo_id' => 'integer',
            'parent_id' => 'integer',
            'menu_order' => 'integer',
            'count' => 'integer',
            'data' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            WooProduct::class,
            'woo_product_category',
            'woo_category_id',
            'woo_product_id'
        );
    }
}
