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
        'part_id', 'nr_factura', 'row_key', 'data_factura', 'directie', 'action',
        'settled_amount', 'source', 'note', 'user_id',
    ];

    /**
     * Cheie stabilă și UNICĂ per rând de scadențar (avansurile au nr_factura duplicat!).
     * Supraviețuiește re-sync-ului (același document → aceleași valori).
     */
    public static function rowKey(mixed $nrFactura, mixed $dataFactura, mixed $rest): string
    {
        return md5(trim((string) $nrFactura) . '|' . trim((string) $dataFactura) . '|' . number_format((float) $rest, 2, '.', ''));
    }

    protected function casts(): array
    {
        return ['settled_amount' => 'decimal:2'];
    }
}
