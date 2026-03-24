<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSubstitutionProposal extends Model
{
    protected $fillable = [
        'source_product_id',
        'proposed_toya_id',
        'confidence',
        'reasoning',
        'status',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence'  => 'float',
            'approved_at' => 'datetime',
        ];
    }

    public function sourceProduct(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'source_product_id');
    }

    public function proposedToya(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'proposed_toya_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}
