<?php

namespace App\Filament\App\Resources\PurchaseOrderResource\Pages;

use App\Filament\App\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['items']) && is_array($data['items'])) {
            $data['items'] = array_values(array_filter(
                $data['items'],
                fn ($item) => isset($item['quantity']) && (float) $item['quantity'] > 0
            ));
        }

        return $data;
    }

    protected function afterSave(): void
    {
        // Auto-match items noi (fără sources_json) cu request items pending
        $affectedRequestIds = [];

        DB::transaction(function () use (&$affectedRequestIds): void {
            foreach ($this->record->items as $orderItem) {
                if (filled($orderItem->sources_json)) {
                    continue;
                }

                $this->autoMatchRequestItems($orderItem, $affectedRequestIds);
            }
        });

        foreach (array_unique($affectedRequestIds) as $requestId) {
            PurchaseRequest::find($requestId)?->recalculateStatus();
        }
    }

    private function autoMatchRequestItems(PurchaseOrderItem $orderItem, array &$affectedRequestIds): void
    {
        $supplierId = $this->record->supplier_id;

        $query = PurchaseRequestItem::query()
            ->where('supplier_id', $supplierId)
            ->where('status', PurchaseRequestItem::STATUS_PENDING)
            ->whereHas('purchaseRequest', fn ($q) => $q->whereIn('status', [
                PurchaseRequest::STATUS_SUBMITTED,
                PurchaseRequest::STATUS_PARTIALLY_ORDERED,
            ]))
            ->whereRaw('quantity > COALESCE(ordered_quantity, 0)')
            ->orderByDesc('is_urgent')
            ->orderBy('needed_by');

        if ($orderItem->woo_product_id) {
            $query->where('woo_product_id', $orderItem->woo_product_id);
        } elseif ($orderItem->sku) {
            $query->where('sku', $orderItem->sku);
        } else {
            return;
        }

        $matchingItems = $query->lockForUpdate()->get();

        if ($matchingItems->isEmpty()) {
            return;
        }

        $remaining      = (float) $orderItem->quantity;
        $primaryRequest = null;
        $sources        = [];

        foreach ($matchingItems as $requestItem) {
            if ($remaining <= 0) {
                break;
            }

            $availableInItem = max(0, (float) $requestItem->quantity - (float) $requestItem->ordered_quantity);
            $canAllocate     = min($remaining, $availableInItem);

            if ($canAllocate <= 0) {
                continue;
            }

            $remaining -= $canAllocate;

            PurchaseRequestItem::where('id', $requestItem->id)
                ->increment('ordered_quantity', $canAllocate);

            $requestItem->refresh();

            if ($requestItem->isFullyOrdered()) {
                $requestItem->update([
                    'status'                 => PurchaseRequestItem::STATUS_ORDERED,
                    'purchase_order_item_id' => $orderItem->id,
                ]);
            } else {
                $requestItem->update([
                    'purchase_order_item_id' => $orderItem->id,
                ]);
            }

            $primaryRequest       = $primaryRequest ?? $requestItem->id;
            $affectedRequestIds[] = $requestItem->purchase_request_id;

            $sources[] = [
                'request_item_id' => $requestItem->id,
                'request_number'  => $requestItem->purchaseRequest?->number,
                'request_id'      => $requestItem->purchase_request_id,
                'quantity'        => $availableInItem,
                'allocated_qty'   => $canAllocate,
            ];
        }

        if (! empty($sources)) {
            $orderItem->updateQuietly([
                'sources_json'             => json_encode($sources, JSON_UNESCAPED_UNICODE),
                'purchase_request_item_id' => $primaryRequest,
            ]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
        ];
    }
}
