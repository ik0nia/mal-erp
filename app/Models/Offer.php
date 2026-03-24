<?php

namespace App\Models;

use App\Concerns\HasLocationScope;
use App\Enums\HasStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Offer extends Model
{
    use HasLocationScope, HasStatusEnum;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'location_id',
        'user_id',
        'number',
        'status',
        'client_name',
        'client_company',
        'client_email',
        'client_phone',
        'notes',
        'currency',
        'subtotal',
        'discount_total',
        'total',
        'valid_until',
        'sent_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'user_id' => 'integer',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'total' => 'decimal:4',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $offer): void {
            if (blank($offer->number)) {
                $offer->number = static::generateNumber();
            }

            $offer->status = $offer->status ?: self::STATUS_DRAFT;
            $offer->currency = $offer->currency ?: 'RON';

            if (! $offer->user_id && auth()->id()) {
                $offer->user_id = (int) auth()->id();
            }
        });

        static::saving(function (self $offer): void {
            if ($offer->isDirty('status') && $offer->status === self::STATUS_SENT && ! $offer->sent_at) {
                $offer->sent_at = now();
            }

            if ($offer->isDirty('status') && $offer->status === self::STATUS_ACCEPTED && ! $offer->accepted_at) {
                $offer->accepted_at = now();
            }
        });
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SENT => 'Trimisă',
            self::STATUS_ACCEPTED => 'Acceptată',
            self::STATUS_REJECTED => 'Respinsă',
            self::STATUS_EXPIRED => 'Expirată',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OfferItem::class)->orderBy('position');
    }

    public function recalculateTotals(): void
    {
        $items = $this->items()->get(['line_subtotal', 'line_total']);

        $subtotal = $items->sum(fn (OfferItem $item): float => (float) $item->line_subtotal);
        $total = $items->sum(fn (OfferItem $item): float => (float) $item->line_total);
        $discountTotal = max(0, $subtotal - $total);

        $this->forceFill([
            'subtotal' => $this->formatAmount($subtotal),
            'discount_total' => $this->formatAmount($discountTotal),
            'total' => $this->formatAmount($total),
        ])->saveQuietly();
    }

    private static function generateNumber(): string
    {
        $series   = strtoupper(trim((string) AppSetting::get(AppSetting::KEY_OFFER_SERIES, 'OFF')));
        $startNum = max(1, (int) AppSetting::get(AppSetting::KEY_OFFER_START_NUMBER, '1'));
        $prefix   = $series . '-';

        $maxExisting = self::query()
            ->where('number', 'like', $prefix . '%')
            ->get(['number'])
            ->map(function ($r) use ($prefix): int {
                $part = substr($r->number, strlen($prefix));
                return is_numeric($part) ? (int) $part : 0;
            })
            ->max() ?? 0;

        $nextNum = max($startNum, $maxExisting + 1);

        do {
            $number  = $prefix . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
            $nextNum++;
        } while (self::query()->where('number', $number)->exists());

        return $number;
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 4, '.', '');
    }
}
