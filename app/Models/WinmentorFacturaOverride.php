<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Marcaj local pentru reconcilierea scadențarului furnizor:
 * o factură din GetSolduriFurn marcată ca stinsă/fantomă (nu putem șterge din WinMentor).
 */
class WinmentorFacturaOverride extends Model
{
    protected $table = 'winmentor_factura_overrides';

    protected $fillable = [
        'part_id', 'nr_factura', 'directie', 'action',
        'settled_amount', 'source', 'note', 'user_id',
    ];

    protected function casts(): array
    {
        return ['settled_amount' => 'decimal:2'];
    }
}
