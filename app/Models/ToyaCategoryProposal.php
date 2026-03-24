<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToyaCategoryProposal extends Model
{
    protected $fillable = [
        'toya_path',
        'product_count',
        'proposed_woo_category_id',
        'alternative_category_ids',
        'confidence',
        'reasoning',
        'status',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'alternative_category_ids' => 'array',
            'confidence'               => 'float',
            'approved_at'              => 'datetime',
        ];
    }

    public function proposedCategory(): BelongsTo
    {
        return $this->belongsTo(WooCategory::class, 'proposed_woo_category_id');
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

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
