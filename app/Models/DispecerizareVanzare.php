<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Strict stratul de CONTROL — decizia de dispecerizare per linie de vânzare.
 * Datele vânzării (client, produs, cantitate) vin live din WinMentor, nu de aici.
 */
class DispecerizareVanzare extends Model
{
    protected $table = 'dispecerizari_vanzari';

    protected $guarded = ['id'];

    public const SURSA_MAGAZIN = 'magazin';
    public const SURSA_DEPOZIT = 'depozit';
    public const SURSA_LIVRARE = 'livrare';

    public const STATUS_NOU       = 'nou';       // decis, neconfirmat
    public const STATUS_DE_PREDAT = 'de_predat'; // confirmat → apare la manipulanți/depozit
    public const STATUS_PREDAT    = 'predat';    // încărcat / predat

    protected function casts(): array
    {
        return [
            'zi'           => 'date',
            'cant_alocata' => 'decimal:4',
            'cant_predata' => 'decimal:4',
            'decis_at'     => 'datetime',
        ];
    }

    /** Cantitatea rămasă de predat pe această alocare. */
    public function getRestAttribute(): float
    {
        return max(0, (float) $this->cant_alocata - (float) $this->cant_predata);
    }

    public function scopeZi($query, $date)
    {
        return $query->whereDate('zi', $date);
    }

    public function decisBy()
    {
        return $this->belongsTo(User::class, 'decis_de');
    }

    public static function cheie(string $tipDoc, string $docId, string $pozitie): string
    {
        return $tipDoc . '|' . $docId . '|' . $pozitie;
    }
}
