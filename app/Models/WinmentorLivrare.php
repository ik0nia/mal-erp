<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WinmentorLivrare extends Model
{
    protected $table = 'winmentor_livrari';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cantitate'      => 'decimal:4',
            'pret'           => 'decimal:4',
            'cant_facturata'  => 'decimal:4',
            'first_seen_at'  => 'datetime',
            'last_seen_at'   => 'datetime',
            'disappeared_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->disappeared_at === null;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('disappeared_at');
    }

    public function scopeDisappeared($query)
    {
        return $query->whereNotNull('disappeared_at');
    }

    public function scopeRecentlyDisappeared($query, int $hours = 48)
    {
        return $query->where('disappeared_at', '>=', now()->subHours($hours));
    }

    public function valoareLinie(): float
    {
        return (float) $this->cantitate * (float) $this->pret;
    }
}
