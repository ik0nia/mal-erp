<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WinmentorComanda extends Model
{
    protected $table = 'winmentor_comenzi';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cantitate'       => 'decimal:4',
            'pret'            => 'decimal:4',
            'cant_comanda'    => 'decimal:4',
            'factura_estimat' => 'boolean',
            'first_seen_at'   => 'datetime',
            'last_seen_at'    => 'datetime',
            'disappeared_at'  => 'datetime',
        ];
    }

    public function isDeschisa(): bool
    {
        return $this->disappeared_at === null;
    }

    public function scopeDeschise($query)
    {
        return $query->whereNull('disappeared_at');
    }

    public function scopeInchise($query)
    {
        return $query->whereNotNull('disappeared_at');
    }

    public function valoareLinie(): float
    {
        return (float) $this->cantitate * (float) $this->pret;
    }
}
