<?php

namespace App\Models;

use App\Concerns\HasLocationScope;
use App\Enums\HasStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Offer extends Model
{
    use \App\Models\Concerns\Auditable;
    use HasLocationScope, HasStatusEnum;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    public const APPROVAL_NOT_REQUIRED = 'not_required';
    public const APPROVAL_PENDING = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';

    protected $fillable = [
        'location_id',
        'user_id',
        'number',
        'status',
        'approval_status',
        'approved_by',
        'approval_requested_at',
        'approved_at',
        'approval_note',
        'approval_notified_at',
        'approved_discount_level',
        'client_name',
        'client_company',
        'client_email',
        'client_phone',
        'notes',
        'discount_condition',
        'transport_mode',
        'transport_free_over',
        'payment_terms',
        'delivery_terms',
        'extra_terms',
        'currency',
        'subtotal',
        'discount_total',
        'subtotal_without_vat',
        'vat_total',
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
            'approved_by' => 'integer',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'transport_free_over' => 'decimal:2',
            'subtotal_without_vat' => 'decimal:4',
            'vat_total' => 'decimal:4',
            'total' => 'decimal:4',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'approval_requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'approval_notified_at' => 'datetime',
            'approved_discount_level' => 'decimal:2',
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

    public const DISCOUNT_PER_LINE = 'per_line';
    public const DISCOUNT_FULL_QTY = 'full_quantity';
    public const DISCOUNT_NONE = 'none';

    public const TRANSPORT_NOT_INCLUDED = 'not_included';
    public const TRANSPORT_INCLUDED = 'included';
    public const TRANSPORT_FREE_OVER = 'free_over';
    public const TRANSPORT_NEGOTIABLE = 'negotiable';

    /** Cota TVA implicită (RO standard 21%), configurabilă din AppSetting. */
    public static function defaultVatRate(): float
    {
        return (float) (AppSetting::get(AppSetting::KEY_OFFER_VAT_RATE, '21') ?: 21);
    }

    public static function discountConditionOptions(): array
    {
        return [
            self::DISCOUNT_PER_LINE => 'Se aplică pentru cantitatea de pe fiecare linie',
            self::DISCOUNT_FULL_QTY => 'Doar la achiziția întregii cantități ofertate',
            self::DISCOUNT_NONE     => 'Fără condiție (prețuri nete)',
        ];
    }

    public static function transportModeOptions(): array
    {
        return [
            self::TRANSPORT_NOT_INCLUDED => 'Transport neinclus',
            self::TRANSPORT_INCLUDED     => 'Transport inclus în preț',
            self::TRANSPORT_FREE_OVER    => 'Transport gratuit peste o valoare',
            self::TRANSPORT_NEGOTIABLE   => 'Transport de stabilit de comun acord',
        ];
    }

    /**
     * Liniile de condiții afișate pe documentul ofertei (PDF + print).
     *
     * @return string[]
     */
    public function conditionLines(): array
    {
        $cur = $this->currency ?: 'RON';
        $lines = [];

        $lines[] = "Prețurile sunt exprimate în {$cur}, fără TVA; TVA este evidențiat și adăugat la total.";

        if ($this->valid_until) {
            $lines[] = 'Ofertă valabilă până la ' . $this->valid_until->format('d.m.Y') . ', în limita stocului disponibil.';
        }

        if ((float) $this->discount_total > 0) {
            $lines[] = match ($this->discount_condition) {
                self::DISCOUNT_FULL_QTY => 'Discounturile se aplică doar la achiziția întregii cantități ofertate.',
                self::DISCOUNT_NONE     => 'Prețurile sunt nete, fără alte reduceri.',
                default                 => 'Discounturile acordate sunt valabile pentru cantitățile din ofertă.',
            };
        }

        $lines[] = match ($this->transport_mode) {
            self::TRANSPORT_INCLUDED  => 'Transportul este inclus în preț.',
            self::TRANSPORT_FREE_OVER => 'Transport gratuit pentru comenzi peste ' . number_format((float) $this->transport_free_over, 2, ',', '.') . ' ' . $cur . '.',
            self::TRANSPORT_NEGOTIABLE => 'Costul transportului se stabilește de comun acord.',
            default                   => 'Transportul nu este inclus în preț.',
        };

        if (filled($this->payment_terms)) {
            $lines[] = 'Termen de plată: ' . trim($this->payment_terms) . '.';
        }

        if (filled($this->delivery_terms)) {
            $lines[] = 'Livrare: ' . trim($this->delivery_terms) . '.';
        }

        foreach (preg_split('/\r\n|\r|\n/', (string) $this->extra_terms) as $extra) {
            $extra = trim($extra);
            if ($extra !== '') {
                $lines[] = $extra;
            }
        }

        return $lines;
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

    public static function approvalLabels(): array
    {
        return [
            self::APPROVAL_NOT_REQUIRED => 'Fără aprobare',
            self::APPROVAL_PENDING => 'Așteaptă aprobare',
            self::APPROVAL_APPROVED => 'Discount aprobat',
            self::APPROVAL_REJECTED => 'Discount respins',
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OfferItem::class)->orderBy('position');
    }

    public function recalculateTotals(): void
    {
        $items = $this->items()->get();

        $subtotal = $items->sum(fn (OfferItem $item): float => (float) $item->line_subtotal);
        $total = $items->sum(fn (OfferItem $item): float => (float) $item->line_total);
        $netTotal = $items->sum(fn (OfferItem $item): float => $item->line_net);
        $discountTotal = max(0, $subtotal - $total);

        $this->forceFill([
            'subtotal' => $this->formatAmount($subtotal),
            'discount_total' => $this->formatAmount($discountTotal),
            'subtotal_without_vat' => $this->formatAmount($netTotal),
            'vat_total' => $this->formatAmount(max(0, $total - $netTotal)),
            'total' => $this->formatAmount($total),
        ])->saveQuietly();

        $this->refreshApprovalState();
    }

    /**
     * Recalculează plafoanele de discount per linie pentru operatorul ofertei
     * și actualizează starea de aprobare a ofertei.
     */
    public function refreshApprovalState(): void
    {
        $operator = $this->user;

        if (! $operator) {
            return;
        }

        $resolver = app(\App\Services\Offers\DiscountPolicyService::class);

        $anyNeedsApproval = false;
        $maxDiscount = 0.0;

        foreach ($this->items()->get() as $item) {
            $maxDiscount = max($maxDiscount, (float) $item->discount_percent);

            if (! $item->woo_product_id) {
                continue;
            }

            $caps = $resolver->resolve($operator, (int) $item->woo_product_id);
            $max = $caps['max'];
            $discount = (float) $item->discount_percent;

            $needs = $max !== null && $discount > $max + 0.001;

            $item->forceFill([
                'max_discount_allowed' => $max,
                'needs_approval' => $needs,
            ])->saveQuietly();

            if ($needs) {
                $anyNeedsApproval = true;
            }
        }

        // Nu mai e nevoie de aprobare → resetăm complet.
        if (! $anyNeedsApproval) {
            $this->forceFill([
                'approval_status' => self::APPROVAL_NOT_REQUIRED,
                'approval_notified_at' => null,
                'approved_discount_level' => null,
            ])->saveQuietly();

            return;
        }

        // Aprobată deja: dacă vânzătorul a CRESCUT discountul peste nivelul aprobat, invalidăm aprobarea.
        if ($this->approval_status === self::APPROVAL_APPROVED) {
            if ($this->approved_discount_level === null || $maxDiscount > (float) $this->approved_discount_level + 0.001) {
                $this->forceFill([
                    'approval_status' => self::APPROVAL_PENDING,
                    'approval_notified_at' => null,
                    'approved_by' => null,
                    'approved_at' => null,
                    'approved_discount_level' => null,
                    'approval_requested_at' => now(),
                ])->saveQuietly();
            }

            return;
        }

        // Respinsă: rămâne respinsă până când discountul scade sub plafon (caz tratat mai sus).
        if ($this->approval_status === self::APPROVAL_REJECTED) {
            return;
        }

        $this->forceFill([
            'approval_status' => self::APPROVAL_PENDING,
            'approval_requested_at' => $this->approval_requested_at ?: now(),
        ])->saveQuietly();
    }

    public function needsDiscountApproval(): bool
    {
        return $this->approval_status === self::APPROVAL_PENDING;
    }

    /**
     * Trimite notificare managerilor dacă oferta tocmai a intrat la aprobare
     * și nu i-am notificat deja (anti-dublură). De apelat după recalculateTotals.
     */
    public function notifyApproversIfNeeded(): void
    {
        if ($this->approval_status !== self::APPROVAL_PENDING || $this->approval_notified_at) {
            return;
        }

        $approvers = User::query()
            ->where(function ($q): void {
                $q->whereIn('role', [
                    User::ROLE_MANAGER,
                    User::ROLE_DIRECTOR_VANZARI,
                ])->orWhere('is_super_admin', true);
            })
            ->when($this->user_id, fn ($q) => $q->where('id', '!=', $this->user_id))
            ->get();

        foreach ($approvers as $approver) {
            $approver->notify(new \App\Notifications\OfferNeedsApprovalNotification($this));
        }

        $this->forceFill(['approval_notified_at' => now()])->saveQuietly();
    }

    public function isBlockedForSending(): bool
    {
        return in_array($this->approval_status, [self::APPROVAL_PENDING, self::APPROVAL_REJECTED], true);
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
