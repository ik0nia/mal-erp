<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierContact extends Model
{
    protected $fillable = [
        'supplier_id',
        'name',
        'role',
        'department',
        'email',
        'phone',
        'notes',
        'is_primary',
        'source',
        'first_seen_at',
        'last_seen_at',
        'email_count',
    ];

    protected function casts(): array
    {
        return [
            'is_primary'    => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at'  => 'datetime',
            'email_count'   => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(EmailMessage::class)->orderByDesc('sent_at');
    }

    /** Actualizează statisticile de activitate pe baza emailurilor din DB. */
    public function recalculateStats(): void
    {
        if (! $this->email) {
            return;
        }

        $stats = EmailMessage::where('from_email', $this->email)
            ->selectRaw('COUNT(*) as cnt, MIN(sent_at) as first, MAX(sent_at) as last')
            ->first();

        $this->update([
            'email_count'   => $stats->cnt ?? 0,
            'first_seen_at' => $stats->first,
            'last_seen_at'  => $stats->last,
        ]);
    }
}
