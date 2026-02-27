<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiAnalysis extends Model
{
    protected $fillable = [
        'type',
        'generated_by',
        'title',
        'content',
        'status',
        'error_message',
        'metrics_snapshot',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'metrics_snapshot' => 'array',
            'generated_at'     => 'datetime',
        ];
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
