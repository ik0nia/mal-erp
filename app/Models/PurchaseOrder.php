<?php

namespace App\Models;

use App\Enums\HasStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasStatusEnum;
    public const WINMENTOR_PENDING = 'pending';
    public const WINMENTOR_SYNCED  = 'synced';
    public const WINMENTOR_FAILED  = 'failed';

    public const STATUS_DRAFT            = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED         = 'approved';
    public const STATUS_REJECTED         = 'rejected';
    public const STATUS_SENT                = 'sent';
    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';
    public const STATUS_RECEIVED           = 'received';
    public const STATUS_CANCELLED          = 'cancelled';

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
        'sent_via',
        'received_at',
        'received_by',
        'received_notes',
        'invoice_series',
        'invoice_number',
        'invoice_date',
        'invoice_due_date',
        'winmentor_sync_status',
        'winmentor_sync_error',
        'winmentor_order_nr',
        'winmentor_synced_at',
        'winmentor_receptie_nr',
        'winmentor_receptie_nrs',
        'winmentor_receptie_date',
        'winmentor_receptie_score',
        'winmentor_receptie_matched_at',
        'lead_time_days',
        'receptie_contabila_lag_days',
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
            'received_at'  => 'datetime',
            'received_by'    => 'integer',
            'invoice_date'       => 'date',
            'invoice_due_date'   => 'date',
            'winmentor_synced_at'             => 'datetime',
            'winmentor_receptie_date'         => 'date',
            'winmentor_receptie_matched_at'   => 'datetime',
            'winmentor_receptie_score'        => 'integer',
            'winmentor_receptie_nrs'          => 'array',
            'lead_time_days'                  => 'integer',
            'receptie_contabila_lag_days'     => 'integer',
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

        static::updated(function (self $record): void {
            if ($record->wasChanged('winmentor_sync_status')
                && $record->winmentor_sync_status === self::WINMENTOR_PENDING) {
                \App\Jobs\PushComenziFurnizoriToWinmentorJob::dispatch($record->id)->afterCommit();
            }

            if ($record->wasChanged('status') && $record->status === self::STATUS_SENT) {
                if (! $record->winmentor_sync_status) {
                    $record->winmentor_sync_status = self::WINMENTOR_PENDING;
                    $record->saveQuietly();
                    \App\Jobs\PushComenziFurnizoriToWinmentorJob::dispatch($record->id)->afterCommit();
                }

                $supplier = $record->supplier?->name ?? 'Furnizor necunoscut';
                \App\Jobs\SendWhPushNotificationJob::dispatch(
                    title: '📦 Comandă nouă de recepționat',
                    body:  "{$record->number} — {$supplier}",
                    url:   '/wh/',
                )->afterCommit();
            }
        });
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT            => 'Draft',
            self::STATUS_PENDING_APPROVAL => 'În așteptare aprobare',
            self::STATUS_APPROVED         => 'Aprobat',
            self::STATUS_REJECTED         => 'Respins',
            self::STATUS_SENT                => 'Trimis',
            self::STATUS_PARTIALLY_RECEIVED => 'Recepție parțială',
            self::STATUS_RECEIVED           => 'Recepționat',
            self::STATUS_CANCELLED          => 'Anulat',
        ];
    }

    public static function statusColorMap(): array
    {
        return [
            self::STATUS_DRAFT            => 'gray',
            self::STATUS_PENDING_APPROVAL => 'warning',
            self::STATUS_APPROVED         => 'success',
            self::STATUS_REJECTED         => 'danger',
            self::STATUS_SENT                => 'info',
            self::STATUS_PARTIALLY_RECEIVED => 'warning',
            self::STATUS_RECEIVED           => 'success',
            self::STATUS_CANCELLED          => 'gray',
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

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(PurchaseOrderReception::class);
    }

    public function recalculateTotals(): void
    {
        $items = $this->items()->get();

        $hasReception = $items->contains(fn ($i) => (float) $i->received_quantity > 0);

        if ($hasReception) {
            // Total = sum(line_total) doar pentru items cu recepție (received_quantity > 0)
            $total = $items
                ->filter(fn ($i) => (float) $i->received_quantity > 0)
                ->sum(fn ($i) => (float) $i->line_total);
        } else {
            $total = $items->sum(fn ($i) => (float) $i->line_total);
        }

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
        // Preîncărcăm toate ProductSupplier-urile relevante într-un singur query (evităm N+1)
        $productIds = $this->items->pluck('woo_product_id')->filter()->unique()->values();

        if ($productIds->isEmpty()) {
            return false;
        }

        $productSupplierMap = ProductSupplier::query()
            ->where('supplier_id', $this->supplier_id)
            ->whereIn('woo_product_id', $productIds)
            ->get(['woo_product_id', 'po_max_qty'])
            ->keyBy('woo_product_id');

        foreach ($this->items as $item) {
            if (! $item->woo_product_id) {
                continue;
            }

            $ps = $productSupplierMap->get($item->woo_product_id);

            if ($ps && $ps->po_max_qty && (float) $item->quantity > (float) $ps->po_max_qty) {
                return true;
            }
        }

        return false;
    }

    private static function generateNumber(): string
    {
        $lock = \Illuminate\Support\Facades\Cache::lock('po_number_generate', 10);
        $lock->block(10);

        try {
            $max = self::query()
                ->where('number', 'like', 'PO-%')
                ->get(['number'])
                ->map(fn ($r): int => (int) ltrim(substr($r->number, 3), '0'))
                ->max() ?? 0;

            return 'PO-' . max($max + 1, 100000);
        } finally {
            $lock->release();
        }
    }
}
