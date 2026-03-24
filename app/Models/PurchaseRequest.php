<?php

namespace App\Models;

use App\Concerns\HasLocationScope;
use App\Enums\HasStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseRequest extends Model
{
    use HasLocationScope, HasStatusEnum;

    public const STATUS_DRAFT            = 'draft';
    public const STATUS_SUBMITTED        = 'submitted';
    public const STATUS_PARTIALLY_ORDERED = 'partially_ordered';
    public const STATUS_FULLY_ORDERED    = 'fully_ordered';
    public const STATUS_CANCELLED        = 'cancelled';

    public const SOURCE_MANUAL    = 'manual';
    public const SOURCE_WOO_ORDER = 'woo_order';

    protected $fillable = [
        'number',
        'user_id',
        'location_id',
        'status',
        'notes',
        'woo_order_id',
        'source_type',
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

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT             => 'Draft',
            self::STATUS_SUBMITTED         => 'Trimis',
            self::STATUS_PARTIALLY_ORDERED => 'Parțial comandat',
            self::STATUS_FULLY_ORDERED     => 'Complet comandat',
            self::STATUS_CANCELLED         => 'Anulat',
        ];
    }

    public static function statusColorMap(): array
    {
        return [
            self::STATUS_DRAFT             => 'gray',
            self::STATUS_SUBMITTED         => 'info',
            self::STATUS_PARTIALLY_ORDERED => 'warning',
            self::STATUS_FULLY_ORDERED     => 'success',
            self::STATUS_CANCELLED         => 'danger',
        ];
    }

    public static function getOrCreateDraft(User $user): self
    {
        return self::firstOrCreate(
            [
                'user_id' => $user->id,
                'status'  => self::STATUS_DRAFT,
            ],
            [
                'location_id' => $user->location_id,
            ]
        );
    }

    public function wooOrder(): BelongsTo
    {
        return $this->belongsTo(WooOrder::class);
    }

    /**
     * Creează automat un PNR submitted din itemele on_demand ale unei comenzi WooCommerce.
     * Idempotent — dacă există deja un PNR pentru această comandă, îl returnează.
     */
    public static function createFromWooOrder(WooOrder $order): ?self
    {
        // Evită dubluri
        $existing = self::where('woo_order_id', $order->id)->first();
        if ($existing) {
            return $existing;
        }

        // Verifică dacă există produse on_demand în comandă
        $onDemandItems = $order->items()
            ->with('product')
            ->get()
            ->filter(fn ($item) =>
                $item->product &&
                $item->product->procurement_type === WooProduct::PROCUREMENT_ON_DEMAND
            );

        if ($onDemandItems->isEmpty()) {
            return null;
        }

        $request = self::create([
            'status'       => self::STATUS_SUBMITTED,
            'notes'        => "Auto-generat din comanda WooCommerce #{$order->number}",
            'woo_order_id' => $order->id,
            'source_type'  => self::SOURCE_WOO_ORDER,
            'location_id'  => $order->location_id,
        ]);

        foreach ($onDemandItems as $item) {
            $request->items()->create([
                'woo_product_id'  => $item->woo_product_id,
                'quantity'        => $item->quantity,
                'is_urgent'       => true,
                'client_reference'=> "Comandă WooCommerce #{$order->number}",
                'notes'           => $order->billing['first_name'] ?? '' . ' ' . ($order->billing['last_name'] ?? ''),
            ]);
        }

        return $request;
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
        $items = $this->items()->get(['status', 'quantity', 'ordered_quantity']);

        if ($items->isEmpty()) {
            return;
        }

        // Excludem itemii anulați din calcul
        $active = $items->where('status', '!=', PurchaseRequestItem::STATUS_CANCELLED);

        if ($active->isEmpty()) {
            // Toți itemii sunt anulați → necesarul devine anulat
            $this->forceFill(['status' => self::STATUS_CANCELLED])->saveQuietly();
            return;
        }

        $total = $active->count();

        // Un item este "complet comandat" dacă ordered_quantity >= quantity
        $fullyOrdered = $active->filter(
            fn ($i) => (float) $i->ordered_quantity >= (float) $i->quantity
        )->count();

        // Un item este "parțial comandat" dacă ordered_quantity > 0
        $hasAnyOrdered = $active->filter(
            fn ($i) => (float) $i->ordered_quantity > 0
        )->count() > 0;

        if ($fullyOrdered >= $total) {
            $this->forceFill(['status' => self::STATUS_FULLY_ORDERED])->saveQuietly();
        } elseif ($hasAnyOrdered) {
            $this->forceFill(['status' => self::STATUS_PARTIALLY_ORDERED])->saveQuietly();
        } else {
            // Niciun item comandat — revenim la submitted
            $this->forceFill(['status' => self::STATUS_SUBMITTED])->saveQuietly();
        }
    }

    private static function generateNumber(): string
    {
        $series    = strtoupper(trim((string) AppSetting::get(AppSetting::KEY_PNR_SERIES, 'PNR')));
        $startNum  = max(1, (int) AppSetting::get(AppSetting::KEY_PNR_START_NUMBER, '1'));
        $prefix    = $series . '-';

        // Cel mai mare număr existent pentru această serie
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
}
