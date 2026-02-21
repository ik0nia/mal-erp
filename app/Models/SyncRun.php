<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRun extends Model
{
    public const TYPE_CATEGORIES = 'categories';
    public const TYPE_PRODUCTS = 'products';
    public const TYPE_WINMENTOR_STOCK = 'winmentor_stock';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'provider',
        'location_id',
        'connection_id',
        'type',
        'status',
        'started_at',
        'finished_at',
        'stats',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'connection_id' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'stats' => 'array',
            'errors' => 'array',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }
}
