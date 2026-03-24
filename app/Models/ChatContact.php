<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatContact extends Model
{
    protected $fillable = ['session_id', 'email', 'phone', 'wants_specialist', 'contacted_at', 'contacted_by'];

    protected function casts(): array
    {
        return [
            'wants_specialist' => 'boolean',
            'contacted_at'     => 'datetime',
        ];
    }

    public function contactedByUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'contacted_by');
    }

    public function isContacted(): bool
    {
        return $this->contacted_at !== null;
    }

    /**
     * Crează sau actualizează contactul pentru o sesiune.
     * Păstrează valorile existente dacă parametrul nou e null.
     */
    public static function collect(
        string $sessionId,
        ?string $email,
        ?string $phone,
        bool $wantsSpecialist = false,
    ): void {
        try {
            $existing = static::where('session_id', $sessionId)->first();

            if ($existing) {
                $existing->fill([
                    'email'            => $email ?: $existing->email,
                    'phone'            => $phone ?: $existing->phone,
                    'wants_specialist' => $wantsSpecialist || $existing->wants_specialist,
                ])->save();
            } else {
                static::create([
                    'session_id'       => $sessionId,
                    'email'            => $email ?: null,
                    'phone'            => $phone ?: null,
                    'wants_specialist' => $wantsSpecialist,
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ChatContact: nu am putut salva', ['error' => $e->getMessage()]);
        }
    }
}
