<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'woo_product_id',
        'product_name',
        'sku',
        'supplier_sku',
        'quantity',
        'unit_price',
        'line_total',
        'notes',
        'purchase_request_item_id',
        'sources_json',
        'received_quantity',
        'received_note',
        'invoice_position',
    ];

    protected function casts(): array
    {
        return [
            'purchase_order_id'        => 'integer',
            'woo_product_id'           => 'integer',
            'quantity'                 => 'decimal:3',
            'unit_price'               => 'decimal:4',
            'line_total'               => 'decimal:4',
            'purchase_request_item_id' => 'integer',
            'received_quantity'        => 'decimal:3',
            'sources_json'             => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $qty   = max(0, (float) ($item->quantity ?? 0));
            $price = max(0, (float) ($item->unit_price ?? 0));

            $item->line_total = number_format($qty * $price, 4, '.', '');

            static::assertBelongsToOrderSupplier($item);
        });

        static::saved(function (self $item): void {
            $item->purchaseOrder?->recalculateTotals();
        });

        static::deleted(function (self $item): void {
            $item->purchaseOrder?->recalculateTotals();
        });
    }

    /**
     * Gardă independentă de cale: o linie de PO nu poate conține un produs care
     * aparține ALTUI furnizor decât cel al comenzii. Prinde inclusiv liniile libere
     * (woo_product_id=NULL) care ocolesc selectorul din UI — cum s-au strecurat
     * bureții abrazivi (furnizor STUDENTII MUNCITORI) într-un PO Toya.
     *
     * Reguli de siguranță (permite, NU blochează):
     *  - produs nerezolvabil (SKU necunoscut = linie liberă Toya legitimă);
     *  - EAN ambiguu (mapează pe mai multe produse — nu ghicim);
     *  - produs fără nicio asociere de furnizor (nimic de contrazis);
     *  - produs asociat inclusiv furnizorului comenzii (caz normal).
     */
    protected static function assertBelongsToOrderSupplier(self $item): void
    {
        // Doar la creare sau când se schimbă produsul/comanda — nu la fiecare recepție.
        if ($item->exists && ! $item->isDirty(['woo_product_id', 'sku', 'purchase_order_id'])) {
            return;
        }

        $po = $item->purchaseOrder ?? PurchaseOrder::find($item->purchase_order_id);
        if (! $po || ! $po->supplier_id) {
            return;
        }

        // 1) Rezolvă produsul: woo_product_id direct, altfel după SKU/EAN (doar dacă e unic).
        $productId = $item->woo_product_id;
        if (! $productId && filled($item->sku)) {
            $ids = WooProduct::where('sku', $item->sku)->pluck('id');
            if ($ids->count() === 1) {
                $productId = (int) $ids->first();
            }
            // 0 = linie liberă (permis) · >1 = EAN ambiguu (permis, nu presupunem)
        }
        if (! $productId) {
            return;
        }

        // 2) Furnizorii produsului (din product_suppliers).
        $supplierIds = ProductSupplier::where('woo_product_id', $productId)
            ->pluck('supplier_id')->map(fn ($v) => (int) $v)->unique();
        if ($supplierIds->isEmpty() || $supplierIds->contains((int) $po->supplier_id)) {
            return; // fără asociere de contrazis SAU aparține chiar acestui furnizor
        }

        // 3) Produsul aparține altor furnizori → BLOCAT.
        $product    = WooProduct::find($productId);
        $ownerNames = Supplier::whereIn('id', $supplierIds)->pluck('name')->implode(', ');
        $orderSup   = $po->supplier?->name ?? ('#' . $po->supplier_id);

        throw ValidationException::withMessages([
            'items' => "Produsul „" . ($product?->name ?? $item->product_name ?? $item->sku)
                . "” (cod {$item->sku}) aparține furnizorului {$ownerNames}, nu furnizorului comenzii ({$orderSup}). "
                . "Nu poate fi adăugat pe acest PO.",
        ]);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'woo_product_id');
    }

    public function purchaseRequestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class);
    }
}
