<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErrorEvent extends Model
{
    protected $fillable = [
        'fingerprint', 'exception_class', 'message', 'file', 'line', 'trace',
        'url', 'method', 'context', 'user_id', 'count', 'status',
        'first_seen_at', 'last_seen_at', 'opened_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at'  => 'datetime',
            'opened_at'     => 'datetime',
            'resolved_at'   => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Numele scurt al clasei (fără namespace). */
    public function getShortClassAttribute(): string
    {
        return class_basename($this->exception_class);
    }

    /**
     * Durata (secunde) cât a fost ACTIVĂ eroarea: de la deschidere până la ultima apariție.
     * (Nu până la momentul marcării ca rezolvat — acela include intervalul de așteptare/auto-resolve.)
     * Null dacă nu e rezolvată sau lipsesc reperele.
     */
    public function getResolutionSecondsAttribute(): ?int
    {
        $start = $this->opened_at ?? $this->first_seen_at;
        $end   = $this->last_seen_at;
        if ($this->status !== 'resolved' || ! $end || ! $start) {
            return null;
        }
        // Carbon 3: diffInSeconds e cu semn → abs() ca durata să fie mereu pozitivă
        return (int) abs($end->diffInSeconds($start));
    }

    /** Text scurt: „rezolvat în 3h 12m" (resolved) / „deschis de 2h" (open). */
    public function getDurationLabelAttribute(): string
    {
        $fmt = function (int $s): string {
            if ($s < 60) return $s . 's';
            if ($s < 3600) return floor($s / 60) . 'm';
            if ($s < 86400) return floor($s / 3600) . 'h ' . floor(($s % 3600) / 60) . 'm';
            return floor($s / 86400) . 'z ' . floor(($s % 86400) / 3600) . 'h';
        };

        if ($this->status === 'resolved' && $this->resolution_seconds !== null) {
            return 'rezolvat în ' . $fmt($this->resolution_seconds);
        }
        $start = $this->opened_at ?? $this->first_seen_at;
        if ($this->status === 'open' && $start) {
            return 'deschis de ' . $fmt((int) abs(now()->diffInSeconds($start)));
        }
        return '—';
    }
}
