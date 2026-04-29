<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WinmentorIntrariUnmatchedSku extends Model
{
    protected $table = 'winmentor_intrari_unmatched_sku';

    protected $fillable = [
        'sku', 'firma', 'last_part_id', 'last_supplier_name',
        'appearances_count', 'last_price', 'last_uom',
        'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_price'    => 'decimal:4',
            'first_seen_at' => 'date',
            'last_seen_at'  => 'date',
        ];
    }
}
