<?php

namespace App\Filament\App\Resources\PurchaseOrderResource\Pages;

use App\Filament\App\Resources\PurchaseOrderResource;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    /**
     * Override fillForm so that when coming from BuyerDashboard (?supplier_id=X)
     * we pre-populate the form with all pending items for that supplier.
     */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $supplierId = (int) request()->query('supplier_id', 0);

        if ($supplierId) {
            $items = $this->buildItemsForSupplier($supplierId);

            $this->form->fill([
                'supplier_id' => $supplierId,
                'items'       => $items,
            ]);
        } else {
            $this->form->fill();
        }

        $this->callHook('afterFill');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $data['buyer_id'] = $user->id;
        }

        return $data;
    }

    /**
     * After the PO is created, link request items → order items using FIFO allocation
     * (urgent first, then by needed_by date asc).
     */
    protected function afterCreate(): void
    {
        $affectedRequestIds = [];

        foreach ($this->record->items as $orderItem) {
            if (blank($orderItem->sources_json)) {
                continue;
            }

            $sources = json_decode($orderItem->sources_json, true);
            if (! is_array($sources) || empty($sources)) {
                continue;
            }

            // FIFO sort: urgent first, then earliest needed_by (ISO Y-m-d → strcmp works correctly)
            usort($sources, function (array $a, array $b): int {
                $urgentDiff = (int) ($b['is_urgent'] ?? false) <=> (int) ($a['is_urgent'] ?? false);
                if ($urgentDiff !== 0) {
                    return $urgentDiff;
                }

                return strcmp($a['needed_by'] ?? '', $b['needed_by'] ?? '');
            });

            $remaining          = (float) $orderItem->quantity;
            $primaryRequestItem = null;

            foreach ($sources as $source) {
                if ($remaining <= 0) {
                    break;
                }

                $requestItem = PurchaseRequestItem::find($source['request_item_id'] ?? null);
                if (! $requestItem) {
                    continue;
                }

                $sourceQty = (float) ($source['quantity'] ?? 0);

                if ($remaining >= $sourceQty) {
                    $requestItem->update([
                        'status'                 => PurchaseRequestItem::STATUS_ORDERED,
                        'purchase_order_item_id' => $orderItem->id,
                    ]);

                    $remaining -= $sourceQty;
                    $primaryRequestItem   = $primaryRequestItem ?? $requestItem->id;
                    $affectedRequestIds[] = $requestItem->purchase_request_id;
                }
                // remaining < sourceQty: partial — item stays pending in dashboard
            }

            if ($primaryRequestItem) {
                $orderItem->updateQuietly(['purchase_request_item_id' => $primaryRequestItem]);
            }
        }

        foreach (array_unique($affectedRequestIds) as $requestId) {
            PurchaseRequest::find($requestId)?->recalculateStatus();
        }
    }

    /**
     * Fetch ALL pending items for $supplierId from ALL submitted purchase requests.
     * Group by product, cumulate quantities, build sources_json per group.
     */
    private function buildItemsForSupplier(int $supplierId): array
    {
        $requestItems = PurchaseRequestItem::query()
            ->with(['purchaseRequest.user', 'purchaseRequest.location'])
            ->where('supplier_id', $supplierId)
            ->where('status', PurchaseRequestItem::STATUS_PENDING)
            ->whereHas('purchaseRequest', fn ($q) => $q->where('status', PurchaseRequest::STATUS_SUBMITTED))
            ->orderByDesc('is_urgent')
            ->orderBy('needed_by')
            ->get();

        if ($requestItems->isEmpty()) {
            return [];
        }

        $groups = $requestItems->groupBy(
            fn (PurchaseRequestItem $item): string => $item->woo_product_id
                ? 'woo:'.(string) $item->woo_product_id
                : 'name:'.$item->product_name
        );

        return $groups->map(function ($items) use ($supplierId): array {
            /** @var \Illuminate\Support\Collection<int, PurchaseRequestItem> $items */
            $first = $items->first();

            $supplierSku = null;
            $unitPrice   = null;

            if ($first->woo_product_id) {
                $ps = ProductSupplier::where('woo_product_id', $first->woo_product_id)
                    ->where('supplier_id', $supplierId)
                    ->first();

                if ($ps) {
                    $supplierSku = $ps->supplier_sku;
                    $unitPrice   = $ps->purchase_price ? (float) $ps->purchase_price : null;
                }
            }

            $totalQty = $items->sum(fn (PurchaseRequestItem $i): float => (float) $i->quantity);

            $sources = $items->map(fn (PurchaseRequestItem $item): array => [
                'request_item_id'  => $item->id,
                'request_number'   => $item->purchaseRequest?->number,
                'request_id'       => $item->purchaseRequest?->id,
                'consultant'       => $item->purchaseRequest?->user?->name,
                'location'         => $item->purchaseRequest?->location?->name,
                'quantity'         => (float) $item->quantity,
                'is_urgent'        => (bool) $item->is_urgent,
                'needed_by'        => $item->needed_by?->format('Y-m-d'),
                'client_reference' => $item->client_reference,
            ])->values()->all();

            return [
                'woo_product_id' => $first->woo_product_id,
                'product_name'   => $first->product_name,
                'sku'            => $first->sku,
                'supplier_sku'   => $supplierSku,
                'quantity'       => $totalQty,
                'unit_price'     => $unitPrice,
                'notes'          => null,
                'sources_json'   => json_encode($sources, JSON_UNESCAPED_UNICODE),
            ];
        })->values()->all();
    }
}
