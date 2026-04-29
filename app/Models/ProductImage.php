<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    public const SOURCE_MANUAL      = 'manual';
    public const SOURCE_TOYA        = 'toya';
    public const SOURCE_WOOCOMMERCE = 'woocommerce';

    protected $fillable = [
        'woo_product_id',
        'url',
        'local_path',
        'sort_order',
        'is_primary',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'woo_product_id');
    }

    /**
     * Setează această imagine ca primară și actualizează main_image_url pe produs.
     * Dezactivează is_primary pe celelalte imagini ale produsului.
     */
    public function setAsPrimary(): void
    {
        ProductImage::where('woo_product_id', $this->woo_product_id)
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);

        $this->update(['is_primary' => true]);

        WooProduct::where('id', $this->woo_product_id)
            ->update(['main_image_url' => $this->url]);
    }
}
