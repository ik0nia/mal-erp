<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderReception extends Model
{
    public const WINMENTOR_PENDING = 'pending';
    public const WINMENTOR_SYNCED  = 'synced';
    public const WINMENTOR_FAILED  = 'failed';

    protected $fillable = [
        'purchase_order_id',
        'reception_number',
        'is_final',
        'received_at',
        'received_by',
        'received_notes',
        'winmentor_sync_status',
        'winmentor_sync_error',
        'winmentor_order_nr',
        'winmentor_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'purchase_order_id' => 'integer',
            'reception_number'  => 'integer',
            'is_final'          => 'boolean',
            'received_at'       => 'datetime',
            'received_by'       => 'integer',
            'winmentor_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Nu dispatch la created — items-urile nu sunt încă salvate.
        // Controller-ul va dispatch manual după ce termină de creat toate items.
        static::updated(function (self $record): void {
            if ($record->wasChanged('winmentor_sync_status')
                && $record->winmentor_sync_status === self::WINMENTOR_PENDING) {
                \App\Jobs\PushReceptionToWinmentorJob::dispatch($record->id)->afterCommit();
            }
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceptionItem::class, 'reception_id');
    }
}
