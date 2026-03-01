<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    public const STATUS_DRAFT            = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED         = 'approved';
    public const STATUS_REJECTED         = 'rejected';
    public const STATUS_SENT             = 'sent';
    public const STATUS_CANCELLED        = 'cancelled';

    protected $fillable = [
        'number',
        'supplier_id',
        'buyer_id',
        'status',
        'total_value',
        'currency',
        'notes_internal',
        'notes_supplier',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'supplier_id'  => 'integer',
            'buyer_id'     => 'integer',
            'total_value'  => 'decimal:2',
            'approved_by'  => 'integer',
            'approved_at'  => 'datetime',
            'rejected_by'  => 'integer',
            'rejected_at'  => 'datetime',
            'sent_at'      => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            if (blank($record->number)) {
                $record->number = static::generateNumber();
            }

            $record->status   = $record->status ?: self::STATUS_DRAFT;
            $record->currency = $record->currency ?: 'RON';

            if (! $record->buyer_id && auth()->id()) {
                $record->buyer_id = (int) auth()->id();
            }
        });

    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_DRAFT            => 'Draft',
            self::STATUS_PENDING_APPROVAL => 'În așteptare aprobare',
            self::STATUS_APPROVED         => 'Aprobat',
            self::STATUS_REJECTED         => 'Respins',
            self::STATUS_SENT             => 'Trimis',
            self::STATUS_CANCELLED        => 'Anulat',
        ];
    }

    public static function statusColors(): array
    {
        return [
            self::STATUS_DRAFT            => 'gray',
            self::STATUS_PENDING_APPROVAL => 'warning',
            self::STATUS_APPROVED         => 'success',
            self::STATUS_REJECTED         => 'danger',
            self::STATUS_SENT             => 'info',
            self::STATUS_CANCELLED        => 'gray',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function recalculateTotals(): void
    {
        $total = $this->items()->sum('line_total');

        $this->forceFill(['total_value' => number_format((float) $total, 2, '.', '')])->saveQuietly();
    }

    public function needsApproval(): bool
    {
        $this->loadMissing(['supplier', 'items']);

        // Regula 1: valoare totală PO > plafon furnizor
        if ($this->supplier->po_approval_threshold &&
            (float) $this->total_value > (float) $this->supplier->po_approval_threshold) {
            return true;
        }

        // Regula 2: oricare item depășește cantitatea maximă
        foreach ($this->items as $item) {
            if (! $item->woo_product_id) {
                continue;
            }

            $ps = ProductSupplier::where('woo_product_id', $item->woo_product_id)
                ->where('supplier_id', $this->supplier_id)
                ->first();

            if ($ps && $ps->po_max_qty && (float) $item->quantity > (float) $ps->po_max_qty) {
                return true;
            }
        }

        return false;
    }

    private static function generateNumber(): string
    {
        do {
            $number = 'PO-'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (self::query()->where('number', $number)->exists());

        return $number;
    }
}
