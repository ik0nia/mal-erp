<?php

namespace App\Models;

use App\Concerns\HasLocationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequest extends Model
{
    use HasLocationScope;

    public const STATUS_DRAFT            = 'draft';
    public const STATUS_SUBMITTED        = 'submitted';
    public const STATUS_PARTIALLY_ORDERED = 'partially_ordered';
    public const STATUS_FULLY_ORDERED    = 'fully_ordered';
    public const STATUS_CANCELLED        = 'cancelled';

    protected $fillable = [
        'number',
        'user_id',
        'location_id',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'user_id'     => 'integer',
            'location_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            if (blank($record->number)) {
                $record->number = static::generateNumber();
            }

            $record->status = $record->status ?: self::STATUS_DRAFT;

            if (! $record->user_id && auth()->id()) {
                $record->user_id = (int) auth()->id();
            }

            if (! $record->location_id && auth()->user() instanceof User) {
                $record->location_id = auth()->user()->location_id;
            }
        });
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_DRAFT             => 'Draft',
            self::STATUS_SUBMITTED         => 'Trimis',
            self::STATUS_PARTIALLY_ORDERED => 'Parțial comandat',
            self::STATUS_FULLY_ORDERED     => 'Complet comandat',
            self::STATUS_CANCELLED         => 'Anulat',
        ];
    }

    public static function statusColors(): array
    {
        return [
            self::STATUS_DRAFT             => 'gray',
            self::STATUS_SUBMITTED         => 'info',
            self::STATUS_PARTIALLY_ORDERED => 'warning',
            self::STATUS_FULLY_ORDERED     => 'success',
            self::STATUS_CANCELLED         => 'danger',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    public function recalculateStatus(): void
    {
        $items = $this->items()->get(['status']);

        if ($items->isEmpty()) {
            return;
        }

        $total   = $items->count();
        $ordered = $items->where('status', PurchaseRequestItem::STATUS_ORDERED)->count();

        if ($ordered === 0) {
            return;
        }

        $newStatus = $ordered >= $total
            ? self::STATUS_FULLY_ORDERED
            : self::STATUS_PARTIALLY_ORDERED;

        $this->forceFill(['status' => $newStatus])->saveQuietly();
    }

    private static function generateNumber(): string
    {
        do {
            $number = 'PNR-'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (self::query()->where('number', $number)->exists());

        return $number;
    }
}
